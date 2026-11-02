# Configuration

Admin: **Stores → Configuration → Sales → Delivery Methods → Quickshipper**.
Config node `carriers/shuboqshpr/*`. Fields are defined in
`etc/adminhtml/system.xml` (each has a merchant-facing comment), defaults in
`etc/config.xml`, and read **only** through `Model/Carrier/Config`.
Scope: D = default, W = website, S = store view.

## Connection

| Field | Default | Scope | Notes |
|---|---|---|---|
| `active` Enabled | 0 | D W | |
| `title` Title | Quickshipper | D W S | Carrier title above the courier options |
| `sandbox_mode` Sandbox Mode | 1 | D W | Picks the sandbox or production hosts, client and dashboard |
| `username` Login Email, `password` Password | — | D W | The merchant's Quickshipper dashboard login (OAuth password grant). Password encrypted, sensitive |
| `production_client_id`, `production_client_secret` | — | D W (production only) | Issued by Quickshipper. The sandbox client (`sandbox_client_id/secret`) is Quickshipper's **public** test client, set in `config.xml`, no admin field |
| `api_timeout` API Timeout (s) | 20 | D W | 0/empty → 20. Quickshipper's first answer after a quiet period can take >10 s |
| `debug` Debug Logging | 0 | D W | Full masked payloads in the log |
| `show_balance` Show Balance in Admin Header | 0 | D | Header dropdown with the prepaid balance, top-up and settings links |
| Hidden: `*_auth_url`, `*_api_url`, `*_dashboard_url` | Quickshipper's hosts | config.xml | Overridable with `bin/magento config:set` if Quickshipper moves |
| Hidden: `webhook_key` | generated | D | Created by the Connect button; encrypted, sensitive |

## Pickup point

| Field | Default | Notes |
|---|---|---|
| `pickup_latitude`, `pickup_longitude` | — | Where couriers collect. 0 counts as not set |
| Magento **Shipping Origin** street + city | — | Pickup address text |
| Magento **Store Information** name + phone | — | Pickup contact |

All six are required (`Config::getPickupPoint()` throws
`PickupNotConfiguredException`): without them Quickshipper is not offered
at checkout and the log says which is missing. The base currency must be
GEL.

## Address geocoding (group `geocoding`)

| Field | Default | Notes |
|---|---|---|
| `geocoding/geocoder` | `nominatim` | Options = providers registered in `etc/di.xml` |
| `geocoding/<code>_url`, e.g. `nominatim_url` | `https://nominatim.openstreetmap.org` | The public server allows ≤1 request/s and light use: busy shops should self-host |
| `geocoding/address_clean_up` | 1 | `AddressNormalizer` rewrites only the query text, never the order's address |

Provider settings follow `<code>_<setting>` and are read through
`Config::getGeocoderUrl()` (general getter), so adding a provider never
changes `Config`.

## Booking and following

| Field | Default | Scope | Notes |
|---|---|---|---|
| `auto_dispatch` Book Courier Automatically | 0 | D W | Fully paid orders are booked and shipped by the cron; unpaid (cash) orders wait for Ship |
| `cancel_on_refund` Cancel Delivery on Full Refund | 0 | D W | |
| `connect_status_updates` (button) | — | D W | Registers / re-points the status webhook for this store's URL |

## Checkout

| Field | Default | Scope | Notes |
|---|---|---|---|
| `show_logos` Show Courier Logos at Checkout | 1 | D W S | Images load from Quickshipper's servers (CSP whitelisted) |
| `customer_pays_service_fee` Customer Pays Quickshipper's Service Fee | 1 | D W | Yes: customer sees Quickshipper's full `amount` (already includes the fee). No: `amount − serviceFee` (never below 0). The rate's cost is always `amount` |
| `car_delivery_attribute` Car Delivery Attribute | — | D | One of the store's own Yes/No product attributes (`YesNoProductAttribute` lists only user-defined ones) |
| `car_delivery_default` Car Delivery by Default | 0 | D W | |
| `car_delivery_from_weight` Car Delivery from Weight (kg) | — | D W | Empty = not used |
| `sallowspecific`, `specificcountry` | 1, GE | D W | |
| `sort_order` | 10 | D W | |

Magento's handling fee and free-shipping cart rules are deliberately not
applied (see `decisions.md`).

## Extension points in `etc/di.xml` (no settings)

- `Geocoder` → `providers`: `<item name="<code>">` with `label` + `model`.
- `CashOnDelivery` → `paymentMethods`: payment method codes that mean "pay
  the courier in cash" (default `cashondelivery`).
- `LogSanitizer` → extra masked keys and the body limit (the built-in
  secrets cannot be removed).
- `NominatimProvider` → `timeoutSeconds` (5).
