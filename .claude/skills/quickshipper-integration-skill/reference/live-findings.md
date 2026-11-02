# How Quickshipper behaves (observed)

What the sandbox actually does, by topic. Observed with a merchant sandbox
account (`delivery-test.quickshipper.ge`) from 2026-09-29 on; each fact
carries the date it was last seen. Where this disagrees with the docs, this
wins. Production may differ — production facts are marked as such.

## Authentication

- The token request must be **form-encoded**; the docs' JSON body is rejected
  (`invalid_request: The mandatory 'grant_type' parameter is missing`). The
  server is OpenIddict. [live 2026-09-29]
- Login is per environment: a production account gets `invalid_grant: User
  not found` on the sandbox. [live 2026-09-29]
- Tokens last ~365 days (`expires_in: 31535999`), no `refresh_token`.
  Missing Bearer → HTTP 417, bad Bearer → HTTP 401. [live 2026-09-29]
- The account **ApiKey does not authenticate the Delivery API** (as header,
  query or Bearer); it is only for the Delivery Button widget and the
  `apiKey` field of `/v1/Order/confirm`. Treat it as a secret. [live 2026-09-30]

## Prices — `GET /v1/Order/fees`

- **Coordinates are what count.** Without them Quickshipper silently uses
  0,0 and still quotes (a meaningless price); text addresses are not
  geocoded. Without street/city text some couriers (Glovo) drop out.
  [live 2026-09-29]
- **Intra-city only in the sandbox**: Tbilisi→Tbilisi and Batumi→Batumi
  quote; Tbilisi→Batumi/Kutaisi/Rustavi give `fees: []` with
  `success: true` — a normal "nothing offered". [live 2026-09-30]
- **`amount` already includes `serviceFee`.** While the account's fee was
  waived every courier was exactly the fee cheaper, and booking took exactly
  `amount` from the balance. So the merchant pays `amount`; `serviceFee`
  (0.60 ₾ normally) is Quickshipper's part of it. [live 2026-11-01]
- **Price ids are opaque** and differ per courier: OnWay `"85"`/`"86"`/`"87"`
  (weight brackets), Wolt `"asap"`, Glovo `"ASAP"`; scheduled prices Wolt
  `"custom"`, Glovo `"CustomDate"`. `deliverySpeedId` is **null** for Wolt
  and Glovo although the spec types it as an integer; the price `id` is the
  only key always present. [live 2026-10-01]
- **Weight is kg.** OnWay brackets are `(min, max]`: exactly 1 kg gets no
  OnWay although labelled "0 - 2 kg"; (1, 2] / (2, 5] / (5, 10]; nothing
  above 10 kg. Without `CartWeight` OnWay quotes its lowest bracket. Wolt and
  Glovo price the same at any weight (even 25 kg). [live 2026-11-02]
- **`CarDelivery=true` only filters out couriers without a car** (OnWay) —
  no surcharge, no other change. Send it as the text `true`.
  `minWeightForCarDelivery`: Wolt 11000 (unit unclear), others 0.
  [live 2026-10-03]
- **`CartAmount` changed nothing** (150 to 5000 ₾) — Wolt is quoted even
  above its 1,000 ₾ parcel-value cap. [live 2026-11-02]
- **No courier filter** in the request; couriers are switched on/off for the
  whole account (`isActive`). [spec][live 2026-10-01]
- **Instant vs scheduled**: instant prices are `asap`/`ASAP` ("45-60 min
  delivery⚡"). Adding `ScheduleDelivery.ProviderId` + `.ScheduledDate`
  returns only that courier with one scheduled price at the instant amount;
  `deliveryTime` is UTC (Wolt without a zone suffix). OnWay ignores a time.
  `hasScheduledDelivery`: Wolt true, OnWay false; Glovo's flag disagreed
  with its behaviour once. The sandbox does not check courier hours (quotes
  at night and for past times). [live 2026-11-02]
- **Slow first answer**: after ~10+ idle minutes the first `/fees` call can
  take 9–10+ s (later ones under ~1.3 s; empty answers ~45 ms). Seen
  repeatedly in the sandbox; production unknown. [live 2026-10-03]
- Occasional **HTTP 500 with an empty body** (fees, account, order reads);
  the same call seconds later works. Treat 5xx as transient. [live 2026-11-01]
- Glovo is sometimes not quoted at all for a trip. [live 2026-11-02]

## Booking — `POST /v1/Order`

- **The balance is checked and the delivery price taken at creation**,
  before any confirm. Too little balance → HTTP 200, `success:false`, body
  400 "Not enough balance", nothing booked. [live 2026-10-01]
- **Draft or not is the merchant's dashboard setting** for API orders:
  "assign the courier company automatically" → `ReadyForPickup` at once;
  "after the order is confirmed" → `Draft`, sent on only by
  `POST /v1/Order/confirm`. The order field `autoAssign: true` does **not**
  override it. [live 2026-10-01]
- **A Draft can be neither cancelled nor deleted** ("Order status change
  failed", "Order delete is restricted") and its price stays held; confirm
  it, then cancel. No expiry seen within 1.5 h. [live 2026-10-01]
- **Confirm** takes 3–10 s, changes no money, sets `externalOrderId` (the
  courier company's id). [live 2026-10-01]
- **Confirming a Draft replaces `scheduledTime` with "now"**; in automatic
  mode a scheduled order keeps its time. [live 2026-11-01]
- **A refused create can leave a cancelled order behind** (Draft →
  Cancelled within a second, with the reason as a comment) while the answer
  says `orderId 0`. Treat `success:false` as "not booked", but expect a
  cancelled entry in the merchant's order list. [live 2026-10-01]
- **`integrationOrderId` is stored and shown in the dashboard** ("internal
  order code") **but never returned by the API**; store Quickshipper's
  `orderId` yourself. `externalOrderId` is the courier company's id.
  [live 2026-10-01]
- Phones: `phonePrefix "+995"` + a number typed with +995 is cleaned by
  Quickshipper (stored once). [live 2026-10-01]
- `carDelivery` and `parcelDimensions` are accepted but not echoed by
  `GET /v1/Order`. [live 2026-10-03]

## Cancelling — `POST /v1/Order/status {status: "Cancelled"}`

- Works before a courier is assigned: the delivery price comes back, the
  service fee is kept (unless waived). `orderPrice` then shows the fee.
  [live 2026-10-01]
- `statusLogs` show the Draft entry only after the order has moved on.
  [live 2026-10-01]

## Reading orders — `GET /v1/Order`

- Unknown id → HTTP 200, `success:false`, body 404 "Order not found" (also
  for another shop's order). [live 2026-09-29]
- `courier` fields: `courierId`, `courierName`, `courierPhoneNumber`,
  `courierImage`, `transportType`, `status`, `courierExternalId` [spec];
  no sandbox order has reached an assigned courier yet.

## Webhooks

- Register: `POST /v1/Webhook {callBackUrl, type: "OrderStatusChange",
  maxRetryCount}` → `webhookId`. `GET /v1/Webhook` lists every webhook of the
  account (`callBackUrl`, `type` as a string, `isActive`); the `PUT` model
  spells it `callbackUrl`. **There is no delete** — switch off with
  `PUT {id, isActive: false}`. No test call at registration.
  [live 2026-10-03]
- A webhook belongs to the **account**: every active webhook receives every
  order's status change. [live 2026-10-03]
- Call: `POST` to the URL as registered (query string kept),
  `Content-Type: application/json`, within ~0.4–1.3 s of the change. Body in
  **PascalCase**: `{OrderId, Status, Order {…as GET /v1/Order…}, Customer,
  Provider, OrderCourierModel}` — contains customer name and phone.
  [live 2026-10-01]
- **Not signed**: only `Content-Type`, `Traceparent`, `X-Correlation-Id`
  and proxy headers. Treat the body as a hint and re-read the order; a
  secret in the URL keeps strangers out. [live 2026-10-01]
- **No webhook for a Draft's creation.** Webhooks for a change our own call
  causes arrive **while that call is still running**; calls answered 503
  were **not retried** within 20 minutes despite `maxRetryCount: 3`.
  Take the status our call causes from its own answer. [live 2026-10-01]

## Sandbox specifics

- The sandbox balance is funded by a **real card payment**; there is no
  free test credit. Quickshipper can waive the service fee for a number of
  test orders on request. [live 2026-10-01]
- **OnWay orders fail** in the sandbox ("OnWay service failed"); Wolt works
  and goes to Wolt Drive's development environment (no real courier).
  [live 2026-10-01]
- The waybill (`GET /v1/Order/waybill`) fails on the sandbox server
  ("Unable to load shared library 'libwkhtmltox'"). [live 2026-10-01]
- `GET /v1/Order/custom-fields` for a shop without custom fields answers
  `success:false`, 404 — not an error. [live 2026-09-29]

## Terms that affect an integration

From Quickshipper's terms and conditions (https://qshpr.com/en/post/53) and
courier terms (https://qshpr.com/en/post/57); re-check, they change.

- Platform fee per order 0.60 ₾ + VAT (§3.1), kept when an order is
  cancelled.
- **The courier's price may change after the courier checks the order**
  (§2.3, §3.3): a checkout quote is not a guaranteed charge.
- Weight, size, value and item limits are each courier's own (§5.4), e.g.
  Wolt caps a parcel's value at 1,000 ₾ incl. VAT
  (https://qshpr.com/ka/post/54); cancellation fees follow the courier's
  rules.
- Cash on delivery depends on the courier (some forbid it).
- The recipient gets the tracking link by SMS when an order is placed
  (§2.4–2.5).

## Documentation errors

- Token example: JSON body (must be form-encoded).
- `POST v1/order/status` shown as `GET /`; `POST`/`PUT v1/webhook` shown as
  `GET`.
- `PUT v1/order` body calls custom fields `customFields`; the spec uses
  `generalFields`.
- Fees: `serviceFee` shown at top level; live it is per courier.
- `POST /v1/Order/confirm` is only in the spec.
