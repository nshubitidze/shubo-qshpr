# Shubo_Qshpr — development plan

What exists, what is being built and what is open. How the module works is
in the module skill (`.claude/skills/shubo-qshpr-carrier/`); design
decisions and their reasons in its `reference/decisions.md`.

## Built

| Area | State |
|---|---|
| Quickshipper client, token, config, log | Done |
| Geocoding: swappable providers (Nominatim), Georgian address clean-up, building + city check, cache | Done |
| Checkout rates: one method per courier and speed, logos, service fee setting, car delivery (merchant's own Yes/No attribute, weight, default), cash on delivery | Done |
| Booking from Submit Shipment: fresh price, Draft confirm, reuse of unshipped bookings, per-order lock, clean-up cron | Done, tested live in the sandbox |
| Automatic booking of paid orders | Done, tested live |
| Status: webhook + polling, shipment notes, alerts, Track popup | Done, tested live |
| Cancel, Book Courier Again, cancel on full refund | Done, tested live |
| Admin balance in the header | Done |
| Multi-address checkout orders recorded | Done |

## In progress (branches)

| Feature | Branch | State |
|---|---|---|
| Delivery time: customers pick a slot for instant couriers | `feature/scheduled-delivery` | Built and tested live; open choices: show the time on the shipment and in customer emails, Book Courier Again books as soon as possible, refuse to confirm a Draft that has a time |
| Opening hours: no "as soon as possible" courier promised or booked while the shop is closed | `feature/opening-hours` (on top of delivery time) | Built and tested locally; open choices listed in its pull request |
| Client suite uses its own API timeout | `fix/client-suite-timeout` | Ready |
| Balance suite checks the shipped default | `fix/balance-suite-default` | Ready |
| Parcel size (`parcelDimensions`) | `feature/parcel-size` | On hold until Quickshipper says what the dimensions change; to be rebuilt with settings that map the store's own attributes |
| Google geocoder (merchant's own key) | `feature/google-geocoder` | Waits for a live test with a Google Maps Platform key |

When a branch is merged, the module skill is updated in the same change.

## Open — not decided

- Email the merchant for every alert that needs them (recipient setting,
  on/off, template, which alerts).
- Invoice page with "Create Shipment": online capture happens before the
  booking, so a failed booking leaves the money captured. Proposed: book
  before `register()` in that path; reproduce first.
- Webhook answer for a wrong key: 403 (now) or 400.
- Several environments on one Quickshipper account: documented as a limit;
  a setting or button to retire old webhooks is possible.
- Hide Wolt for carts above 1,000 ₾ (Wolt's courier terms cap the parcel
  value; the sandbox still quotes it).
- The check suites write stub lines into the store's real log; give them
  their own log file.
- Production geocoder: rerun the accuracy test with Geoapify (free key) and
  compare with self-hosted Nominatim / Photon and Google.
- Ship a small fake Quickshipper under `dev/` for booking tests without
  spending sandbox money.
- Deferred: address UX for customers (map pin), customer price policy
  (markup, free-shipping threshold).

## Questions for Quickshipper

Open questions are in the vendor skill's `reference/open-questions.md`;
answers go into `reference/live-findings.md` or `reference/business.md`.
