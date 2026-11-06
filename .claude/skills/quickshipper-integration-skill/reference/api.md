# Quickshipper Delivery API — reference

The parts of the API the module uses, from [docs], [spec] and [live] (see
`../SKILL.md` for the tags and the spec URLs). Field-level schemas: the spec.

## Hosts

| | Sandbox | Production |
|---|---|---|
| Auth (OAuth2 / OpenIddict) | `https://test-auth.quickshipper.ge` | `https://auth.quickshipper.app` |
| Delivery API | `https://delivery-test.quickshipper.ge` | `https://delivery.quickshipper.app` |
| OpenAPI spec | `https://delivery-test.quickshipper.ge/swagger/v1/swagger.json` | `https://delivery.quickshipper.app/swagger/v1/swagger.json` |
| Merchant dashboard | `https://test.quickshipper.ge` | `https://dashboard.quickshipper.app` |
| Tracking page | `https://test.quickshipper.ge/track/<code>` [docs] | returned as `trackingUrl` |

Sources: [docs] Authorization section; [spec] `securitySchemes` [live 2026-09-29].

## Authentication

`POST {AUTH}/connect/token` — OAuth2 token endpoint (OpenIddict; discovery at
`{AUTH}/.well-known/openid-configuration`).

```
Authorization: Basic base64(<client_id>:<client_secret>)
Content-Type: application/x-www-form-urlencoded

grant_type=password&scope=DeliveryApi&username=<login email>&password=<password>
```

- Client: `DeliveryApiClient:DeliveryApiSecret` (public, [docs]). Quickshipper
  says production currently uses the same client [vendor 2026-11-06] (their
  e-mail swapped the labels "client" and "secret"; the pair above is the
  one that works). Not yet tried against production. Every merchant uses
  this client with their own dashboard login — no per-merchant client.
- The body **must be form-encoded**. The docs example shows a JSON body; the
  server rejects it (`invalid_request: The mandatory 'grant_type' parameter is
  missing`). [live 2026-09-29]
- `username`/`password` = the merchant's dashboard login **in the same
  environment** (a production account is "User not found" on the sandbox).
  [live 2026-09-29]
- Response: `{access_token, token_type:"Bearer", expires_in:31535999}`, **no
  refresh_token**. The token is a JWT valid ~1 year; its claims include `Shop`, `ShopOffset`,
  `scope:"DeliveryApi"`, `email`, `role`. [live 2026-09-29]
- Discovery also lists `client_credentials`, `refresh_token` grants; the spec
  declares a `clientCredentials` flow for scope `DeliveryApi`. Not offered to
  merchants.
- Production does not require the caller's IP to be allow-listed, but
  Quickshipper recommends it ("not required, but desirable"): send them the
  server's IP. [vendor 2026-11-06]
- Every Delivery API call: `Authorization: Bearer <token>`,
  `Content-Type: application/json`.

## Response envelopes (three shapes)

1. **Business envelope** (most responses, HTTP 200 even on failure):
   ```json
   {"httpStatusCode":200,"userMessage":"Operation Completed","developerMessage":"Operation Completed","success":true,"errors":[], ...payload}
   ```
   Failure example: HTTP **200** with `{"httpStatusCode":404,"userMessage":"Order not found","success":false}`.
   Always decide on `success` (+ body `httpStatusCode`), never on HTTP status alone. [live]
2. **Gateway auth errors**: HTTP 401 `{"status":"Error","message":"Invalid Token"}`;
   HTTP **417** `{"status":"Error","message":"Please Provide authToken"}` when the header is missing. [live]
3. **ASP.NET validation**: HTTP 400 `application/problem+json`
   `{"title":"One or more validation errors occurred.","status":400,"errors":{"Field":["..."]},"traceId":"00-…"}`.
   Log `traceId` — it is the vendor request id. [live]

## Endpoints

All paths under `{API}`.

### Rates

**`GET /v1/Order/fees`** — quotes from every provider for a from→to trip.
Query [spec]: `FromStreetName, FromCityName, FromLatitude, FromLongitude,
ToStreetName, ToCityName, ToLatitude, ToLongitude` (docs mark all 8
required; spec marks none; live: coordinates alone work, but Glovo then drops out),
`CartWeight` (kg), `CartAmount` (GEL), `CarDelivery` (bool),
`ScheduleDelivery.ProviderId`, `ScheduleDelivery.ScheduledDate`,
`Parcels` (one entry per box: `parcels[0].width=30&parcels[0].height=20&parcels[0].length=15`),
`CardOnDelivery` (bool, "adds the configured transaction fee where
supported"). [spec 2026-11-06]

- **Parcel size changes the price for some couriers.** Send it either as
  `Parcels` (+ `CartWeight`) or as a `parcelDimensionId` the customer picked
  from the possible values; `parcels` are the boxes the courier takes.
  Quickshipper says its docs on this are out of date and points to the
  spec. [vendor 2026-11-06] Not yet tried live. `GET /v1/Order/weights`
  (`FromLatitude…ToLongitude, ProviderId, DeliverySpeedId, CartAmount`)
  returns weight brackets with `parcelDimensionsId`, `minWeight`,
  `maxWeight`, `userPrice` — probably those values (unconfirmed). [spec]
- **`CartAmount`** feeds delivery-price rules the merchant sets on the
  dashboard's integration page (price by cart amount); without such rules it
  can be ignored. [vendor 2026-11-06]
- **Rate limit**: recommended at most **120 requests per minute per
  account**. [vendor 2026-11-06] What an excess call gets (e.g. HTTP 429) is
  unknown.

Response: `fees[]` of `ProviderDeliveryFeeModel` + `distance` (km):

| field | meaning |
|---|---|
| `providerId`, `providerName`, `providerCode`, `providerLogoUrl`, `providerNote` | courier identity |
| `isActive` | active for this account |
| `prices[]` | `amount`, `oldAmount` (strike-through price), `currencyName` (`GEL`), `currency` (`₾`), `deliverySpeedId`, `deliverySpeedName` (e.g. "45-60 min delivery⚡", "4 days delivery"), `deliveryTime`, **`id` = providerFeeId** to pass back on order create, `hasCarDelivery` |
| `serviceFee`, `serviceFeeCurrency` | Quickshipper fee, reported separately (0.6 ₾ in sandbox) |
| `hasWeight` | provider prices by weight bracket |
| `hasCashOnDelivery`, `hasCarDelivery`, `hasScheduledDelivery`, `minWeightForCarDelivery` | capabilities |
| `generalFields[]` | provider-specific order fields (e.g. Wolt "Preparation time(min.)" list 0–60) |
| `discountPercent`, `minPrice`, `inHouseProvider`, `showBarcodeWarning` | display hints |

### Orders

**`POST /v1/Order`** — place an order (`OrderPlaceRequestModel`) [spec]:
`pickUpInfo` / `dropOffInfo` {`address`, `latitude`, `longitude`,
`addressComment`, `name`, `phonePrefix`, `phone`, `city`, `country`};
`provider` {`providerId`, `providerFeeId` (= `prices[].id` from fees),
`deliverySpeedId`, `preparationTime` (min, Wolt only), `parcelDimensionId`,
`parcelDimensions[]` {length,width,height,weight}, `parcelsQuantity`};
`parcels[]` {`fields[]` {id,value,type}}; `generalFields[]`;
`carDelivery`, `comment`, `scheduledTime`, `autoAssign`,
`courierTransportType`, `courier` (own-fleet), **`integrationOrderId`** (our
reference — stored and shown in the dashboard, not returned by
`GET /v1/Order`, but a filter of `GET /v1/Order/list`; `externalOrderId` is
the courier's id [live 2026-10-01]), `dropThePin`,
`cashOnDelivery` {`deliveryPrice`, `parcelPrice`, `paymentMethod` (0/1)},
`cartAmount`, `cartWeight`, top-level `parcelDimensionId`, `parcels[]`
{`width`, `height`, `length`, `fields[]`} [spec 2026-11-06].
- **`autoAssign`** is for a merchant's **own couriers** (automatic
  distribution to them; dashboard → delivery settings → "automatic order
  distribution"). It does nothing for courier companies and cannot change
  the "assign after confirmation" (Draft) setting per order.
  [vendor 2026-11-06]
- **Cash on delivery**: Quickshipper says it has **no COD service**
  [vendor 2026-11-06], although the spec still has `cashOnDelivery` here,
  `hasCashOnDelivery` in prices and `CardOnDelivery` on `/fees`. Do not send
  `cashOnDelivery` until Quickshipper explains these fields
  (`open-questions.md`).
Docs mark required: dropOff `address, latitude, longitude, name, phonePrefix,
phone, city`; pickUp `address, latitude, name, phone, city`.
Response `OrderPlaceResponseModel`: `orderId` (int), `orderStatus`,
`trackingUrl`, `deliveryTime`, `deliveryFee`, `providerName`, … 
Whether it lands as `Draft` or `ReadyForPickup` depends on the merchant's
dashboard setting [live 2026-10-01] — see `live-findings.md`.

**`POST /v1/Order/confirm`** — `{orderId, apiKey?}`: confirms a Draft order to
the provider → `ReadyForPickup`. Accepts Bearer **or** `apiKey` in the body. [spec]

**`GET /v1/Order?OrderId=<int>`** — full order: `order` {id, status,
orderNo, orderPrice, serviceFee, deliveryType, addresses/coords, distance,
approximateDeliveryMin, scheduledTime, deliveryStartDate, createDate,
`statusLogs[]` {status, createDate}, trackingUrl, `externalOrderId`,
`channel`, images, comments, custom fields}, `courier` {`courierId`,
`courierName`, `courierPhoneNumber`, `courierImage`, `transportType`, `status`,
`courierExternalId`}, `provider` {`providerId`, `providerName`, `logoUrl`,
`note`}, `customer`, `shop`. Unknown id →
HTTP 200 `success:false, httpStatusCode:404`. [spec][live]

**`GET /v1/Order/list`** — query `FromDate, ToDate, Status,
IntegrationOrderId, Limit, Page`. Finds our orders by `integrationOrderId`
(e.g. after a booking that got no answer). [spec][vendor 2026-11-06] Exact,
case-sensitive match; answer and item fields in `live-findings.md`.
[live 2026-11-06]

**`POST /v1/Order/status`** — `{orderId, status, orderComment}`. Used for
cancellation. Cancelling only works if no courier is assigned yet, or if the
provider allows cancellation through the integration; some providers require a
written request. Invalid status → `"Bogus is not valid status"` (400 in body). [docs][live]
Quickshipper's advice for cancelling: contact its support. [vendor 2026-11-06]

### Account

- `GET /v1/Account` — `apiKey`, `googleMapKey`, `defaultGoogleMapKey`,
  `shopName`, `userName`, `balance` (e.g. `"0 ₾"` — a string), `hasProviders`. [spec][live]

Endpoints the module does not call (order edit, delete, waybill, weight
brackets, custom fields, provider settings, own-fleet couriers) are in the
spec.

### Webhooks

- `POST /v1/Webhook` `{callBackUrl, maxRetryCount, type, apiKey}` → `webhookId`.
- `PUT /v1/Webhook` `{id, maxRetryCount, isActive, type, callbackUrl, apiKey}`.
  [spec 2026-11-06]
- `GET /v1/Webhook` → `webhooks[]` (`id, callBackUrl, isActive, type,
  maxRetryCount` — the `apiKey` is not listed). [spec]
- Types: `OrderStatusChange`, `CourierLocation`. [docs]
- **Authentication**: the `apiKey` given at registration is sent with every
  call in an **`apikey` header**. No HMAC signature. [vendor 2026-11-06]
  Confirmed on the sandbox, also after setting it with `PUT`. [live 2026-11-06]
- **Retries** (production): up to **5**, after 1, 5, 15, 30 and 60 minutes.
  The sandbox currently sends **once**. [vendor 2026-11-06]
- **Source IPs** are fixed, from a set; Quickshipper sends the list for an
  allow-list on request. [vendor 2026-11-06]
- What was observed is in `live-findings.md` (PascalCase body): treat a
  call as a hint and re-read the order with `GET /v1/Order`.

## Enums

**OrderStatus** [docs]: `Draft` (created, not ready), `ReadyForPickup`,
`WaitingForCourier` (offer mode), `Assigned`, `Pickup` (courier took parcel),
`OnTheWay`, `Delivered`, `Cancelled`, `DeliveryFailed` (delivery or pickup failed).
Final: Delivered, Cancelled, DeliveryFailed.
