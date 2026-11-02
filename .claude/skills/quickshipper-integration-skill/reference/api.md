# Quickshipper Delivery API — reference

Distilled 2026-09-29 from [docs], [spec] and [live] (see `../SKILL.md` for the
tags). Field-level schemas: `../source/openapi-summary.md`.

## Hosts

| | Sandbox | Production |
|---|---|---|
| Auth (OAuth2 / OpenIddict) | `https://test-auth.quickshipper.ge` | `https://auth.quickshipper.app` |
| Delivery API | `https://delivery-test.quickshipper.ge` | `https://delivery.quickshipper.app` |
| OpenAPI spec | `https://delivery-test.quickshipper.ge/swagger/v1/swagger.json` | `https://delivery.quickshipper.app/swagger/v1/swagger.json` |
| Merchant dashboard | `https://test.quickshipper.ge` | see `business.md` |
| Delivery Button script | `https://express.quickshipper.ge/assets/quickshipper-express.min.js` | `https://express.quickshipper.app/assets/quickshipper-express.min.js` |
| Tracking page | `https://test.quickshipper.ge/track/<code>` [docs] | returned as `trackingUrl` |

Sources: [docs] Authorization section; [spec] `securitySchemes`, Theneo
project `publishedUrls`; widget scripts' hard-coded `E_BASE_API_URL`
[live 2026-09-29].

## Authentication

`POST {AUTH}/connect/token` — OAuth2 token endpoint (OpenIddict; discovery at
`{AUTH}/.well-known/openid-configuration`).

```
Authorization: Basic base64(<client_id>:<client_secret>)
Content-Type: application/x-www-form-urlencoded

grant_type=password&scope=DeliveryApi&username=<login email>&password=<password>
```

- Sandbox client: `DeliveryApiClient:DeliveryApiSecret` (public, [docs]).
  Production client: **not published** — open question.
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
  declares a `clientCredentials` flow for scope `DeliveryApi`. Not documented
  for merchants — open question whether Quickshipper issues per-merchant
  client credentials (would avoid storing a password).
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

All paths under `{API}`. "Used by carrier?" is our PROPOSED relevance, not a decision.

### Rates

**`GET /v1/Order/fees`** — quotes from every provider for a from→to trip.
Query [spec]: `FromStreetName, FromCityName, FromLatitude, FromLongitude,
ToStreetName, ToCityName, ToLatitude, ToLongitude` (docs mark all 8
required; spec marks none; live: coordinates alone work, but Glovo then drops out),
`CartWeight` (kg), `CartAmount` (GEL), `CarDelivery` (bool),
`ScheduleDelivery.ProviderId`, `ScheduleDelivery.ScheduledDate`.

Response: `fees[]` of `ProviderDeliveryFeeModel` + `distance` (km):

| field | meaning |
|---|---|
| `providerId`, `providerName`, `providerCode`, `providerLogoUrl`, `providerNote` | courier identity |
| `isActive` | active for this account |
| `prices[]` | `amount`, `oldAmount` (strike-through price), `currencyName` (`GEL`), `currency` (`₾`), `deliverySpeedId`, `deliverySpeedName` (e.g. "45-60 min delivery⚡", "4 days delivery"), `deliveryTime`, **`id` = providerFeeId** to pass back on order create, `hasCarDelivery` |
| `serviceFee`, `serviceFeeCurrency` | Quickshipper fee, reported separately (0.6 ₾ in sandbox) |
| `hasWeight` | provider prices by weight bracket (see `/weights`) |
| `hasCashOnDelivery`, `hasCarDelivery`, `hasScheduledDelivery`, `minWeightForCarDelivery` | capabilities |
| `generalFields[]` | provider-specific order fields (e.g. Wolt "Preparation time(min.)" list 0–60) |
| `discountPercent`, `minPrice`, `inHouseProvider`, `showBarcodeWarning` | display hints |

**`GET /v1/Order/weights`** — weight brackets and prices for one provider.
Query: `FromLatitude, FromLongitude, ToLatitude, ToLongitude, ProviderId,
DeliverySpeedId, CartAmount`. Items: `userPrice`, `oldUserPrice`,
`displayName` ("2 - 5 kg"), `parcelDimensionsId`, `minWeight`, `maxWeight`
(kg). Providers without weight pricing (Wolt) → `success:false`, 400. [spec][live]

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
reference — stored and shown in the dashboard, but not returned by the API;
`externalOrderId` is the courier's id [live 2026-10-01]), `dropThePin`,
`cashOnDelivery` {`deliveryPrice`, `parcelPrice`}, `cartAmount`, `cartWeight`.
Docs mark required: dropOff `address, latitude, longitude, name, phonePrefix,
phone, city`; pickUp `address, latitude, name, phone, city`.
Response `OrderPlaceResponseModel`: `orderId` (int), `orderStatus`,
`trackingUrl`, `deliveryTime`, `deliveryFee`, `providerName`, … 
Whether it lands as `Draft` or `ReadyForPickup` is **not documented** — open question.

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

**`GET /v1/Order/list`** — `FromDate, ToDate, Status, Limit, Page` →
`items[]` {id, orderNo, status, createDate, addressFrom/To, customerName,
customerPhone, `externalOrderId`, trackingUrl}, `itemsCount`, `page`, `limit`.
Useful for a reconciler. [spec][live]

**`PUT /v1/Order`** — edit (`EditOrderRequestModel`: orderId, scheduledTime,
provider, pickUp/dropOff, comment, fields, parcels, carDelivery,
cashOnDelivery, cartAmount). [spec]

**`POST /v1/Order/status`** — `{orderId, status, orderComment}`. Used for
cancellation. Cancelling only works if no courier is assigned yet, or if the
provider allows cancellation through the integration; some providers require a
written request. Invalid status → `"Bogus is not valid status"` (400 in body). [docs][live]

**`DELETE /v1/Order`** — body `{orderId}` (schema `DeleteDemoOrderRequestModel`
also has bulk fields). Same no-courier restriction. [docs][spec]

**`GET /v1/Order/waybill?OrderId=`** — `{base64}` waybill (label) document. [spec]

**`GET /v1/Order/custom-fields`** — shop-defined custom fields
(`DeliveryCustomFieldModel`). A shop with none → `success:false, 404
"model not found."` [spec][live]

### Account / providers

- `GET /v1/Account` — `apiKey`, `googleMapKey`, `defaultGoogleMapKey`,
  `shopName`, `userName`, `balance` (e.g. `"0 ₾"` — a string), `hasProviders`. [spec][live]
  Handy "test connection" call for an admin button.
- `POST /v1/Account` — `{mapKey}` sets the shop's Google Maps key. [spec]
- `GET /v1/Provider/list` — providers on the account: `id`, `name`,
  `logoUrl`, `isActive`, `allowCashOnDelivery`, `isFake`, `providerType`
  (int enum 0–10, meanings undocumented). Sandbox: Wolt(2), Glovo(12), OnWay(25). [spec][live]
- `PUT /v1/Provider/status` `{id}` — toggles a provider on/off for the account. [spec]
- `GET|POST /v1/Provider/settings` — merchant's own Wolt/Glovo/Uber
  contracts (merchantId/venueId/keys) — contains secrets; mask in logs. [spec]
- Own-fleet: `/v1/Courier*`, `/v1/Order/couriers`, `/v1/Account/onboarding-courier`,
  `/v1/Account/transport-types` (Pedestrian 4, Bicycle 5, Scooter 2, Car 1,
  Combo 6, Truck 3). Out of scope for a carrier. [spec][live]

### Webhooks

- `POST /v1/Webhook` `{callBackUrl, maxRetryCount, type}` → `webhookId`.
- `PUT /v1/Webhook` `{id, maxRetryCount, isActive, type, callbackUrl}`.
- `GET /v1/Webhook` → `webhooks[]`.
- Types: `OrderStatusChange`, `CourierLocation`. [docs]
- **Payload format, signing/authentication, retry timing and expected
  response are NOT documented** — open question. Until known, treat webhook
  bodies as untrusted hints and re-read the order via `GET /v1/Order`.

## Enums

**OrderStatus** [docs]: `Draft` (created, not ready), `ReadyForPickup`,
`WaitingForCourier` (offer mode), `Assigned`, `Pickup` (courier took parcel),
`OnTheWay`, `Delivered`, `Cancelled`, `DeliveryFailed` (delivery or pickup failed).
Terminal (INFERRED): Delivered, Cancelled, DeliveryFailed.

**CustomFieldValueType**: ShortText, Numeric, DateTime, List, Checkbox, BigText.
**CustomFieldGroupType**: General, Parcel.
**CustomFieldType**: General, ParcelBarcode, PickupDateTime, DropoffDateTime,
ParcelName, ParcelVerification. [docs]
