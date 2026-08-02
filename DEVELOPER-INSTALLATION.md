# Developer installation and release gate

## Staging deployment

```bash
cd /var/www/vhosts/etravel.gr/httpdocs/staging/wp-content/plugins
unzip /path/to/etravel-multiroom-booking.zip
wp plugin activate etravel-multiroom-booking --path=/var/www/vhosts/etravel.gr/httpdocs/staging
wp cache flush --path=/var/www/vhosts/etravel.gr/httpdocs/staging
```

Adjust paths to the actual Plesk document root.

## Recommended settings for the eTravel launch

- Maximum rooms: 6
- Maximum adults per room: 8
- Maximum children per room: 6
- Collect child ages: ON
- Require exact room count: ON
- Enforce checkout room count: OFF until checkout UAT passes
- Maximum plans: 12
- Cookie retention: 4 hours

## Do not modify MotoPress core

Do not patch files inside `motopress-hotel-booking`. Use this add-on, child-theme template overrides and documented WordPress filters only.

## Acceptance tests

- 2 adults, 1 room → normal native booking works.
- 4 adults + 2 children, 2 rooms → exact two-unit plan found where inventory permits.
- 6 adults, 3 rooms → no unit is reused above its real available count.
- 2 adults + 3 children in one room → only room types meeting both child and total capacity appear.
- No exact match → helpful alternative message, not a false booking result.
- Checkout → one occupancy section per selected accommodation and values prefilled.
- Booking admin → original per-room request stored.
- Invalid/tampered party payload → rejected by HMAC verification.
- Mobile → no overflow and all room fields keyboard accessible.
- Lighthouse → Accessibility 100 target and no console errors.

## Rollback

Deactivate and delete the add-on. Existing MotoPress bookings and inventory remain unchanged. The custom booking metadata may remain harmlessly in `wp_postmeta` and can be removed later if required.
