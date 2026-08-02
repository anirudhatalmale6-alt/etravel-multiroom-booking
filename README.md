# eTravel Multi-Room Booking for MotoPress

Exact per-room guest requests, multi-room availability planning, MotoPress handoff,
checkout occupancy binding and booking-request records for eTravel.gr.

## Version 1.1.0 — R3.1 hardening
- Opaque, single-use, server-side room-plan **token** (4h expiry) — the detailed plan and
  child ages never travel in a URL or an encoded cookie.
- **Server-side validation** of the created booking (exact room count, capacity, per-room
  occupancy, no repeated physical unit) — independent of any JavaScript.
- Token **bound to the real booking id**, consumed and cleared after booking; a stale
  cookie cannot attach to another booking.
- Occupancy mapped to **accommodation ids** (not checkout display order) — asymmetric
  occupancy stays attached to the correct accommodation.
- Child age is a required **"Select age"** choice with an explicit **"Under 1"** option.
- Correct **distinct-unit availability count** (same accommodation type with multiple
  physical units).
- Retains the `wp_readonly()` WP 6.4+ compatibility fix.

MotoPress remains the booking engine. No MotoPress core files are modified.
