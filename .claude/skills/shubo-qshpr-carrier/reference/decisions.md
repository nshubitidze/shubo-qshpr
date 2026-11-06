# Design decisions — and why

These are settled. Do not undo one as a side effect of another change; if a
change needs to, say so and get agreement first.

## Checkout and price

- **A native Magento carrier over Quickshipper's price API**, one shipping
  method per courier and speed — not Quickshipper's Delivery Button widget.
  Keeps Magento's checkout, order, shipment and tracking working as usual.
- **The checkout price is final for the customer.** The courier is booked
  later with a fresh price; a difference is the merchant's. The order
  comment shows both, so the merchant sees it.
- **Who pays Quickshipper's service fee is a setting** (default: the
  customer, i.e. Quickshipper's full price). Quickshipper's `amount` already
  includes the fee — adding `serviceFee` on top would count it twice.
- **No Magento handling fee, no free-shipping cart rules** on this carrier.
  A promotion meant for another carrier must not make paid courier
  deliveries free; the fee setting replaces the handling fee.
- **`CartAmount` is sent with every price request** — Quickshipper's own
  discount or fee-sharing rules may use it.
- **API timeout 20 s by default**: Quickshipper's first answer after a quiet
  period can take more than 10 s, and with a shorter limit checkout shows no
  Quickshipper option.

## Addresses

- **The module geocodes; Quickshipper does not.** Text-only addresses are
  priced and routed at 0,0.
- **Building-level answers in the typed city only** — a street-level point
  can be hundreds of metres off, and common street names exist in several
  cities; either would price and send a courier to the wrong place.
- **Swappable geocoders** registered in `di.xml`; the free public Nominatim
  server is the default (lowest cost for the merchant; a busy shop
  self-hosts). Paid providers (Google) are optional additions.
- **Address clean-up rewrites only the query**, never the order's address.
  On 228 Georgian test addresses it raised Nominatim's hit rate from 42% to
  81%.
- **Pickup** = the module's latitude/longitude fields + Magento's Shipping
  Origin (address text) + Store Information (name, phone).
- **Phones are sent as entered with `+995`**; the module does not change
  Magento's phone validation (Quickshipper removes a doubled prefix itself).

## Booking

- **Submit Shipment books the courier** (not the "Create Shipping Label"
  checkbox, not "save first, book later"): a failed booking leaves no
  shipment, no completed order and no "shipped" email.
- **Every shipment books its own courier** (partial shipments too).
- **A courier no longer offered at booking** stops the shipment with a
  message; nothing is booked.
- **Draft mode**: if the merchant's dashboard is set to "assign after
  confirmation", the Draft is confirmed at once. It is recorded *before* the
  confirm because a Draft can be neither cancelled nor deleted and its price
  is already held.
- **Reuse instead of rebooking**: a booking whose shipment was never saved is
  reused by the next shipment of the order; a cron cancels or reports
  bookings nobody comes back for.
- **One lock per order** around everything that books, confirms, cancels or
  updates.
- **A booking without an answer is never retried** — it may exist and be
  charged at Quickshipper.
- **Automatic booking** books on full payment, from a cron (a payment is
  never held up by Quickshipper), with retries only for "no answer before
  booking". Shipments created via REST or an ERP are not booked.
- **The tracking link is stored as Quickshipper returns it** (`trackingUrl`),
  not built from a base URL.
- **Instant couriers and courier hours are managed in the merchant's
  Quickshipper dashboard**, not in module settings.

## After booking

- **Status: webhook as a trigger + re-read, plus polling.** Webhooks are not
  signed, so their body is never trusted; the installation's webhook key
  keeps strangers from making the store call Quickshipper. Polling every 15
  minutes covers lost webhooks and stores without a public URL.
- **The webhook key travels in the `apikey` header** (2026-11-06):
  registered as the webhook's `apiKey`, which Quickshipper sends on every
  call — kept out of URLs, which end up in logs and in Quickshipper's
  webhook list. The URL copy (`?key=`) is still registered and accepted
  until the header is seen working in production, so webhooks connected
  before keep working; then it is dropped. **5 retries**, Quickshipper's
  maximum.
- **Delivered = a shipment note only**; cancelled or failed after shipping →
  merchant alerted, shipment kept (Magento cannot undo a shipment).
- **Cancel keeps the shipment and removes the track**, so the customer's
  Track link does not lead to a cancelled order. No customer emails.
- **Cancel on full refund is a setting, default off.** A paid order can only
  be refunded in Magento, so the refund is the trigger.
- **Cash on delivery**: cash payment hidden for couriers that cannot collect
  (unknown counts as cannot); the courier collects shipped goods + delivery.

## Product data and store

- **No module-created product attributes.** A store may already keep the
  data (e.g. for another shipping module). Features that need product data
  get a setting that picks one of the store's own attributes (car delivery
  does this).
- **Logos load from Quickshipper's servers** (CSP whitelisted); the admin
  header's Quickshipper mark is served from the module.
- **The admin balance is loaded after the page** and the session lock is
  released first: a slow Quickshipper never slows the admin.
- **`LogSanitizer` is extended through `di.xml`** (extra keys, body limit);
  built-in secrets are always masked.
- **HTTP status codes from `Laminas\Http\Response::STATUS_CODE_*`**, not
  module constants or bare numbers.
