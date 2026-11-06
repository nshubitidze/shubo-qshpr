# Open questions

What is still unknown about Quickshipper. Do not guess these in code; when
one is answered (by Quickshipper or live), move the answer into
`live-findings.md` or `api.md` with its source and remove it here.
Asked by e-mail and not (fully) answered on 2026-11-06 is marked *(asked)*.

## Pricing and money

- **Production billing** *(asked)*: is the delivery price taken from a
  prepaid balance at booking (as in the sandbox), or is everything invoiced
  monthly (terms §3.4)? Does production's `amount` include the VAT on the
  service fee?
- A later price change (wrong address, weight, failed delivery) *(asked)*:
  is it reported through the API (`orderPrice`, a webhook)? How long is a
  `/fees` quote valid?
- **Cash on delivery** *(asked)*: Quickshipper says it has no COD service,
  yet the API has `cashOnDelivery` {`deliveryPrice`, `parcelPrice`,
  `paymentMethod`}, `hasCashOnDelivery` and `CardOnDelivery`. What happens
  to an order that carries `cashOnDelivery` — does a courier collect, and
  who pays the merchant?

## Orders

- Unit of `minWeightForCarDelivery`; each courier's real weight limits; what
  `carDelivery` and `parcelDimensions` change after booking (price, vehicle).
- Parcel size: which couriers price by it; where the `parcelDimensionId`
  values come from (`GET /v1/Order/weights`?); does `GET /v1/Order` return
  the dimensions (Quickshipper is checking).
- How to drop a never-confirmed Draft through the API *(asked: "contact
  support")*; do Drafts expire and is the money returned?
- If the courier company refuses at `/v1/Order/confirm`, what state is the
  order left in and is the price returned? *(asked, not answered)*
- Is `scheduledTime` the pickup or the delivery time? Can a Draft keep its
  `scheduledTime` through `/confirm`? *(asked)*
- Courier hours and how far ahead a delivery can be scheduled; what happens
  to an `asap` order outside the courier's hours.
- Can a recipient's SMS be switched off for API orders? *(asked, not
  answered)*
- Support contact and hours for cancelling after a courier is assigned.

## Webhooks

- The `apikey` header's casing on the wire (seen only through a Cloudflare
  tunnel); the full IP list. *(asked)*
- Timeout and which answers count as delivered (and so are not retried).
- Can a webhook be limited to some orders, deleted, or managed in the
  dashboard? (Several environments on one account all receive every order.)
- Payload of `CourierLocation`.

## Access

- Will production keep the public OAuth client (Quickshipper: "currently"
  the same)? Can merchants get `client_credentials` instead of storing their
  dashboard password?
- Above 120 `/fees` requests a minute: what is answered (HTTP 429?); limits
  of other endpoints; SLA. *(asked)*
- Meanings of `providerType` (0–10) and `isFake`.
- Production tracking link format and lifetime.
- Is the first `/fees` call after an idle period also slow in production?
