# Live findings (sandbox)

Observed against `delivery-test.quickshipper.ge` / `test-auth.quickshipper.ge`
with a merchant sandbox account. Read-only calls only — no orders were created.
Where these disagree with the docs, these win (module standard §2).

## 2026-09-29 — first investigation

### Auth
- Token request with the docs' **JSON body is rejected**
  (`invalid_request: The mandatory 'grant_type' parameter is missing.`); form
  encoding works. Server is OpenIddict (error URIs point to documentation.openiddict.com).
- Unknown user → HTTP 400 `invalid_grant: User not found` (also what a
  production-only account gets on sandbox).
- Success → `expires_in: 31535999` (≈365 days), no `refresh_token`.
- Missing Bearer → HTTP **417** `{"status":"Error","message":"Please Provide authToken"}`;
  bad Bearer → HTTP 401 `{"status":"Error","message":"Invalid Token"}`.

### Rates — `GET /v1/Order/fees` (pickup: Elguja Amashukeli St, Tbilisi)

| Case | Result |
|---|---|
| Tbilisi→Tbilisi (4.73 km), no weight | OnWay 6.60 (4 days, feeId `85`), Wolt 0.65 (old 5.50, 45–60 min, feeId `asap`), Glovo 9.01 (45–60 min, feeId `ASAP`) |
| + `CartWeight=0.5` / `1` | Wolt only |
| + `CartWeight=2` | OnWay 6.60 (`85`), Wolt |
| + `CartWeight=3`–`5` | OnWay 8.60 (`86`), Wolt |
| + `CartWeight=10` | OnWay 10.60 (`87`), Wolt |
| + `CartWeight=15`…`5000` | Wolt only |
| + `CartAmount=150` | unchanged |
| + `CarDelivery=true` | Wolt, Glovo (OnWay has no car) |
| Batumi→Batumi (1.27 km) | Wolt 0.65 (old 5.60) |
| Tbilisi→Batumi / Kutaisi / Rustavi | `fees: []`, `success: true` |
| From only (no To*) | `fees: []`, distance 9680 km (defaults to 0,0) — must validate input ourselves |
| Coordinates only (no street/city) | OnWay + Wolt; Glovo missing |
| Street/city text only (no coordinates) | Wolt 0.65, **distance 0.1 km** — no server-side geocoding; missing coords silently become 0,0 and still return a (meaningless) quote. We must always send real coordinates. |

Observations:
- **Weight unit is kg.** OnWay brackets from `/weights`: `(1,2]` 6.60 id 85,
  `(2,5]` 8.60 id 86, `(5,10]` 10.60 id 87. Lower bound looks exclusive
  (1 kg → OnWay absent) although `displayName` says "0 - 2 kg" — ask vendor.
  Without `CartWeight`, OnWay quotes the lowest bracket.
- **Glovo disappears whenever `CartWeight` is sent** (any value) and when
  street/city strings are omitted. Unexplained — ask vendor.
- Wolt ignores weight; `minWeightForCarDelivery: 11000` (unit unclear, likely grams).
- `prices[].id` is the `providerFeeId` for order creation; its format varies
  per provider (`"85"`, `"asap"`, `"ASAP"`) — treat as opaque string.
- `serviceFee: 0.6` per provider, separate from `amount`. Whether the
  merchant is billed `amount + serviceFee` is an open question.
- Latency: first call ~2 s, later ~0.3–0.9 s; empty results ~40 ms.
- Sandbox Wolt price 0.65 with `oldAmount` 5.50 looks like a promo/test price.

### Other endpoints
- `GET /v1/Account` works; `balance` is a display string (`"0 ₾"`);
  `apiKey` equals the ApiKey shown in the dashboard's Integration tab.
- `GET /v1/Provider/list` → Wolt(2, type 1), Glovo(12, type 2), OnWay(25, type 5); all `allowCashOnDelivery:false`.
- `GET /v1/Order/custom-fields` for a shop without custom fields → HTTP 200,
  `success:false`, `httpStatusCode:404`, `"model not found."` — not an error for us.
- `GET /v1/Order?OrderId=1` (someone else's / non-existent) → HTTP 200,
  `success:false`, 404 "Order not found" (no cross-shop leak).
- `POST /v1/Order/status` with an invalid status → HTTP 200, `success:false`,
  body 400 `"Bogus is not valid status"`.
- `GET /v1/Order/express` without `DropOffInfo` → HTTP 400 problem+json
  (ASP.NET validation, has `traceId`); with a wrong ApiKey → HTTP 200
  `success:false` "Invalid ApiKey"; valid → `url`
  `https://express.quickshipper.ge/createorder?data=…`. Calling it creates no order.
- Production and sandbox OpenAPI specs list identical operations; sandbox
  adds `needsCashOnDelivery`, `checkoutLabel`, `needsIdCheck` to provider settings.

### Documentation errors
- Token example uses a JSON body with a form content type (wrong — see Auth).
- `POST v1/order/status` is shown as `GET /`; `POST`/`PUT v1/webhook` are shown as `GET`.
- `PUT v1/order` doc body calls custom fields `customFields`; spec uses `generalFields`.
- Fees response in docs puts `serviceFee` at top level; live it is per provider.
- The "order/confirm service" mentioned under Delivery Button is only in the spec (`POST /v1/Order/confirm`).

## 2026-09-30 — module client (step 01), read-only

Through `Shubo\Qshpr\Model\QshprClient` with the module's check suite.

- Login (form-encoded password grant) 213–225 ms; `GET /v1/Account` 39–183 ms.
- `GET /v1/Order/fees` Freedom Square → Vake, Tbilisi (7.55 km, street and
  city strings + coordinates, no weight): OnWay 6.60, Wolt 14.32 GEL in
  855 ms. Wolt's 0.65 promo price of 2026-09-29 is gone; Glovo absent.
- Tbilisi → Batumi: `fees: []`, `success: true`, distance 394.76 km, 45 ms.
- **Slow first call after idle.** Every in-city fees call that came after
  ~10+ minutes without calls was slow: timed out at 10 s with 0 bytes twice
  (17:29, 17:54 UTC), took 9.5 s once (17:42); every call right after
  answered in 0.7–1.3 s, and empty (intercity) answers in ~45 ms. Looks like
  a cold start behind the fees endpoint (sandbox; production unknown).
  Rate lookups must survive it: short timeout, "no Quickshipper methods"
  rather than a checkout error, and possibly a warm-up or cached quotes —
  to decide in the carrier design. Worth asking Quickshipper.
- Once (18:01 UTC) `GET /v1/Account`, ~100 ms after a successful login,
  returned **HTTP 500 with an empty body**; a rerun seconds later was fine.
  Treat 5xx as transient.
- **The account ApiKey does not authenticate the Delivery API.** `GET
  /v1/Order/fees` with the key as an `ApiKey` or `X-Api-Key` header or an
  `ApiKey` query parameter → HTTP 417 "Please Provide authToken"; as
  `Authorization: Bearer <key>` → HTTP 401 "Invalid Token". Matches the specs:
  their only security scheme is OAuth2 (password and client_credentials
  flows); the key appears only on `GET /v1/Order/express` and in the
  `/v1/Order/confirm` body (Delivery Button).

## 2026-09-30 — fees shapes seen by the carrier (step 03)

Tbilisi → Tbilisi (Freedom Square → Kargareteli St, ~3 km), same trip, varying `CartWeight`:

| CartWeight | OnWay | Wolt | Glovo |
|---|---|---|---|
| none / 2 | 6.60, `deliverySpeedId` 85, `id` "85" | 9.91, `deliverySpeedId` **null**, `id` "asap" | 9.01, `deliverySpeedId` **null**, `id` "ASAP" |
| 4 | 8.60, 86 / "86" | same | same |
| 8 | 10.60, 87 / "87" | same | same |
| 0.454 | — (below its bracket) | 9.91 | 9.01 |

- **`deliverySpeedId` is null for Wolt and Glovo** although the spec types it
  as an integer; OnWay's equals its weight-bracket price id. The price `id`
  (`providerFeeId`) is the only per-price key always present.
- **Glovo now appears when `CartWeight` is sent** — the 2026-09-29 "Glovo
  disappears with any weight" no longer reproduces (question 6 partly moot).
- Wolt's sandbox price is now 9.91 (was 0.65 promo, then 14.32 for a longer trip).

## 2026-10-01 — OnWay weight brackets, exact

Same trip (Freedom Square → Nikoladze St, Tbilisi), `CartWeight` stepped:
1.0 kg → no OnWay; 1.01 and 2.0 → 6.60; 2.01 and 5.0 → 8.60; 5.01 and 10.0 →
10.60; 10.01, 15, 25 → no OnWay. **Brackets are (min, max]** — a parcel of
exactly 1 kg gets no OnWay although the bracket is labelled "0 - 2 kg"
(answers most of question 6). Wolt (5.50) and Glovo (9.01) were the same at
every weight. Confirmed through the storefront checkout with test parcels of
1, 1.5, 3, 7 and 12 kg (module `dev/checks/create-test-products.php`).

## 2026-10-01 — instant vs scheduled couriers, courier filtering (read-only)

Freedom Square → Nikoladze St, Tbilisi (4.11 km), `CartWeight=1.5`, at
00:50 Tbilisi time.

- **No courier filter in the fees request.** `GET /v1/Order/fees` takes only
  the trip, `CartWeight`, `CartAmount`, `CarDelivery` and
  `ScheduleDelivery.*` [spec]. Couriers can only be switched off for the
  whole account (dashboard, or `PUT /v1/Provider/status`), which shows up as
  `isActive:false`; any narrower choice must be made by the integration
  when it reads the answer.
- **Instant prices** (no schedule): Wolt `id "asap"` and Glovo `id "ASAP"`,
  both "45-60 min delivery⚡", `deliveryTime` null; OnWay `id "85"`,
  "4 days delivery". Quoted at 00:50 at night — the sandbox does not check
  courier hours.
- **Scheduled prices**: adding `ScheduleDelivery.ProviderId` +
  `ScheduleDelivery.ScheduledDate` returns **only that courier**, with one
  scheduled price: Wolt `id "custom"`, Glovo `id "CustomDate"`,
  `deliverySpeedName` "02 Oct - 12:00 🗓️", same amount as instant (9.91 /
  9.01). `deliveryTime` is UTC: Glovo with `Z`, Wolt **without** a zone
  suffix, and Wolt's is null for near or past times. For OnWay the schedule
  is ignored (its normal price comes back).
- `hasScheduledDelivery` is true for Wolt, **false for Glovo** — yet Glovo
  returns a scheduled price. The flag cannot be relied on.
- The sandbox accepted 03:00 at night and a time in the past for both. Glovo
  returned nothing 8 days ahead; Wolt still quoted. Real limits (hours, how
  far ahead) unknown — open question 23.
- Wolt has an optional general field "Preparation time(min.)" (id -3, list
  0–60) — the order's `provider.preparationTime`: how long before the
  courier should pick up.
- `providerType` (Wolt 1, Glovo 2, OnWay 5) and `inHouseProvider` (Wolt and
  OnWay true, Glovo false) are undocumented; neither is a documented
  "instant" flag.

## 2026-10-01 — first order attempt (maintainer's go for sandbox orders)

Module `dev/checks/qshpr-order-probe.php`, OnWay (price id `85`, 6.60 GEL,
fee 0.60), Freedom Square → Nikoladze St, 1.5 kg, `phonePrefix "+995"`.

- **`POST /v1/Order` with a 0 ₾ balance is refused**: HTTP 200,
  `success:false`, body `httpStatusCode 400`, `userMessage` "Not enough
  balance", `errors ["Not enough balance"]`, `orderId 0` — nothing created.
  Answers question 3 (the error) and shows the balance is checked **at
  creation**, before any Draft/confirm step. The sandbox account's
  `GET /v1/Account` `balance` is "0 ₾", so no sandbox order can be created
  until the sandbox balance is topped up (dashboard `test.quickshipper.ge`).
- The request body was otherwise not rejected by validation (no 400
  problem+json), so the field names and `phonePrefix "+995"` passed at least
  the schema check.
- `GET /v1/Provider/list` unchanged: Wolt 2 (type 1), Glovo 12 (type 2),
  OnWay 25 (type 5), all active, `isFake:false`.
- Later the same day the **sandbox** balance was topped up to 5 ₾; the
  sandbox is funded by a real card payment (there is no free test credit). The same OnWay order (6.60 + 0.60 fee) was refused
  again with "Not enough balance" (`errors: []` this time): 5 ₾ is below
  the price. Whether the check is against the delivery price alone (6.60) or
  price + fee (7.20) is not known yet. Cheapest possible sandbox test order on
  this trip: OnWay, 6.60 ₾ + 0.60 ₾ fee.
- With the balance at 8 ₾ the same OnWay order got HTTP 200, `success:false`,
  body 400, `developerMessage` "OnWay service failed", `userMessage` null,
  `orderId 0`. Adding `provider.parcelDimensionId: 85` (the 1–2 kg bracket's
  `parcelDimensionsId` from `GET /v1/Order/weights`) changed nothing. Cause
  unknown: the dummy phone (+995 500000001), the OnWay sandbox itself, or a
  missing field. Balance stayed 8 ₾.
- **A refused create can still leave an order behind.** `GET /v1/Order/list`
  then showed orders **42606** (the 5 ₾ "Not enough balance" attempt) and
  **42607** (the first "OnWay service failed" attempt), each created as
  `Draft` and moved to `Cancelled` within a second, with the reason as an order
  comment (author = the account login), `channel "DeliveryApi"`,
  `deliveryType "Scheduled"`, `orderPrice 6.60`, `serviceFee 0.60`. The API
  answer did **not** return their ids (`orderId 0`). The 0 ₾ attempt created
  nothing (balance pre-check). An integration must therefore treat
  `success:false` from `POST /v1/Order` as "not dispatched" but expect a
  cancelled record in the merchant's order list.
- Both orders have `externalOrderId: null`. (First read as "our
  `integrationOrderId` was not stored" — wrong: `externalOrderId` is the
  courier's id; our reference is shown in the dashboard only, see order
  42609 below.)
- `customer.phoneNumber` "500000001" + `phonePrefix` "+995" were stored as
  sent; the list shows `customerPhone "+995500000001"`. The shop contact was
  taken from our pickup name and phone.
- The third OnWay attempt (with `parcelDimensionId`) left order **42608**,
  also auto-cancelled.

### First successful order — Wolt, 2026-10-01 (sandbox order 42609)

Freedom Square → a point 2.12 km away (Wolt's lowest distance price 5.50 ₾;
Wolt was 9.91 ₾ beyond ~4.5 km, Glovo 9.01 ₾ at every distance tried),
`providerFeeId "asap"`, `preparationTime 30`, dummy phone +995 500000001.

- **`POST /v1/Order`** → in 4.1 s: `success:true`, `orderId 42609`,
  **`orderStatus "ReadyForPickup"`**, `deliveryTime "asap"`, `deliveryFee 5.5`,
  `trackingUrl https://test.quickshipper.ge/track/SQ42609`. Status log: `Draft`
  → `ReadyForPickup` 2.6 s later **on its own** — no `/confirm` call is needed
  (answers question 2 for the Delivery API).
- **Charging**: balance 8.00 → **2.50** right after creation (the 5.50 delivery
  price). After cancelling: **7.40** — the delivery price came back, the 0.60
  service fee was kept. The order's `orderPrice` became 0.6 after cancel.
  A cancelled-before-courier order costs the merchant the service fee.
- **`GET /v1/Order`**: `deliveryType "ASAP"` (OnWay's failed orders were
  "Scheduled"), `approximateDeliveryMin 90`, `scheduledTime` = creation + 40 min
  (UTC, `Z`), `courier null`.
- **`integrationOrderId` is stored but not returned by the API.** The
  merchant dashboard's order page shows it under "Additional details" as
  "შიდა შეკვეთის კოდი" (internal order code): `probe-1790802746` (seen by the
  developer, 2026-10-01). The API's `GET /v1/Order` and `/v1/Order/list`
  do not return it; their `externalOrderId` held
  `"6abd7b3c72e58806e5c995cb"` (looks like Wolt's own id). So the merchant can
  match an order to Magento by eye in the dashboard, but code cannot — the
  integration must store Quickshipper's `orderId` itself. (The earlier
  failed orders' `externalOrderId: null` is the courier id missing, not ours.)
- Dashboard view of the cancelled order: price shown 0.6 ₾ (the kept fee),
  status timeline "registered 01:12 → order cancelled 01:12" (Tbilisi time),
  pickup/drop-off with names and phones as sent, our `comment` as
  "additional comment".
- **Waybill** `GET /v1/Order/waybill` → HTTP 200, `success:false`, body 400:
  "Unable to load shared library 'libwkhtmltox'" — broken on the sandbox
  server (wkhtmltox is an HTML→PDF renderer, so the waybill is presumably a
  PDF; not verified).
- **Cancel** `POST /v1/Order/status {orderId, status:"Cancelled",
  orderComment}` 2 s after creation → `success:true`, status `Cancelled`.
  "Cancelled" is the right status value (question 8, partly).
- `GET /v1/Webhook` on the sandbox account → `webhooks: []` (none registered).
  The docs describe only registration (`callBackUrl`, `maxRetryCount`,
  `type` OrderStatusChange / CourierLocation); the payload is undocumented
  (question 1) and has to be captured live.

## 2026-10-01 — dashboard setting: courier assignment for API orders

Seen by the maintainer in the merchant dashboard settings: "API-ით შეკვეთის
განთავსებისას" (when an order is placed through the API), two options —
**"საკურიერო კომპანია დაინიშნოს ავტომატურად"** (assign the courier company
automatically; selected on the sandbox account) and **"საკურიერო კომპანია
დაინიშნოს შეკვეთის დადასტურების შემდეგ"** (assign it after the order is
confirmed).

- This matches the docs' two Delivery Button scenarios (order created
  `ReadyForPickup`, or created `Draft` and sent to the courier by
  `POST /v1/Order/confirm`) and explains the Wolt order of 2026-10-01 going
  Draft → ReadyForPickup by itself: the account is on "automatically".
- Not verified yet: that "after confirmation" makes `POST /v1/Order` return
  `Draft`; whether the balance is charged at create or at confirm in that
  mode; how long an unconfirmed Draft lives and whether deleting it is free;
  whether the order field `autoAssign` (boolean in the spec, undocumented)
  overrides the setting per request.

### Webhook registration, 2026-10-01

- `POST /v1/Webhook {callBackUrl, type: "OrderStatusChange",
  maxRetryCount: 3}` → `success:true`, `webhookId 1081`. `GET /v1/Webhook`
  lists it with `isActive: true`. Note the casing: `callBackUrl` on create
  and in the list, `callbackUrl` in the `PUT` model [spec].
- No test or verification call was sent to the URL at registration (none
  within 20 s).
- There is no delete endpoint for webhooks [spec]: an unwanted one is
  switched off with `PUT /v1/Webhook {id, isActive: false}`.
- `GET /v1/Webhook` entry as listed on 2026-10-03 [live]:
  `{"id":1081,"callBackUrl":"https://…/qshpr/webhook/status?key=…","isActive":true,"type":"OrderStatusChange","maxRetryCount":3}`
  — `type` is the name as a string (not the number), `callBackUrl` with a
  capital B, the query string kept as registered. The endpoint takes no
  parameters [spec]: always every webhook of the account, both types,
  active and inactive. Sent without `Content-Type` (the docs list it even
  on this GET) — accepted.

### `autoAssign` and dropping a Draft — test T1, 2026-10-01 (order 42615 / VT42615)

Portal on "assign the courier after confirmation"; probe order Wolt `asap`,
Freedom Square → Nikoladze St (2.12 km), `"autoAssign": true`.

- **`autoAssign: true` does not override the portal setting**: `POST
  /v1/Order` → `orderStatus "Draft"` (747 ms); still Draft 20 s later,
  `statusLogs: []`, `externalOrderId: null`, `deliveryType "Scheduled"`,
  `scheduledTime null`. What the field does is still unknown.
- **The price was taken at creation** again: balance 6.80 → 1.90 ₾ (4.90).
- **A never-confirmed Draft can be neither cancelled nor deleted**:
  `POST /v1/Order/status {status: "Cancelled"}` → HTTP 200, `success:false`,
  body 400 "Order status change failed"; `DELETE /v1/Order {orderId}` →
  `success:false`, 400 "Order delete is restricted". The order stays Draft
  and the 4.90 ₾ stays held. (A confirmed order could be cancelled before a
  courier, 2026-10-01, order 42610.)
- **No webhook** for the Draft creation (webhook 1081 active, tunnel
  verified): creating a Draft is not a logged status change.
- Prices that day: Wolt on this trip **4.90 ₾** (was 5.50 earlier the same
  day), **`serviceFee` 0** in the fees answer and on the order (was 0.60).
- Waybill still broken on the sandbox (`libwkhtmltox`).

### Fee-free requests granted — 2026-10-01

The maintainer contacted Quickshipper, who granted the sandbox account **20
requests without the service fee**. This explains `serviceFee` 0 from the
afternoon of 2026-10-01 (order 42615 onwards; 0.60 ₾ before). It is a
courtesy for testing, **not** normal pricing: the usual fee is 0.60 ₾ per
order, kept on cancel. How the 20 are counted (per created order, per
cancel, including refused creates?) is not known.

### Draft 42615 resolved, and the first webhooks — 2026-10-01

- ~1 h 30 min after creation the Draft was unchanged (no expiry seen in that
  time) and a direct cancel was still refused ("Order status change failed").
- Confirm (13:59:43 UTC, 3.5 s) → `ReadyForPickup`, `externalOrderId` set;
  balance unchanged. Cancel 17 s later (0.9 s) → `Cancelled`, `orderPrice`
  0. **Balance 1.90 → 6.80 ₾: the full 4.90 came back, no fee kept** (the
  sandbox showed `serviceFee` 0 that afternoon). `statusLogs` after the
  cancel: Draft (13:30:14) → ReadyForPickup → Cancelled — the Draft entry
  appears only once the order has moved on.
- **Webhook `OrderStatusChange` (id 1081) delivered both changes** — none
  for the Draft's creation:
  - `POST <callBackUrl>` exactly as registered, `Content-Type:
    application/json`, ~1.3 s after the change (ReadyForPickup) and within
    ~0.4 s (Cancelled). Sent from the same Quickshipper server address both times.
  - **Body in PascalCase** (unlike the camelCase API answers):
    `{"OrderId": 42615, "Status": "ReadyForPickup", "Order": {…},
    "Customer": {Name, PhoneNumber, PhonePrefix}, "Provider": {ProviderId,
    ProviderName, LogoUrl, Note}, "OrderCourierModel": null}`. `Order`
    mirrors `GET /v1/Order`'s `order` (Id, Status, OrderNo, OrderPrice,
    ServiceFee, DeliveryType, addresses and coordinates, ScheduledTime,
    StatusLogs, TrackingUrl, ExternalOrderId, Channel, …). Contains
    personal data (customer name and phone).
  - **No signature or secret**: headers are only `Content-Type`,
    `Traceparent`, `X-Correlation-Id` (a UUID per call) and proxy headers.
    Anyone who knows the URL could post the same body — a receiver must
    treat it as a hint and re-read the order with `GET /v1/Order` (and can
    add its own secret to the callback URL).
  - Our capture answered 200; retries on failure not observed yet
    (`CAPTURE_STATUS=503 ./run.sh start` would show them).

### Draft mode, tried 2026-10-01 (order 42610 / JM42610)

With the dashboard set to "assign the courier after confirmation", a booking
from the module (admin Ship → Submit Shipment, Wolt `asap`, 5.50 ₾) got
`orderStatus "Draft"` from `POST /v1/Order`; `GET /v1/Order` then showed
status Draft, `statusLogs: []`, no courier. **The balance dropped by the
delivery price at creation** (7.40 → 1.90 ₾); the 0.60 fee was not taken
at that point. So in Draft mode the money is already held before any
confirm. Still to see: whether the Draft ever moves or expires on its own,
what `/confirm` does, and whether deleting or cancelling a Draft returns
the money (and keeps the fee or not).
- **Dashboard view of the Draft** (maintainer's screen): status
  "შეკვეთა ვერ დარეგისტრირდა" (the order could not be registered) with a
  button "ცადე თავიდან გაშვება" (try launching again) — in this mode an
  unconfirmed order looks like a failed one to the merchant. Internal order
  code = our `integrationOrderId` (000000023). Distance shown 5.39 km (the
  API `distance`); Wolt still priced 5.50.
- **Doubled country code is cleaned by Quickshipper.** We sent
  `phonePrefix "+995"` + `phone "+995555123456"` (the number as typed at
  checkout); the order's `customer` came back `phoneNumber "555123456"`,
  `phonePrefix "+995"`, and the dashboard shows "555 123 456". So sending
  the phone as entered is safe for numbers typed with +995.
- **`POST /v1/Order/confirm {orderId}`** with the Bearer token, 4 minutes
  after creation → in 2.7 s: `success:true`, `orderStatus "ReadyForPickup"`,
  `deliveryFee 5.5`. The order then had `statusLogs`
  `[ReadyForPickup]` (the Draft period is not logged in this mode),
  `externalOrderId "6abd8dfc26ea6e327adfe6cf"` (the courier's id, set only
  now), `approximateDeliveryMin 90`, `scheduledTime` = confirm + ~45 min, no
  courier yet. **The balance did not change on confirm** (1.90 ₾ before and
  after): the delivery price was taken at creation, and the 0.60 fee was not
  taken at confirm either.
- **The sandbox sends Wolt orders to Wolt's development environment.** The
  developer found Wolt's own tracking link for JM42610:
  `https://daas-track.development.dev.woltapi.com/en/s/<code>` — "daas" is
  Wolt Drive (delivery as a service), `development.dev.woltapi.com` is not a
  production domain, so no real courier is expected (inferred from the
  domain; the page does not say "test"). The page showed "Almost there! Your
  order is being prepared now", "40-50 minutes until delivery", and the
  a brand name that is not our pickup name — the Wolt test venue behind the
  sandbox account. This link is not in Quickshipper's API answer (only
  Quickshipper's own `trackingUrl` is); question 28 partly answered.
- Wolt's own docs (https://developer.wolt.com/docs/wolt-drive/endpoints,
  read 2026-10-01) confirm `daas-public-api.development.dev.woltapi.com` as
  Wolt Drive's **development** base URL (production:
  `daas-public-api.wolt.com`). They do not say whether development deliveries
  are simulated or how they progress. Wolt invoices merchants per their Wolt
  contract; the money in our tests is taken by **Quickshipper** from its own
  balance. Wolt: a delivery can be cancelled until a courier accepts it;
  a late cancel may carry a fee.
- **Cancelled after confirm, before a courier** (10 min after confirm,
  `POST /v1/Order/status` `Cancelled`, 1.5 s): `success:true`; `orderPrice`
  became 0.6; `statusLogs` now shows Draft → ReadyForPickup → Cancelled
  (the Draft entry appears only after the cancel). Balance 1.90 → **6.80 ₾**:
  the 5.50 delivery price came back and the 0.60 fee was taken at cancel.
  Same net cost as in automatic mode: a cancelled order costs the fee.
  No courier had been assigned in the ~10 minutes after confirm.

## 2026-10-01 (evening UTC) — module flows tested live with the maintainer

Portal on "after confirmation"; Wolt; balance 6.80 ₾ before and after each
booking (one open booking at a time). Orders 42616 (UH42616), 42617
(CI42617), 42618 (GQ42618), all cancelled before a courier.

- Every booking answered `Draft`; `POST /v1/Order/confirm` took **7.2–9.9 s**
  and answered `ReadyForPickup`. `serviceFee` 0 on all three (the fee-free
  grant); each cancel returned the full 4.90 ₾ — balance back to 6.80.
- **The `OrderStatusChange` webhook arrives while our own request is still
  running**: `ReadyForPickup` came ~5 s into the 7–10 s confirm, `Cancelled`
  during the 7–8 s cancel call. Our store answered 503 (order locked) to all
  6; **none was sent again** in the following ~20 minutes, despite
  `maxRetryCount: 3` on the webhook. So a 503 is not reliably retried (or
  retries come much later — unknown). The status that our own call causes
  must be taken from its answer, not from the webhook.
- Webhook headers seen: `Content-Type: application/json`, `Traceparent`,
  `X-Correlation-Id`, `X-Forwarded-For` (one fixed Quickshipper address) (behind the
  tunnel); still no signature.
- `GET /v1/Order` after a cancel: `statusLogs` Draft → ReadyForPickup →
  Cancelled, as with 42610. No courier was assigned to any of them in the
  minutes they were open.

## 2026-10-03 — courier field names (code review)

- `GET /v1/Order` → `courier` holds `courierId`, `courierName`,
  `courierPhoneNumber`, `courierImage`, `transportType`, `status`,
  `courierExternalId` [spec, sandbox and production alike]. This file's
  summary in `api.md` had said `{name, phone, …}` and the module read
  `courier.name`, so a courier's name would never have been recorded; fixed
  to `courierName`. **Not yet seen live**: no sandbox order has reached an
  assigned courier — the first real assignment should confirm the name.
- **Slow first answer again** (maintainer's checkout test, 2026-10-03 at
  02:39 Tbilisi time, after hours without requests): the login answered in
  491 ms, but `GET /v1/Order/fees` gave nothing within our 10 s timeout;
  the same request 36 s later took 933 ms. Matches question 19 (first
  request after ~10 idle minutes takes 9–10+ s).


## 2026-10-03 — car delivery by weight (price requests only, nothing created)

Same trip (Freedom Square → Iakob Nikoladze St 7, Tbilisi), `CartWeight`
3 / 8 / 11 / 15 / 25 kg, each with and without `CarDelivery=true`:

- Wolt `asap` 9.31 and Glovo `ASAP` 8.41 at **every** weight, with or
  without a car — no surcharge, no car forced, no refusal even at 25 kg.
- OnWay is offered only without a car (`hasCarDelivery:false`) and only up to
  8 kg here (86 = 8 ₾ at 3 kg, 87 = 10 ₾ at 8 kg; gone at 11 kg).
- `hasCarDelivery` true for Wolt and Glovo (fee and price level);
  `minWeightForCarDelivery` Wolt `11000`, Glovo `0`, OnWay `0`.
- So in the sandbox `CarDelivery=true` only filters out couriers without a
  car. Whether production prices a car differently or forces one above a
  weight is still unknown (question 21). Through the checkout (module
  branch `feature/car-delivery`): a 3 kg product set to car delivery →
  Glovo + Wolt only; unset → OnWay, Glovo, Wolt.
- The flag is sent as the text `true` (the module's query is built with
  `http_build_query`, which would turn a PHP `true` into `1`).

## 2026-11-01 — the service fee is inside `amount` (price request only)

The sandbox account still had fee-free requests left, so `/fees` answered
`serviceFee` 0. Same kind of trip as before (Freedom Square → Nikoladze
St, 5.06 km, `CartWeight` 2): **OnWay `85` 6.00, Wolt `asap` 4.90**.
With the fee charged the same prices were OnWay 6.60 (2026-09-29) and Wolt
5.50 (2026-10-01, before the grant); on 2026-10-03 (fee-free) OnWay 86/87
were 8.00/10.00 against 8.60/10.60 on 2026-09-29. Every courier is exactly
the 0.60 fee cheaper while the fee is waived, and order 42609 took exactly
`amount` (5.50) from the balance. So **`amount` includes `serviceFee`**:
the merchant is charged `amount`, of which `serviceFee` is Quickshipper's
part. Answers most of open question 5 (VAT and production still unasked).
The module therefore records `amount` as the rate's cost (not
`amount + serviceFee`), and a shop that pays the fee itself shows the
customer `amount − serviceFee`.


## 2026-11-01 — a delivery time survives in automatic mode (order 42852 / XR42852)

Portal on "assign the courier automatically"; module booking (store order
000000045), Wolt, quote with `ScheduleDelivery.*` → `providerFeeId
"custom"`, `scheduledTime "2026-11-02T06:00:00Z"`, no `autoAssign` field.
`POST /v1/Order` → `ReadyForPickup` at once (no Draft, no `/confirm`);
`GET /v1/Order` kept `deliveryType "Scheduled"`, `scheduledTime
"2026-11-02T06:00:00Z"` for hours afterwards (`orderPrice` 4.9,
`serviceFee` 0, no courier yet). So only the Draft → `/confirm` path drops
the time (order 42619, 2026-10-03; seen again the same day with order
42851 / ZB42851, booked while the portal was still on "after
confirmation": Draft → module's `/confirm` → time replaced by "now", the
module's warning note appeared; cancelled through the module's button,
full 4.90 ₾ back). A real phone number was given as the
recipient; the maintainer received no SMS (T&C 2.4–2.5 say the tracking
link is sent by SMS at placement — unknown whether the sandbox sends any).
One `GET /v1/Order` for it got HTTP 500 with an empty body (22:00 UTC);
the polling cron retried later.

## 2026-11-02 — weight and cart value in `/fees` (price requests only, nothing created)

Freedom Square → Iakob Nikoladze St 7, Tbilisi (fee still waived):

| `CartWeight` | Answer |
|---|---|
| not sent | OnWay `85` 6.00, Wolt `asap` 4.90 |
| 0.5 / 1 | Wolt only |
| 1.01 / 2 | OnWay `85` 6.00, Wolt 4.90 |

- `CartWeight` is optional [docs: not marked required; spec: nothing is];
  without it OnWay quotes its 1–2 kg price, but an explicit weight of 1 kg
  or less drops OnWay although its bracket is labelled "0 - 2 kg" (as on
  2026-10-01). The maintainer chose not to ask Quickshipper (old question 6).
- Glovo returned no price at all that day.
- `CartAmount` 500, 999, 1001 and 5000 (with `CartWeight=2`): identical
  answers — **Wolt is still offered above 1,000 ₾**, although Wolt's annex
  to the courier terms (https://qshpr.com/ka/post/54) caps a parcel's
  contents at 1,000 ₾ incl. VAT and lets Wolt refuse a dearer one
  (`business.md`). Whether production filters by `CartAmount` is an open question.
