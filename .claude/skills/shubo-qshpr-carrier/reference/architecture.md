# Architecture

Every class opens with a docblock stating its role and invariants — read it
before changing the class. This file is the map.

## Where things are

| Area | Classes |
|---|---|
| Configuration | `Model/Carrier/Config` (the only code that knows config paths), `Model/Carrier/PickupPoint` (value object), `Model/Ui/ConfigProvider::CODE` (carrier code `shuboqshpr`, single source of truth) |
| Quickshipper API | `Model/QshprClient` (all HTTP; validates every answer shape, throws `QshprApiException`), `Model/Endpoint` (enum of operations + paths), `Model/TokenCache` (bearer token, encrypted in Magento's cache, at most a day), `Model/OrderStatus` (enum of Quickshipper order statuses), `Model/LogSanitizer` (masks secrets in logged payloads) |
| Geocoding | `Model/Geocoding/Geocoder` (picks the provider set in the admin, accepts only building-level answers in the typed city, caches), `ProviderInterface`, `NominatimProvider`, `AddressNormalizer` (Georgian registry-style street → what OpenStreetMap matches), `Address`, `Location`, `Precision` |
| Checkout rates | `Model/Carrier/Qshpr` (the carrier), `Model/Rates/RateQuoter` (address → `GET /v1/Order/fees` → offers, cached 10 min per trip), `Offer`, `CarDeliveryRule`, `CourierLogos`; `Plugin/ShippingMethodLogo` (logo URL as extension attribute `shubo_qshpr_logo_url`), `Plugin/QuoteItemCarDeliveryAttribute` |
| Delivery time and opening hours | `Model/Schedule/DeliverySlots` (slots, labels), `ScheduledCouriers` (which couriers take a time, cached from price answers), `OpeningHours` (next opening, closed days); `Plugin/ShippingMethodDeliveryTime`, `Plugin/SaveDeliveryTime`, `Model/Ui/DeliveryTimeConfigProvider`, `Model/Checkout/DeliveryTimeLayout`, `Observer/ShowDeliveryTime` |
| Cash on delivery | `Model/CashOnDelivery/CashOnDelivery` (which payment codes are cash), `Observer/HideCashOnDelivery` |
| Delivery record | `Model/Delivery/DeliveryLog` (the table, states, every query), `Booking` (value object), `ChosenCourier` (courier + price id read back from the order's shipping method), `OrderNotes`, `ShipmentNotes` |
| Booking | `Plugin/ShipmentRegisterPlugin` (admin Submit Shipment) → `Model/Dispatch/Dispatcher` (fresh price, create, Draft confirm, reuse, track), `OrderPayloadBuilder` (body of `POST /v1/Order`), `DeliveryLock`, `ShippedQuantities`; `Observer/LinkShipment`, `Observer/ReleaseDeliveryLock` |
| Automatic booking | `Observer/MarkPaidForDispatch` + `Observer/RecordDelivery` → `Model/Dispatch/AutoDispatchTrigger` (marks due) → `Cron/AutoDispatch` → `Model/Dispatch/AutoShipper` |
| Status | `Controller/Webhook/Status` (webhook), `Cron/StatusSync` (polling) → `Model/Status/StatusUpdater`; `Model/Status/WebhookRegistrar` + `Controller/Adminhtml/Webhook/Connect` + `Block/Adminhtml/System/Config/ConnectStatusUpdates` (the "Connect" button) |
| Cancel / again / refund | `Plugin/ShipmentViewButtons`, `Controller/Adminhtml/Delivery/Cancel` → `Model/Dispatch/Canceller` (+ `CancelOutcome` enum), `Controller/Adminhtml/Delivery/Rebook` → `Model/Dispatch/Rebooker` → `Dispatcher::rebook()`, `Observer/CancelOnRefund` |
| Bookings left without a shipment | `Cron/CleanUpDeliveries` |
| Admin balance | `ViewModel/AdminBalance`, `Model/Account/BalanceReader` (cached 5 min), `Controller/Adminhtml/Account/Balance` (loaded after the page), `view/adminhtml/templates/header/balance.phtml` |
| Helpers | `Model/Money` (tetri ↔ lari, the only converter), `Model/WeightConverter` (store weight unit → kg) |
| Exceptions | `Exception/QshprApiException`, `DispatchException` (message written for the merchant; has `retryable`), `GeocodingException`, `PickupNotConfiguredException` |

## Magento entry points

| Hook | Where | What |
|---|---|---|
| Carrier model | `etc/config.xml` `carriers/shuboqshpr/model` | `Model/Carrier/Qshpr` |
| `afterRegister` on `Magento\Sales\Model\Order\Shipment` | `etc/adminhtml/di.xml` | books the courier (admin only: shipments from REST or an ERP are not booked) |
| `beforeSetLayout` on `Magento\Shipping\Block\Adminhtml\View` | `etc/adminhtml/di.xml` | Cancel / Book Courier Again buttons |
| `afterModelToDataObject` on `Magento\Quote\Model\Cart\ShippingMethodConverter` | `etc/di.xml` (global: checkout asks through `webapi_rest`) | courier logo |
| `afterGetProductAttributes` on `Magento\Quote\Model\Quote\Config` | `etc/di.xml` | loads the merchant's car-delivery attribute with cart items |
| `sales_model_service_quote_submit_before` | `etc/events.xml` (global) | `ShowDeliveryTime` (time in the shipping description) |
| `sales_model_service_quote_submit_success` | `etc/events.xml` (global) | `RecordDelivery` |
| `beforeSaveAddressInformation` on `Magento\Checkout\Model\ShippingInformationManagement` | `etc/di.xml` | `SaveDeliveryTime` (checks the chosen time) |
| `checkout_submit_all_after` | `etc/events.xml` | `RecordMultishippingDeliveries` (multi-address checkout) |
| `sales_order_shipment_save_after` | `etc/events.xml` | `LinkShipment` (inside the save transaction) |
| `sales_order_shipment_save_commit_after` | `etc/events.xml` | `ReleaseDeliveryLock` |
| `sales_order_invoice_pay` | `etc/events.xml` | `MarkPaidForDispatch` |
| `sales_order_creditmemo_save_commit_after` | `etc/events.xml` | `CancelOnRefund` |
| `payment_method_is_active` | `etc/events.xml` | `HideCashOnDelivery` |
| Crons (`etc/crontab.xml`, group `default`) | `shubo_qshpr_auto_dispatch` every minute, `shubo_qshpr_clean_up_deliveries` every 10 min, `shubo_qshpr_status_sync` every 15 min |
| Routes | storefront `qshpr` (`/qshpr/webhook/status`), admin `shuboqshpr` |
| ACL | Cancel / Book again: `Magento_Sales::ship`; Connect: `Magento_Shipping::carriers`; balance: `AdminBalance::ACL_RESOURCE` |
| Checkout JS | `view/frontend/layout/checkout_index_index.xml` registers `shipping-rates-validation` (street and city changes re-ask prices); `requirejs-config.js` mixes `Magento_Checkout/js/view/shipping` with `js/mixin/shipping-courier-logos` (row template `template/shipping-method-item.html`) |
| CSP | `etc/csp_whitelist.xml`: `img-src` `*.quickshipper.app`, `*.quickshipper.ge` (courier logos) |
| Translations | `i18n/ka_GE.csv` (source strings are English) |

## The delivery table `shubo_qshpr_delivery`

One row per Quickshipper delivery (`etc/db_schema.xml` explains every
column). Amounts are tetri. No foreign keys: the row is the merchant's
record of what was booked and paid and outlives a deleted order or shipment.

Key columns: `order_id`, `increment_id`, `store_id` (picks the
Quickshipper login), `shipment_id` (null until the shipment is saved),
`provider_id` + `price_key` (the customer's choice), drop-off coordinates,
`paid_amount` (what the customer paid; 0 for an extra delivery),
`charged_amount` + `service_fee` (what Quickshipper took),
`qshpr_order_id` (unique) + `qshpr_order_no` (tracking number, e.g.
`SQ42609`) + `tracking_url`, `state`, `qshpr_status`, `courier_name`,
`status_changed_at` / `status_checked_at`, `alerted_at`, `scheduled_time`
(the delivery time booked, UTC; null = as soon as possible),
`dispatch_due_at` / `dispatch_attempts` / `last_error` (automatic booking).

### States (`DeliveryLog::STATE_*`) — forward only

```
quoted ──book──► drafted ──confirm──► dispatched ──status──► delivered
   │                 │                    │                  cancelled
   └──book──────────────────────────────►─┘                  failed
                     │                    │
                     └───── abandoned ◄───┘  (booked, never shipped, over at Quickshipper)
```

- `quoted` — order placed with a Quickshipper courier, not booked.
- `drafted` — Quickshipper holds a Draft (dashboard set to "assign after
  confirmation"); confirmed at once, so this lasts only if the confirm failed.
- `dispatched` — sent to the courier company.
- `abandoned` — booked, no shipment was ever saved, and Quickshipper no
  longer carries it out (cancelled, failed, not found).
- `delivered`, `cancelled`, `failed` — final; follow Quickshipper's status.

`BOOKED_STATES` = drafted, dispatched. Every transition method
(`markBooked`, `markConfirmed`, `markAbandoned`, `recordStatus`, …) puts the
allowed source states in the UPDATE's WHERE and returns whether a row moved,
so a repeated or racing update cannot move a row twice.

## Concurrency

`Model/Dispatch/DeliveryLock` — one database lock per Magento order
(`QSHPR_ORD_` + sha1, 15 s wait). Taken by the Dispatcher (held until the
shipment's save commits, released by `ReleaseDeliveryLock`, or by the end
of the request if the save fails), the clean-up cron, the status updater,
the canceller and automatic booking. Crons do not wait for it (0 s): a busy
order is handled next run. After waiting, the Dispatcher re-reads shipped
quantities (`ShippedQuantities`) because Magento validated the shipment
against the order it loaded before the wait.

## Caches (all in Magento's cache)

| What | Key prefix / tag | Lifetime |
|---|---|---|
| Bearer token (encrypted; key = HMAC of environment + credentials) | `shubo_qshpr_token_` (config cache tag) | ≤ 1 day |
| Prices per trip (key = HMAC of the whole query) | `shubo_qshpr_fees_` / `SHUBO_QSHPR_RATES` | 10 min |
| Courier logos | `shubo_qshpr_logo_` / `SHUBO_QSHPR_RATES` | 7 days; every price answer refreshes |
| Geocoder answers (key = HMAC of the query, no address text) | `shubo_qshpr_geocode_` / `SHUBO_QSHPR_GEOCODE` | found 30 days, not found 1 day, errors not cached |
| Balance per login | `shubo_qshpr_balance_` / `SHUBO_QSHPR_BALANCE` | 5 min, failures 1 min |

## Logging

Channel `ShuboQshprLogger` → `var/log/shubo_qshpr.log` (bound per class in
`etc/di.xml`). Every API call logs one summary line; errors always; full
masked payloads only with "Debug Logging" on. Line prefixes say the area:
`rates:`, `dispatch:`, `auto-dispatch:`, `status:`, `webhook:`,
`clean-up:`, `cancel:`, `refund:`.
