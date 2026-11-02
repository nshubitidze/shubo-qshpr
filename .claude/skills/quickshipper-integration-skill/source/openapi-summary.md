# OpenAPI summary (sandbox spec, fetched 2026-09-29)

Generated from `openapi-sandbox.json` (https://delivery-test.quickshipper.ge/swagger/v1/swagger.json). The production spec (https://delivery.quickshipper.app/swagger/v1/swagger.json) has the same operations; only a few provider-settings fields differ (`needsCashOnDelivery`, `checkoutLabel`, `needsIdCheck` exist only in sandbox).

### GET /v1/Account — Account details
- 200: OK ['DeliveryApiAccountResponseModel']
- security: [{}]

### POST /v1/Account — Account details
- body (application/json): EditDeliveryApiAccountRequestModel
- 200: OK ['BaseResponseModel']
- security: [{}]

### POST /v1/Account/onboarding-courier — On-boarding courier info
- 200: OK ['ShopCourierCreateResponseModel']
- security: [{}]

### GET /v1/Account/transport-types — List of available transport types for couriers
- 200: OK ['BaseListResponseModel`1']
- security: [{}]

### POST /v1/Courier — Create courier
- body (application/json): CreateCourierRequestModel
- 200: OK ['BaseResponseModel']
- security: [{}]

### GET /v1/Courier — Get couriers
- 200: OK ['CouriersListResponseModel']
- security: [{}]

### PUT /v1/Courier — Edit courier profile
- body (application/json): EditCourierRequestModel
- 200: OK ['BaseResponseModel']
- security: [{}]

### GET /v1/Courier/{courierId} — Get courier by id
- param `courierId` in path: integer(int32) REQUIRED 
- 200: OK ['GetCourierResponseModel']
- security: [{}]

### POST /v1/Order — Place order
- body (application/json): OrderPlaceRequestModel
- 200: OK ['OrderPlaceResponseModel']
- security: [{}]

### GET /v1/Order — Get order info
- param `OrderId` in query: integer(int32) 
- 200: OK ['OrderInfoResponseModel']
- security: [{}]

### PUT /v1/Order — Edit order
- body (application/json): EditOrderRequestModel
- 200: OK ['BaseResponseModel']
- security: [{}]

### DELETE /v1/Order — Delete order
- body (application/json): DeleteDemoOrderRequestModel
- 200: OK ['BaseResponseModel']
- security: [{}]

### GET /v1/Order/list — Get paginated list of orders
- param `FromDate` in query: string(date-time) 
- param `ToDate` in query: string(date-time) 
- param `Status` in query: string 
- param `Limit` in query: integer(int32) 
- param `Page` in query: integer(int32) 
- 200: OK ['OrdersListResponseModel']
- security: [{}]

### GET /v1/Order/fees — Provider delivery fees
- param `FromStreetName` in query: string 
- param `FromCityName` in query: string 
- param `FromLatitude` in query: number(double) 
- param `FromLongitude` in query: number(double) 
- param `ToStreetName` in query: string 
- param `ToCityName` in query: string 
- param `ToLatitude` in query: number(double) 
- param `ToLongitude` in query: number(double) 
- param `ScheduleDelivery.ProviderId` in query: integer(int32) 
- param `ScheduleDelivery.ScheduledDate` in query: string(date-time) 
- param `CarDelivery` in query: boolean 
- param `CartAmount` in query: number(double) 
- param `CartWeight` in query: number(double) 
- 200: OK ['ProviderDeliveryFeeResponseModelDeliveryApi']
- security: [{}]

### GET /v1/Order/custom-fields — List of custom fields, defined on shop
- 200: OK ['GetCustomFieldsResponseModel']
- security: [{}]

### GET /v1/Order/weights — List of weights and prices
- param `FromLatitude` in query: number(double) 
- param `FromLongitude` in query: number(double) 
- param `ToLatitude` in query: number(double) 
- param `ToLongitude` in query: number(double) 
- param `ProviderId` in query: integer(int32) 
- param `DeliverySpeedId` in query: integer(int32) 
- param `CartAmount` in query: number(double) 
- 200: OK ['WeightsResponseModel']
- security: [{}]

### POST /v1/Order/status — Change order status
- body (application/json): ChangeStatusRequestModel
- 200: OK ['BaseResponseModel']
- security: [{}]

### GET /v1/Order/express — Gets url of express-quickshipper for express load in iframe or modal
- param `DropOffInfo.Address` in query: string 
- param `DropOffInfo.Longitude` in query: number(double) 
- param `DropOffInfo.Latitude` in query: number(double) 
- param `DropOffInfo.AddressComment` in query: string 
- param `DropOffInfo.Name` in query: string 
- param `DropOffInfo.PhonePrefix` in query: string 
- param `DropOffInfo.Phone` in query: string 
- param `DropOffInfo.City` in query: string 
- param `PickUpInfo.Address` in query: string 
- param `PickUpInfo.Longitude` in query: number(double) 
- param `PickUpInfo.Latitude` in query: number(double) 
- param `PickUpInfo.AddressComment` in query: string 
- param `PickUpInfo.Name` in query: string 
- param `PickUpInfo.PhonePrefix` in query: string 
- param `PickUpInfo.Phone` in query: string 
- param `PickUpInfo.City` in query: string 
- param `ApiKey` in query: string REQUIRED 
- param `Lang` in query: string 
- param `CarDelivery` in query: boolean 
- param `CartWeight` in query: number(double) 
- 200: OK ['GetExpressQuickshipperResponseModel']
- security: [{}, {}]

### POST /v1/Order/confirm — Confirms order to provider and changes status to ReadyForPickup. Is used in Express Quickshipper (a.k.a DeliveryButton). Accepts either an ApiKey in the body or a bearer access token
- body (application/json): ConfirmOrderRequestModel
- 200: OK ['OrderPlaceResponseModel']
- security: [{}, {}]

### GET /v1/Order/couriers — Courier accounts list
- 200: OK ['GetDeliveryApiCouriersResponseModel']
- security: [{}]

### GET /v1/Order/waybill — Gives you a base64 of waybill for certein order
- param `OrderId` in query: integer(int32) 
- 200: OK ['OrdersExportModel']
- security: [{}]

### GET /v1/Provider/list — List of available providers for logged in account
- 200: OK ['GetProvidersResponseModel']
- security: [{}]

### PUT /v1/Provider/status — Toggle provider's status on account
- body (application/json): BaseIdRequestModel
- 200: OK ['BaseResponseModel']
- security: [{}]

### POST /v1/Provider/settings — Add provider's information on account
- body (application/json): AddProviderInfoRequestModel
- 200: OK ['BaseResponseModel']
- security: [{}]

### GET /v1/Provider/settings — List provider settings
- 200: OK ['ListProviderInfoResponseModel']
- security: [{}]

### POST /v1/Webhook — create new webhook
- body (application/json): RegisterWebhookRequestModel
- 200: OK ['RegisterWebhookResponseModel']
- security: [{}]

### GET /v1/Webhook — Get all webhooks
- 200: OK ['WebhookResponseModel']
- security: [{}]

### PUT /v1/Webhook — Edit webhook
- body (application/json): EditWebhookRequestModel
- 200: OK ['BaseResponseModel']
- security: [{}]

## Schemas

**AddProviderInfoRequestModel**
- `wolt`: WoltInfoModel
- `glovo`: GlovoInfoModel
- `uber`: UberInfoModel
- `vat`: number(double)?
- `needsCashOnDelivery`: boolean

**BaseIdNameModel**
- `id`: integer(int32)
- `name`: string?

**BaseIdRequestModel**
- `id`: integer(int32)

**BaseListResponseModel`1**
- `httpStatusCode`: integer(int32)
- `userMessage`: string?
- `developerMessage`: string?
- `success`: boolean
- `errors`: string[]
- `items`: BaseIdNameModel[]
- `itemsCount`: integer(int32)
- `hasItems`: boolean

**BaseResponseModel**
- `httpStatusCode`: integer(int32)
- `userMessage`: string?
- `developerMessage`: string?
- `success`: boolean
- `errors`: string[]

**CashOnDeliveryModel**
- `deliveryPrice`: number(double)?
- `parcelPrice`: number(double)?

**ChangeStatusRequestModel**
- `orderId`: integer(int32)
- `status`: string?
- `orderComment`: string?

**ConfirmOrderRequestModel**
- `orderId`: integer(int32) REQUIRED
- `apiKey`: string? — Only required when the request is not authenticated with a bearer token.

**CourierDeliveryApiModel**
- `id`: integer(int32)
- `externalId`: string?
- `userName`: string?
- `firstName`: string?
- `lastName`: string?
- `status`: string?
- `isActive`: boolean
- `createDate`: string(date-time)
- `phonePrefix`: string?
- `phoneNumber`: string?
- `email`: string?

**CourierOrderCustomFieldValueModel**
- `name`: string?
- `value`: string?
- `type`: string?
- `valueType`: string?
- `canEdit`: boolean
- `valueId`: integer(int32)
- `showInOrdersList`: boolean

**CouriersListResponseModel**
- `httpStatusCode`: integer(int32)
- `userMessage`: string?
- `developerMessage`: string?
- `success`: boolean
- `errors`: string[]
- `items`: CourierDeliveryApiModel[]

**CreateCourierRequestModel**
- `firstName`: string?
- `lastName`: string?
- `email`: string?
- `phonePrefix`: string?
- `phoneNumber`: string?
- `externalId`: string?
- `transportType`: string?
- `maxVolume`: number(double)?
- `maxWeight`: number(double)?
- `minWeightPerOrder`: number(double)?
- `minVolumePerOrder`: number(double)?

**CustomFieldModel**
- `id`: integer(int32)
- `valueId`: integer(int32)?
- `name`: string?
- `isOptional`: boolean
- `description`: string?
- `placeholder`: string?
- `listValues`: string[]
- `type`: string?
- `valueType`: string?
- `value`: string?

**CustomFieldsForEdit**
- `id`: integer(int32)
- `value`: string?
- `valueId`: integer(int32)?
- `type`: string?

**DeleteDemoOrderRequestModel**
- `orderId`: integer(int32)
- `orderIds`: integer(int32)[]
- `all`: boolean
- `districtIds`: string?
- `search`: string?
- `dateFrom`: string(date-time)?
- `dateTo`: string(date-time)?
- `clientShopIds`: string?
- `weight`: string?
- `parcelsQuantity`: integer(int32)?

**DeliveryApiAccountResponseModel**
- `httpStatusCode`: integer(int32)
- `userMessage`: string?
- `developerMessage`: string?
- `success`: boolean
- `errors`: string[]
- `apiKey`: string?
- `googleMapKey`: string?
- `defaultGoogleMapKey`: string?
- `shopName`: string?
- `shopLogo`: string?
- `courierLogedIn`: boolean
- `hasProviders`: boolean
- `userName`: string?
- `balance`: string?

**DeliveryApiCourierModel**
- `userName`: string?
- `name`: string?
- `imageUrl`: string?
- `transportTypeId`: integer(int32)?

**DeliveryCustomFieldModel**
- `id`: integer(int32)
- `name`: string?
- `isOptional`: boolean
- `description`: string?
- `placeholder`: string?
- `listValues`: string[]
- `type`: string?
- `valueType`: string?

**DetailsCustomerInfo**
- `name`: string?
- `phoneNumber`: string?
- `phonePrefix`: string?

**EditCourierRequestModel**
- `courierId`: integer(int32)?
- `email`: string?
- `phonePrefix`: string?
- `phone`: string?
- `firstName`: string?
- `lastName`: string?
- `externalId`: string?
- `transportType`: string?
- `isDeleted`: boolean

**EditDeliveryApiAccountRequestModel**
- `mapKey`: string?

**EditOrderRequestModel**
- `orderId`: integer(int32)
- `scheduledTime`: string(date-time)?
- `courier`: OrderPlaceCourierModel
- `provider`: OrderDeliveryProviderModel
- `pickUpInfo`: OrderAddressModel
- `dropOffInfo`: OrderAddressModel
- `comment`: string?
- `generalFields`: CustomFieldsForEdit[]
- `parcels`: ParcelForEdit[]
- `carDelivery`: boolean
- `cashOnDelivery`: CashOnDeliveryModel
- `cartAmount`: number(double)?

**EditWebhookRequestModel**
- `id`: integer(int32)
- `maxRetryCount`: integer(int32)?
- `isActive`: boolean?
- `type`: string?
- `callbackUrl`: string?

**GetCourierResponseModel**
- `httpStatusCode`: integer(int32)
- `userMessage`: string?
- `developerMessage`: string?
- `success`: boolean
- `errors`: string[]
- `courierId`: integer(int32)
- `email`: string?
- `firstName`: string?
- `lastName`: string?
- `phoneNumber`: string?
- `phonePrefix`: string?
- `status`: string?

**GetCustomFieldsResponseModel**
- `httpStatusCode`: integer(int32)
- `userMessage`: string?
- `developerMessage`: string?
- `success`: boolean
- `errors`: string[]
- `items`: DeliveryCustomFieldModel[]

**GetDeliveryApiCouriersResponseModel**
- `httpStatusCode`: integer(int32)
- `userMessage`: string?
- `developerMessage`: string?
- `success`: boolean
- `errors`: string[]
- `items`: DeliveryApiCourierModel[]

**GetExpressQuickshipperResponseModel**
- `httpStatusCode`: integer(int32)
- `userMessage`: string?
- `developerMessage`: string?
- `success`: boolean
- `errors`: string[]
- `url`: string?

**GetProvidersItemModel**
- `id`: integer(int32)
- `name`: string?
- `logoUrl`: string?
- `isActive`: boolean
- `allowCashOnDelivery`: boolean
- `isFake`: boolean
- `providerType`: ProviderType

**GetProvidersResponseModel**
- `httpStatusCode`: integer(int32)
- `userMessage`: string?
- `developerMessage`: string?
- `success`: boolean
- `errors`: string[]
- `items`: GetProvidersItemModel[]

**GlovoInfoModel**
- `apiKey`: string?
- `apiSecret`: string?
- `checkoutLabel`: string?

**GlovoSettingModel**
- `isEmpty`: boolean
- `isPending`: boolean
- `logoUrl`: string?
- `name`: string?
- `settings`: GlovoInfoModel

**ListProviderInfoResponseModel**
- `httpStatusCode`: integer(int32)
- `userMessage`: string?
- `developerMessage`: string?
- `success`: boolean
- `errors`: string[]
- `wolt`: WoltSettingModel
- `glovo`: GlovoSettingModel
- `uber`: UberSettingModel

**OrderAddressModel**
- `address`: string?
- `longitude`: number(double)?
- `latitude`: number(double)?
- `addressComment`: string?
- `name`: string?
- `phonePrefix`: string?
- `phone`: string?
- `city`: string?
- `country`: string?

**OrderCommentModel**
- `author`: string?
- `comment`: string?
- `createDate`: string(date-time)

**OrderCourierModel**
- `courierId`: integer(int32)
- `courierName`: string?
- `courierPhoneNumber`: string?
- `courierImage`: string?
- `transportType`: string?
- `status`: string?
- `courierExternalId`: string?

**OrderCustomFieldModel**
- `id`: integer(int32)
- `value`: string?
- `type`: string?

**OrderDeliveryProviderModel**
- `providerId`: integer(int32)
- `providerFeeId`: string?
- `deliverySpeedId`: integer(int32)?
- `preparationTime`: integer(int32)? — Time in minutes. Currently is used for Wolt only.
- `parcelDimensionId`: integer(int32)?
- `parcelDimensions`: ParcelDimensionModel[]
- `parcelsQuantity`: integer(int32)?

**OrderDropOffModel**
- `address`: string?
- `longitude`: number(double)
- `latitude`: number(double)
- `addressComment`: string?
- `name`: string?
- `phonePrefix`: string?
- `phone`: string?
- `city`: string?
- `country`: string?

**OrderInfoModel**
- `id`: integer(int32)
- `status`: string?
- `approximateDeliveryMin`: integer(int32)?
- `orderNo`: string?
- `orderPrice`: number(double)
- `serviceFee`: number(double)
- `deliveryType`: string?
- `toAddressComment`: string?
- `fromAddressComment`: string?
- `addressFrom`: string?
- `addressTo`: string?
- `fromLongitude`: number(double)
- `fromLatitude`: number(double)
- `toLongitude`: number(double)
- `toLatitude`: number(double)
- `distance`: number(double)
- `comment`: string?
- `deliveryStartDate`: string(date-time)?
- `scheduledTime`: string(date-time)?
- `createDate`: string(date-time)
- `statusLogs`: OrderStatusLogs[]
- `generalCustomFields`: CourierOrderCustomFieldValueModel[]
- `parcelCustomFields`: ParcelCustomFieldValueModel[]
- `trackingUrl`: string?
- `courierComment`: string?
- `orderComments`: OrderCommentModel[]
- `images`: string[]
- `channel`: string?
- `externalOrderId`: string?

**OrderInfoResponseModel**
- `httpStatusCode`: integer(int32)
- `userMessage`: string?
- `developerMessage`: string?
- `success`: boolean
- `errors`: string[]
- `courier`: OrderCourierModel
- `customer`: DetailsCustomerInfo
- `provider`: OrderProviderModel
- `order`: OrderInfoModel
- `shop`: OrderShopModel

**OrderListItemModel**
- `id`: integer(int32)
- `orderNo`: string?
- `status`: string?
- `createDate`: string(date-time)
- `addressFrom`: string?
- `addressTo`: string?
- `customerName`: string?
- `customerPhone`: string?
- `externalOrderId`: string?
- `trackingUrl`: string?

**OrderParcelModel**
- `fields`: OrderCustomFieldModel[] — ისეთი ველები, რომლებიც ამანათზე ეთითება

**OrderPickUpModel**
- `address`: string?
- `longitude`: number(double)
- `latitude`: number(double)
- `addressComment`: string?
- `name`: string?
- `phonePrefix`: string?
- `phone`: string?
- `city`: string?
- `country`: string?

**OrderPlaceCourierModel**
- `userName`: string?
- `detachCourier`: boolean

**OrderPlaceRequestModel**
- `carDelivery`: boolean
- `comment`: string?
- `parcelDimensionId`: integer(int32)?
- `scheduledTime`: string(date-time)?
- `dropOffInfo`: OrderDropOffModel
- `pickUpInfo`: OrderPickUpModel
- `provider`: OrderDeliveryProviderModel
- `courier`: OrderPlaceCourierModel
- `parcels`: OrderParcelModel[]
- `generalFields`: OrderCustomFieldModel[]
- `autoAssign`: boolean
- `courierTransportType`: integer(int32)?
- `integrationOrderId`: string?
- `dropThePin`: boolean
- `cashOnDelivery`: CashOnDeliveryModel
- `cartAmount`: number(double)?
- `cartWeight`: number(double)?

**OrderPlaceResponseModel**
- `httpStatusCode`: integer(int32)
- `userMessage`: string?
- `developerMessage`: string?
- `success`: boolean
- `errors`: string[]
- `orderId`: integer(int32)
- `orderStatus`: string?
- `trackingUrl`: string?
- `deliveryTime`: string?
- `deliveryFee`: number(double)
- `comment`: string?
- `toAddress`: string?
- `toAddressComment`: string?
- `toLatitude`: number(double)
- `toLongitude`: number(double)
- `providerLogoUrl`: string?
- `providerName`: string?
- `createDate`: string(date-time)?
- `fromAddress`: string?

**OrderProviderModel**
- `providerId`: integer(int32)
- `providerName`: string?
- `logoUrl`: string?
- `note`: string?

**OrderShopModel**
- `shopPhoneNumber`: string?
- `shopAddressName`: string?

**OrderStatusLogs**
- `status`: string?
- `createDate`: string(date-time)?

**OrdersExportModel**
- `httpStatusCode`: integer(int32)
- `userMessage`: string?
- `developerMessage`: string?
- `success`: boolean
- `errors`: string[]
- `base64`: string?

**OrdersListResponseModel**
- `httpStatusCode`: integer(int32)
- `userMessage`: string?
- `developerMessage`: string?
- `success`: boolean
- `errors`: string[]
- `items`: OrderListItemModel[]
- `itemsCount`: integer(int32)
- `hasItems`: boolean
- `page`: integer(int32)
- `limit`: integer(int32)

**ParcelCustomFieldValueModel**
- `parcelId`: integer(int32)
- `title`: string?
- `values`: string?
- `isPickupConfirmed`: boolean?
- `isDropoffConfirmed`: boolean?
- `isProblematicPickup`: boolean
- `isProblematicDropoff`: boolean
- `pickupComment`: string?
- `dropoffComment`: string?

**ParcelDimensionModel**
- `length`: number(double)?
- `width`: number(double)?
- `height`: number(double)?
- `weight`: number(double)?

**ParcelForEdit**
- `id`: integer(int32)?
- `fields`: CustomFieldsForEdit[]

**ProviderDeliveryFeeModel**
- `providerName`: string?
- `providerCode`: string?
- `providerLogoUrl`: string?
- `providerId`: integer(int32)
- `providerNote`: string?
- `isActive`: boolean
- `prices`: ProviderDeliveryFeePriceModel[]
- `generalFields`: CustomFieldModel[]
- `discountPercent`: number(double)?
- `serviceFee`: number(double)
- `serviceFeeCurrency`: string?
- `showBarcodeWarning`: boolean
- `hasCashOnDelivery`: boolean
- `hasCarDelivery`: boolean
- `hasScheduledDelivery`: boolean
- `minWeightForCarDelivery`: number(double)
- `inHouseProvider`: boolean
- `minPrice`: number(double)
- `hasWeight`: boolean

**ProviderDeliveryFeePriceModel**
- `amount`: number(double)
- `oldAmount`: number(double)?
- `currency`: string?
- `currencyName`: string?
- `deliverySpeedId`: integer(int32)?
- `deliverySpeedName`: string?
- `deliverySpeedDescription`: string?
- `deliveryTime`: string(date-time)?
- `id`: string?
- `hasCarDelivery`: boolean

**ProviderDeliveryFeeResponseModelDeliveryApi**
- `httpStatusCode`: integer(int32)
- `userMessage`: string?
- `developerMessage`: string?
- `success`: boolean
- `errors`: string[]
- `fees`: ProviderDeliveryFeeModel[]
- `distance`: number(double)

**ProviderType** enum (integer): [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10]

**RegisterWebhookRequestModel**
- `callBackUrl`: string?
- `maxRetryCount`: integer(int32)
- `type`: string?

**RegisterWebhookResponseModel**
- `httpStatusCode`: integer(int32)
- `userMessage`: string?
- `developerMessage`: string?
- `success`: boolean
- `errors`: string[]
- `webhookId`: integer(int32)

**ShopCourierCreateResponseModel**
- `httpStatusCode`: integer(int32)
- `userMessage`: string?
- `developerMessage`: string?
- `success`: boolean
- `errors`: string[]
- `courierId`: integer(int32)
- `userName`: string?
- `password`: string?

**UberInfoModel**
- `customerId`: string?
- `clientId`: string?
- `clientSecret`: string?

**UberSettingModel**
- `isEmpty`: boolean
- `isPending`: boolean
- `logoUrl`: string?
- `name`: string?
- `settings`: UberInfoModel

**WebhookModel**
- `id`: integer(int32)
- `callBackUrl`: string?
- `isActive`: boolean
- `type`: string?
- `maxRetryCount`: integer(int32)

**WebhookResponseModel**
- `httpStatusCode`: integer(int32)
- `userMessage`: string?
- `developerMessage`: string?
- `success`: boolean
- `errors`: string[]
- `webhooks`: WebhookModel[]

**WeightPricesModel**
- `userPrice`: number(double)
- `oldUserPrice`: number(double)?
- `displayName`: string?
- `parcelDimensionsId`: integer(int32)
- `minWeight`: number(double)?
- `maxWeight`: number(double)?

**WeightsResponseModel**
- `httpStatusCode`: integer(int32)
- `userMessage`: string?
- `developerMessage`: string?
- `success`: boolean
- `errors`: string[]
- `items`: WeightPricesModel[]
- `itemsCount`: integer(int32)
- `hasItems`: boolean
- `deliverySpeedId`: integer(int32)?

**WoltInfoModel**
- `merchantId`: string?
- `venueId`: string?
- `merchantKey`: string?
- `needsIdCheck`: boolean
- `checkoutLabel`: string?

**WoltSettingModel**
- `isEmpty`: boolean
- `isPending`: boolean
- `logoUrl`: string?
- `name`: string?
- `settings`: WoltInfoModel
