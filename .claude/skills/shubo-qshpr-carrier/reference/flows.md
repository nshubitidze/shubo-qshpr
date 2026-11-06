# Flows

Quickshipper API details (fields, envelopes, statuses) are in the
`quickshipper-integration-skill`; this file says what the module does.

## 1. Checkout rates

`Model/Carrier/Qshpr::collectRates()` → `Model/Rates/RateQuoter::quote()`:

1. Base currency must be GEL, the pickup point complete — otherwise no
   Quickshipper option (log line `rates: …`).
2. `Geocoder::locate()` the street (first line) + city. Only a
   building-level answer inside the typed city counts; otherwise no option.
   Quickshipper does not geocode: an address sent as text only would be
   priced at 0,0.
3. `GET /v1/Order/fees` with pickup and drop-off street, city and
   coordinates, `CartAmount`, `CartWeight` (kg, if > 0) and
   `CarDelivery=true` when `CarDeliveryRule` says so. Cached 10 minutes per
   identical query.
4. One `Offer` per active courier and GEL price (cheapest first). Method code
   `<providerId>_<priceKey>` (e.g. `2_asap`; the price id lower-cased,
   alphanumeric), title `Courier – speed`. Price shown =
   `Offer::customerPrice()` (fee in or out, per setting); cost = full amount.
5. Each offer's logo is remembered per courier id (`CourierLogos`), because
   a Magento rate keeps only its standard fields.

Any failure (timeout, API error, bug) gives no Quickshipper option and
leaves the other carriers working. Luma re-asks prices when street or city
change (`shipping-rates-validation-rules.js`).

## 2. Order placed

`Observer/RecordDelivery` (`sales_model_service_quote_submit_success`) →
`DeliveryLog::recordQuote()`: courier, price key, drop-off coordinates (a
geocoder cache hit), paid shipping → row `quoted`. Never fails the order:
errors are logged and booking geocodes again. Multi-address checkout:
`Observer/RecordMultishippingDeliveries`. If the order is already paid (card
captured at checkout), `AutoDispatchTrigger` marks it due here.

## 3. Booking from Submit Shipment

`Plugin/ShipmentRegisterPlugin::afterRegister()` (admin shipment and invoice
"Create Shipment") → `Dispatcher::dispatch()`:

1. `ChosenCourier::fromOrder()` — not a Quickshipper order → nothing.
2. Take the order's `DeliveryLock`; if the items were shipped meanwhile
   (`ShippedQuantities`) → stop.
3. **Reuse** a booking that no shipment carries (`findUnlinkedBooking`):
   read it from Quickshipper; over (cancelled, failed, 404) → `abandoned`,
   book anew; a Draft → confirm; active → reuse, nothing new is paid.
4. **Book new**: an order paid in cash on delivery stops here (no
   Quickshipper call); drop-off point (recorded or geocoded), shipment
   weight, car rule, **fresh price** for the same courier (a checkout quote
   is not a reservation; the courier must still be offered, else the
   shipment stops), `OrderPayloadBuilder` → `POST /v1/Order`.
5. Quickshipper took the price → **record the booking first**
   (`drafted` or `dispatched`), then confirm a Draft
   (`POST /v1/Order/confirm`), then add the track (number + link) and an
   order comment with courier, number and prices.
6. `LinkShipment` writes `shipment_id` inside the save transaction;
   `ReleaseDeliveryLock` releases the lock after commit.

Everything before the booking is confirmed throws `DispatchException`: the
admin shows the message and Magento saves no shipment. After it, follow-up
failures are logged and the shipment is saved — except a confirmed Draft
whose `dispatched` mark cannot be written (the shipment stops; the next
attempt reuses the booking). A create request without an answer is never
retried: the message tells the merchant to check the dashboard first.

## 4. Automatic booking ("Book Courier Automatically")

`Observer/MarkPaidForDispatch` (`sales_order_invoice_pay`) and
`RecordDelivery` → `AutoDispatchTrigger::markIfPaid()` sets
`dispatch_due_at` when the order is paid in full. `Cron/AutoDispatch`
(every minute, lock wait 0) → `AutoShipper::ship()`: builds a shipment of
everything left to ship, books through the same `Dispatcher` **before** the
save, saves shipment + order, sends the shipment email. Retries only when
Quickshipper did not answer before booking: after 10 and 30 minutes; any
other failure or the third one stops it and tells the merchant (order
comment, admin notification, log). Cash orders are not paid, so they wait
for Ship.

## 5. Bookings left without a shipment

`Cron/CleanUpDeliveries` (every 10 min, 15 min grace): for each booked row
without `shipment_id`, Quickshipper's status decides — not yet with a
courier → cancel + `abandoned`; already cancelled/failed → `abandoned`;
Draft, courier assigned or cancel refused → merchant told once
(`alerted_at`), row kept for a retried shipment; cancel without answer →
read again next run.

## 6. Status updates

- **Webhook** `POST /qshpr/webhook/status?key=…` (`Controller/Webhook/Status`):
  the webhook key from the `apikey` header, else from the URL, checked with
  `hash_equals` (403); the log line says which (`key from header|url`).
  The body only names the `OrderId`. Not one of our rows → 200 without
  calling Quickshipper. Answers 200 / 400 / 403 / 503 (Quickshipper retries
  non-accepted calls up to 5 times, after 1, 5, 15, 30, 60 min — production;
  the sandbox sends once).
- **Polling** `Cron/StatusSync` every 15 min: shipped rows still under way,
  50 per run; no change for 7 days → merchant told once, polling stops.
- Both → `StatusUpdater::update()`: under the lock, `GET /v1/Order`; new
  status or courier → `recordStatus()`, a shipment note; Delivered /
  Cancelled / DeliveryFailed → final state; cancelled or failed → order
  comment + admin notification. Repeated or late calls change nothing.
- The **Connect** button (`WebhookRegistrar`) creates the installation's
  webhook key and registers `<base URL>qshpr/webhook/status?key=…` for
  `OrderStatusChange` with the key also as the webhook's `apiKey` and 5
  retries, or re-points the webhook registered for the same address
  (Quickshipper cannot delete webhooks).
- Magento's Track popup (`Qshpr::getTrackingInfo()`): link to Quickshipper's
  tracking page; the status goes into the carrier line because the popup
  shows a link or a status, not both.

## 7. Cancel, book again, refund

- **Cancel Quickshipper Delivery** (shipment page, while under way) →
  `Canceller`: reads the order first (Quickshipper cancels only before a
  courier has it), `POST /v1/Order/status` `Cancelled`, state `cancelled`,
  track removed, shipment kept. Outcomes in `CancelOutcome`.
- **Book Courier Again** (after cancelled or failed) → `Rebooker` →
  `Dispatcher::rebook()`: a new row linked to the same shipment. Replacing a
  cancelled delivery keeps the paid amount; replacing a failed one is an
  extra delivery (`paid_amount` 0).
- **Cancel Delivery on Full Refund** → `Observer/CancelOnRefund` after the
  credit memo commits: cancels deliveries still under way, reports ones a
  courier has, leaves delivered ones alone.

## 8. Cash on delivery

Quickshipper has no cash-on-delivery service (its answer of 2026-11-06),
so no Quickshipper courier collects money. `Observer/HideCashOnDelivery`
hides the cash payment methods (`CashOnDelivery::paymentMethods`, default
`cashondelivery`) whenever a Quickshipper courier is chosen; the courier's
`hasCashOnDelivery` flag is ignored. An order paid in cash anyway (placed
before this, or its payment changed) is not booked: Submit Shipment stops
with a message and the shipment is not saved. `cashOnDelivery` is never
sent.

## 9. Car delivery

`CarDeliveryRule`: car when any item's chosen Yes/No attribute is Yes, or
the weight reaches "Car Delivery from Weight", or "Car Delivery by Default"
is on. The same rule runs for checkout (cart items) and booking (shipment
items), so the booked courier is the kind that was quoted. Quickshipper then
offers only couriers with cars.

## 10. Admin balance

With "Show Balance in Admin Header" on, the header shows a dropdown loaded
after the page (`Controller/Adminhtml/Account/Balance`, session lock
released before the call) with the balance string Quickshipper returns
(never parsed), Test/Live, a top-up link to the dashboard and a settings
link. Account = the default store view's login.
