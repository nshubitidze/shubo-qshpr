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

- Sandbox client: `DeliveryApiClient:DeliveryApiSecret` (public, [docs]).
  Production client: issued by Quickshipper, not published.
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
  merchants (see `open-questions.md`).
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
`ScheduleDelivery.ProviderId`, `ScheduleDelivery.ScheduledDate`.

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
reference — stored and shown in the dashboard, but not returned by the API;
`externalOrderId` is the courier's id [live 2026-10-01]), `dropThePin`,
`cashOnDelivery` {`deliveryPrice`, `parcelPrice`}, `cartAmount`, `cartWeight`.
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

**`POST /v1/Order/status`** — `{orderId, status, orderComment}`. Used for
cancellation. Cancelling only works if no courier is assigned yet, or if the
provider allows cancellation through the integration; some providers require a
written request. Invalid status → `"Bogus is not valid status"` (400 in body). [docs][live]

### Account

- `GET /v1/Account` — `apiKey`, `googleMapKey`, `defaultGoogleMapKey`,
  `shopName`, `userName`, `balance` (e.g. `"0 ₾"` — a string), `hasProviders`. [spec][live]

Endpoints the module does not call (order list and edit, delete, waybill,
weight brackets, custom fields, provider settings, own-fleet couriers) are
in the spec.

### Webhooks

- `POST /v1/Webhook` `{callBackUrl, maxRetryCount, type}` → `webhookId`.
- `PUT /v1/Webhook` `{id, maxRetryCount, isActive, type, callbackUrl}`.
- `GET /v1/Webhook` → `webhooks[]`.
- Types: `OrderStatusChange`, `CourierLocation`. [docs]
- Payload, signing and retries are not documented; what was observed is in
  `live-findings.md` (PascalCase body, no signature): treat a call as a hint
  and re-read the order with `GET /v1/Order`.

## Enums

**OrderStatus** [docs]: `Draft` (created, not ready), `ReadyForPickup`,
`WaitingForCourier` (offer mode), `Assigned`, `Pickup` (courier took parcel),
`OnTheWay`, `Delivered`, `Cancelled`, `DeliveryFailed` (delivery or pickup failed).
Final: Delivered, Cancelled, DeliveryFailed.
