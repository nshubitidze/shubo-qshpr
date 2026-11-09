# Changelog

All notable changes to this module are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the module uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.1.0] - 2026-11-06

### Changed

- Cash on delivery is never offered with Quickshipper couriers:
  Quickshipper has no cash-on-delivery service. Checkout hides cash payment
  methods for Quickshipper delivery, Submit Shipment refuses an order paid
  in cash on delivery, and `cashOnDelivery` is never sent. This replaces
  "cash on delivery only with couriers that collect cash".
- Production uses Quickshipper's standard API client while the API Client
  ID / Secret fields are empty, so a merchant enters only the Quickshipper
  login; the fields stay as an optional override.
- Status webhook: "Connect status updates" also registers the webhook key as
  the webhook's `apiKey`, with 5 retries. The webhook accepts the key from
  the `apikey` header or, as before, from the URL, so webhooks connected
  earlier keep working.

## [1.0.0] - 2026-11-03

First public release.

### Added

- Checkout: one delivery option per courier and speed, priced by
  Quickshipper for the customer's exact address, with the courier's logo.
  The module geocodes the address itself (OpenStreetMap Nominatim by
  default, free) and offers couriers only when the address is found to the
  building.
- Booking: Submit Shipment books the chosen courier with a fresh price and
  puts Quickshipper's order number and tracking link on the shipment.
  Optionally, paid orders are booked and shipped automatically.
- Delivery statuses by webhook (one button to connect) and by a polling
  cron, shown as shipment notes and in the customer's Track popup. Cancel
  and "Book Courier Again" on the shipment page; optional cancel on full
  refund.
- Who pays Quickshipper's service fee (customer or shop), car delivery by a
  product attribute you choose / weight / default, the prepaid balance in
  the admin header, Georgian translation.

[Unreleased]: https://github.com/nshubitidze/shubo-qshpr/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/nshubitidze/shubo-qshpr/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/nshubitidze/shubo-qshpr/releases/tag/v1.0.0
