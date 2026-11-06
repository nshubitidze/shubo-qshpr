# Troubleshooting and known limits

First look: `var/log/shubo_qshpr.log` (one line per Quickshipper call, all
errors; switch on "Debug Logging" for full masked payloads), then the
order's comments and the shipment's notes, then the row in
`shubo_qshpr_delivery` for the order (states mirror what Quickshipper
holds — never edit them by hand). Caches and their tags are listed in
`architecture.md`.

## No Quickshipper option at checkout

Check in this order (each logs a `rates:` line or a geocoder line):

1. Carrier enabled, country allowed (GE by default), base currency **GEL**.
2. Pickup point complete: Pickup Latitude/Longitude, Shipping Origin street
   and city, Store Information name and phone.
3. Login: "Login Email" / "Password" match Sandbox Mode (a sandbox account
   does not work against production and the other way round).
4. The address was not found **to the building in the typed city** — try a
   house number, check "Clean Up Addresses Before Lookup", or the public
   Nominatim server's limits (1 request/s). Not-found answers are cached for
   a day: flush the cache tag `SHUBO_QSHPR_GEOCODE` after fixing data.
5. Quickshipper timed out — its first answer after a quiet period can take
   over 10 s; "API Timeout" defaults to 20 s.
6. Quickshipper quoted nothing for that trip (e.g. a city it does not serve,
   or `CarDelivery` asked and no courier with a car).

Prices are cached 10 minutes per identical trip (`SHUBO_QSHPR_RATES`).

## Delivery time

- **No "Delivery time" list:** the setting is off, the courier's price
  answer does not say `hasScheduledDelivery` (only flagged couriers get it;
  re-quote after Quickshipper changes it), or no slot is left (opening hours,
  closed days, Minimum Notice, Days Ahead).
- **"As soon as possible" missing:** the shop is closed and "Book Couriers
  Only While Open" is on — by design.
- **The courier came at once instead of at the chosen time:** the
  Quickshipper dashboard creates Drafts ("assign after confirmation"); the
  confirm replaces the time (the order history says so). Switch the
  dashboard to automatic assignment.
- **Labels in English day names** ("Mon, 9 Nov") in every language: a known
  limit.

## Booking errors (Submit Shipment)

The admin message says what happened and whether the shipment was saved.

- "Not enough balance" — the Quickshipper account is prepaid; top it up.
  "Show Balance in Admin Header" helps to see it coming.
- Courier no longer offered — the fresh price no longer lists it (hours,
  weight, car); ship another way or with another courier.
- "did not answer the booking" — the order may exist at Quickshipper. Check
  the dashboard before submitting again; a retry reuses a booking the module
  recorded.
- "Another shipment of this order is booking" — wait and reload.
- "Quickshipper has no cash-on-delivery service" — the order is paid in cash
  on delivery (placed before cash was hidden for Quickshipper couriers, or
  its payment was changed). Ship it another way, or take the payment first.
- Store Information phone missing — Quickshipper needs the pickup phone.

## Status not updating

- Webhook not connected or the store has no public URL: statuses arrive
  every 15 minutes from the polling cron (needs Magento cron running).
- Domain changed: press "Connect status updates" again at the new address.
  The old webhook stays registered at Quickshipper (it cannot be deleted)
  and keeps being called.
- A delivery without news for 7 days is reported once and no longer polled.
- Webhooks for our own request arrive while the order is locked and get 503;
  that is expected — the status is taken from our request's answer.
- `webhook: refused a call with a missing or wrong key` — neither the
  `apikey` header nor the URL had the installation's key (e.g. the key was
  regenerated, or another environment's webhook on the same account).
  Press "Connect status updates" again. Accepted calls log `key from
  header` or `key from url`; `url` only means the webhook was connected
  before the header existed (press Connect again) or Quickshipper did not
  send the header.

## Known limits

- **Several environments or stores on one Quickshipper account**: a webhook
  belongs to the account, so every connected environment receives every
  other environment's orders. Each ignores orders it does not know (answers
  200 without calling Quickshipper). Use one Quickshipper account per
  environment where possible. After copying a database to another
  environment (e.g. staging from production), its delivery rows point at the
  original's Quickshipper orders: the copy will follow them, or on another
  account may read unrelated orders — clear or retire those rows.
- **A Draft can be neither cancelled nor deleted** at Quickshipper, and its
  price is held. The module confirms Drafts at once; an unconfirmable Draft
  is reported by the clean-up cron for the merchant to settle.
- **Cancelling returns the delivery price but Quickshipper may keep its
  service fee.**
- **Shipments created by REST or an ERP are not booked** — only the admin's
  Submit Shipment and the module's own automatic booking.
- **Invoice page with "Create Shipment"** books after an online capture: a
  booking failure there leaves the money captured. Prefer Ship for
  Quickshipper orders.
- **Multi-address checkout** orders are recorded and booked per order.
- **Public Nominatim** may return slightly different points for the same
  query on different days; answers are cached 30 days.
- **Quickshipper's order list shows the Magento order number, but its API
  does not return it**; the module links by Quickshipper's order id.
- **Courier limits are the courier company's**: e.g. a courier's terms may
  cap the parcel value even when the sandbox still quotes it.
