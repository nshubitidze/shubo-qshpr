# Open questions

What is still unknown about Quickshipper. Do not guess these in code; when
one is answered (by Quickshipper or live), move the answer into
`live-findings.md` or `api.md` with its source and remove it here.

## Pricing and money

- Is the 0.60 ₾ `serviceFee` VAT-inclusive, and does production also
  include it in `amount`?
- How long is a `/fees` quote valid, and is a later price change (after the
  courier checks the order) reported through the API?
- What does `CartAmount` change (fee sharing, discounts, a courier's value
  limit)? Does production filter couriers by it?
- Cash on delivery: which couriers, what `deliveryPrice` and `parcelPrice`
  mean exactly, payout to the merchant, and what happens when an order
  carries `cashOnDelivery` for a courier that cannot collect.

## Orders

- Unit of `minWeightForCarDelivery`; each courier's real weight limits; what
  `carDelivery` and `parcelDimensions` change after booking (price, vehicle).
- `autoAssign`: what does the order field do (it does not override the
  dashboard setting)?
- How to drop a never-confirmed Draft (cancel and delete are refused); do
  Drafts expire and is the money returned?
- If the courier company refuses at `/v1/Order/confirm`, what state is the
  order left in and is the price returned?
- Is a Draft's `scheduledTime` meant to survive `/confirm`? Is
  `scheduledTime` the pickup or the delivery time?
- Can the API return `integrationOrderId`, or search orders by it?
- Courier hours and how far ahead a delivery can be scheduled; what happens
  to an `asap` order outside the courier's hours.
- Can a recipient's SMS be switched off for API orders?

## Webhooks

- Can calls be signed, or do they come from fixed IP addresses?
- Retry schedule, timeout and expected answer (a 503 was not retried).
- Can a webhook be limited to some orders, deleted, or managed in the
  dashboard? (Several environments on one account all receive every order.)
- Payload of `CourierLocation`.

## Access

- Production OAuth client credentials, and whether merchants can get
  `client_credentials` instead of storing their dashboard password.
- IP allow-listing or an approval step in production; rate limits, SLA.
- Meanings of `providerType` (0–10) and `isFake`.
- Production tracking link format and lifetime.
- Is the first `/fees` call after an idle period also slow in production?
- Can a sandbox order be moved through the statuses (e.g. to Delivered) to
  test an integration?
