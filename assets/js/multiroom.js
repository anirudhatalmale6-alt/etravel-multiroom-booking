(() => {
  'use strict';

  const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));

  const addDays = (value, days) => {
    const date = new Date(`${value}T12:00:00`);
    if (Number.isNaN(date.getTime())) return '';
    date.setDate(date.getDate() + days);
    return date.toISOString().slice(0, 10);
  };

  function rebuildAges(room) {
    const childrenSelect = room.querySelector('[data-etr-mr-children]');
    const ages = room.querySelector('[data-etr-mr-ages]');
    if (!childrenSelect || !ages) return;
    const roomIndex = Number(room.dataset.etrMrRoom || 0);
    const count = Number(childrenSelect.value || 0);
    const L = window.etravelMultiroom?.labels || {};
    // Preserve any ages already chosen so re-render does not wipe the selection.
    const prev = {};
    qsa('select', ages).forEach((s) => { prev[s.name] = s.value; });
    ages.innerHTML = '';
    if (!window.etravelMultiroom?.collectAges || count < 1) return;

    for (let child = 0; child < count; child += 1) {
      const label = document.createElement('label');
      label.className = 'etr-mr-age-field';
      label.textContent = `${L.childAge} ${child + 1}`;
      const select = document.createElement('select');
      select.name = `etr_party[${roomIndex}][ages][${child}]`;
      select.required = true;
      select.setAttribute('aria-label', label.textContent);
      // R3.1: required "Select age" placeholder (empty) + explicit "Under 1" (value 0).
      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = L.selectAge || 'Select age';
      placeholder.disabled = true;
      placeholder.selected = true;
      select.append(placeholder);
      const under1 = document.createElement('option');
      under1.value = '0';
      under1.textContent = L.under1 || 'Under 1';
      select.append(under1);
      for (let age = 1; age <= 17; age += 1) {
        const option = document.createElement('option');
        option.value = String(age);
        option.textContent = String(age);
        select.append(option);
      }
      if (prev[select.name] !== undefined && prev[select.name] !== '') select.value = prev[select.name];
      label.append(select);
      ages.append(label);
    }
  }

  function updateForm(form) {
    const roomCount = Number(form.querySelector('[data-etr-mr-room-count]')?.value || 1);
    qsa('[data-etr-mr-room]', form).forEach((room, index) => {
      room.hidden = index >= roomCount;
      qsa('select,input', room).forEach((field) => {
        field.disabled = room.hidden;
      });
      if (!room.hidden) rebuildAges(room);
    });

    let adults = 0;
    let children = 0;
    qsa('[data-etr-mr-room]:not([hidden])', form).forEach((room) => {
      adults += Number(room.querySelector('select[name*="[adults]"]')?.value || 0);
      children += Number(room.querySelector('select[name*="[children]"]')?.value || 0);
    });
    const total = form.querySelector('[data-etr-mr-total]');
    if (total) total.textContent = `${roomCount} room${roomCount === 1 ? '' : 's'} · ${adults} adult${adults === 1 ? '' : 's'} · ${children} child${children === 1 ? '' : 'ren'}`;
  }

  function initSearch(form) {
    const checkIn = form.querySelector('[data-etr-mr-checkin]');
    const checkOut = form.querySelector('[data-etr-mr-checkout]');
    const enforceDates = () => {
      if (!checkIn?.value || !checkOut) return;
      const minimum = addDays(checkIn.value, 1);
      checkOut.min = minimum;
      if (!checkOut.value || checkOut.value <= checkIn.value) checkOut.value = minimum;
    };

    form.addEventListener('change', (event) => {
      if (event.target.matches('[data-etr-mr-room-count], [data-etr-mr-children], select[name*="[adults]"]')) updateForm(form);
      if (event.target === checkIn) enforceDates();
    });
    form.addEventListener('submit', (event) => {
      enforceDates();
      updateForm(form);
      if (!form.checkValidity()) {
        event.preventDefault();
        form.reportValidity();
      }
    });
    enforceDates();
    updateForm(form);
  }

  function highlightSelectedPlan() {
    const assignments = window.etravelMultiroom?.combo?.assignments;
    if (!Array.isArray(assignments) || assignments.length < 1) return;
    const quantities = {};
    assignments.forEach((assignment) => {
      const id = Number(assignment.type_id);
      if (!id) return;
      quantities[id] = (quantities[id] || 0) + 1;
    });

    Object.entries(quantities).forEach(([id, quantity]) => {
      const selectors = [
        `.etr-mr-room-type-${id}`,
        `#mphb_room_type-${id}`,
        `#post-${id}`,
        `[data-room-type-id="${id}"]`
      ];
      selectors.forEach((selector) => {
        qsa(selector).forEach((card) => {
          card.classList.add('etr-mr-required-card');
          if (!card.querySelector('.etr-mr-required-badge')) {
            const badge = document.createElement('div');
            badge.className = 'etr-mr-required-badge';
            badge.textContent = `${window.etravelMultiroom.labels.requiredUnits}: ${quantity}`;
            card.prepend(badge);
          }
        });
      });
    });
  }

  function findCheckoutBlocks() {
    const selectors = [
      '.mphb-room-details',
      '.mphb-reserved-room-details',
      '.mphb-checkout-item',
      '.mphb_sc_checkout-form .mphb-room-type-title'
    ];
    let blocks = [];
    selectors.some((selector) => {
      blocks = qsa(selector).filter((node) => node.querySelector?.('select[name*="adults"], select[name*="children"]') || node.parentElement?.querySelector?.('select[name*="adults"], select[name*="children"]'));
      return blocks.length > 0;
    });
    return blocks;
  }

  function setSelectValue(select, requested) {
    if (!select) return false;
    const value = String(requested);
    if (!qsa('option', select).some((option) => option.value === value)) return false;
    // Idempotent: only fire change when the value actually changes, otherwise the
    // MotoPress recalc AJAX + our MutationObserver would loop and keep the submit
    // button perpetually disabled (waitResponse never clears).
    if (select.value === value) return true;
    select.value = value;
    select.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }

  function prefillCheckout() {
    const rooms = window.etravelMultiroom?.party?.rooms;
    if (!Array.isArray(rooms) || rooms.length < 1) return;
    const blocks = findCheckoutBlocks();
    const warning = document.querySelector('[data-etr-mr-checkout-warning]');
    if (blocks.length < 1) return;

    // R3.1: bind occupancy to the accommodation by room_type_id (with a used-tracker
    // for repeated types), NOT by checkout display order — so asymmetric occupancy
    // stays attached to the correct accommodation even if the order differs.
    const assignments = Array.isArray(window.etravelMultiroom?.combo?.assignments)
      ? window.etravelMultiroom.combo.assignments.map((a) => ({ type_id: Number(a.type_id), adults: Number(a.adults), children: Number(a.children), used: false }))
      : rooms.map((r) => ({ type_id: 0, adults: Number(r.adults), children: Number(r.children), used: false }));

    let failed = false;
    blocks.forEach((block) => {
      const scope = block.querySelector?.('select[name*="adults"], select[name*="children"]') ? block : block.parentElement;
      if (!scope) return;
      const adults = scope.querySelector('select[name*="adults"]');
      const children = scope.querySelector('select[name*="children"]');
      const typeInput = scope.querySelector('[name*="room_type_id"]') || block.querySelector?.('[name*="room_type_id"]');
      const typeId = typeInput ? Number(typeInput.value) : 0;
      let pick = typeId ? assignments.find((a) => !a.used && a.type_id === typeId) : null;
      if (!pick) pick = assignments.find((a) => !a.used);
      if (!pick) return;
      pick.used = true;
      if (!setSelectValue(adults, pick.adults)) failed = true;
      if (children && !setSelectValue(children, pick.children)) failed = true;
    });

    const mismatch = blocks.length !== rooms.length;
    if (warning && (failed || mismatch)) {
      warning.hidden = false;
      warning.textContent = `${window.etravelMultiroom.labels.checkoutCount} Requested: ${rooms.length}; selected: ${blocks.length}.`;
    }

    if (window.etravelMultiroom?.enforceCount && mismatch) {
      qsa('.mphb_sc_checkout-submit-wrapper button[type="submit"], .mphb_sc_checkout-form button[type="submit"], .mphb-checkout-submit-button').forEach((button) => {
        button.disabled = true;
        button.setAttribute('aria-disabled', 'true');
      });
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    qsa('[data-etr-mr-search]').forEach(initSearch);
    highlightSelectedPlan();
    prefillCheckout();
    const checkoutForm = document.querySelector('.mphb_sc_checkout-form');
    if (checkoutForm) {
      const observer = new MutationObserver(() => prefillCheckout());
      observer.observe(checkoutForm, { childList: true, subtree: true });
      // The occupancy is set once; stop observing so we never contend with the
      // MotoPress recalc that toggles the submit button.
      setTimeout(() => observer.disconnect(), 6000);
    }
  });
})();
