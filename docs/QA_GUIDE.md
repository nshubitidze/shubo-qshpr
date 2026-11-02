# QA guide — verifying a change to Shubo_Qshpr

How to check that a change works, on a local Magento 2.4.9 store with the
module installed under `app/code/Shubo/Qshpr` (or `vendor/shubo/module-qshpr`).
Run every PHP command inside the store's PHP environment, from the Magento
root. Paths below use `<module>` for the module directory.

## 1. Ground rules

- **Sandbox only.** Use a Quickshipper **test** account (test.quickshipper.ge)
  with "Sandbox Mode" = Yes. Never test against a live account.
- **Creating anything at Quickshipper costs money**, even in the sandbox: the
  test balance is funded by a real card payment and every order costs the
  service fee, also when cancelled. Price requests, account reads and order
  reads are free. Tests that would create an order or a webhook stay
  **offline** (stub client, or the API URL pointed at an unreachable host)
  unless the person responsible for the account agrees.
- Test the **logic**, not only the gates: reproduce the case on the store
  without the change, then show the change fixes the same case, and say how
  likely the case is and what it costs when it happens.
- Admin browser tests log in with a separate admin user: Magento allows one
  session per admin user, so a test login ends a person's session.

## 2. Store setup (once per store)

- Base currency GEL, default country GE.
- Quickshipper enabled with the sandbox login, Pickup Latitude/Longitude
  (e.g. 41.6934 / 44.8015, Tbilisi), Shipping Origin street + city in
  Tbilisi, Store Information name + phone.
- Optional: disable Flat Rate / Table Rate so checkout shows only Quickshipper.
- Test parcels: `php <module>/dev/checks/create-test-products.php` — category
  "Quickshipper Test" with 1–12 kg products (prices change with weight);
  reindex or wait for cron.
- The suites' live sections read the sandbox login from env
  `QSHPR_USERNAME` / `QSHPR_PASSWORD`, else from an untracked `.qshpr.env` in
  the Magento root (`chmod 600`). Never commit it.

## 3. Gates (must be green)

```
vendor/bin/phpcs --standard=Magento2 --extensions=php,phtml \
    --ignore='*/dev/*,*/Test/*' <module>          # 0 errors; fix warnings on changed lines
vendor/bin/phpstan analyse -l 2 <module>          # with bitexpert/phpstan-magento; dev/ and Test/ excluded
bin/magento setup:di:compile                       # after clearing generated/code and generated/metadata
```

Code style: PSR-12 plus the rules in `.claude/rules/module-standard.md`.
After a schema change also run `bin/magento setup:upgrade`.

## 4. Check suites (`dev/checks/`)

Plain PHP scripts that bootstrap Magento. Each prints `PASS`/`FAIL` lines and
ends with `ALL PASS` or the number of failures. Stand-ins are anonymous
classes (a named class would be executed by `setup:di:compile`).
`QSHPR_OFFLINE=1` skips every live section: nothing is sent to Quickshipper.

```
QSHPR_OFFLINE=1 php <module>/dev/checks/qshpr-<suite>-check.php
```

| Suite | Covers | Live part (free, read-only) |
|---|---|---|
| `client` | `Config`, `TokenCache`, `QshprClient`: every answer shape, token cache, 401 retry, transport failure | login, account, fees |
| `geocoder` | `AddressNormalizer` (incl. 228 Georgian addresses in `fixtures/`), `NominatimProvider`, `Geocoder` (precision, city, cache) | 8 lookups on public Nominatim, 1.1 s apart |
| `carrier` | carrier + `RateQuoter`: rates, fee setting, no free shipping, cache, every "not offered" path; logos; cash on delivery; car delivery | Tbilisi rates, Batumi intercity none |
| `dispatch` | booking through the real admin path (`Shipment::register()` → save) over a stub client, in a rolled-back transaction: normal, Draft confirm, refused confirm, reuse, lock, clean-up cron, automatic booking | — |
| `status` | webhook, status updater, polling cron, stale deliveries, cancel, book again, buttons, cancel on refund — stub client, rolled back | — |
| `balance` | `BalanceReader`, `AdminBalance` | one account read |

`dispatch` and `status` need one local order placed with a Quickshipper
courier that still has items to ship (the browser test below makes one);
they change nothing — everything runs in a transaction that is rolled back.

Which to run: the suites of the area you changed, and `dispatch` + `status`
for anything in `Model/Dispatch`, `Model/Delivery`, `Model/Status`, `Cron`
or `Observer`. Before a release: all six.

Suites write to `var/log/shubo_qshpr.log` and check their own lines; their
stub orders (`SQ7770…`) show up there.

## 5. Browser tests (`dev/qa-e2e/`)

Playwright in Docker (`run.sh` pulls the pinned image; `node_modules` stays
in that folder). `BASE_URL` is the store's URL; its hostname is mapped to
127.0.0.1 inside the container.

| Test | What | Cost |
|---|---|---|
| `checkout-rates.js` | guest checkout: rates appear for a Tbilisi address, change with the street, vanish for Batumi; places an order with Check / Money order (`RATES_ONLY=1`: stop after the rates; `COURIER=<id>`; `LOGOS=0`; `COD=hidden\|shown`) | free (no booking) |
| `admin-balance.js` | balance in the admin header | free |
| `admin-ship.js` | Ship → Submit Shipment as a merchant | **books a real sandbox courier** |
| `admin-shipment-button.js` | Cancel / Book Courier Again on a shipment | **acts at Quickshipper** |

```
BASE_URL=http://localhost PRODUCT_ID=<id> <module>/dev/qa-e2e/run.sh checkout-rates.js
BASE_URL=… ADMIN_PATH=<admin path> ADMIN_USER=<test admin> ADMIN_PASSWORD=… <module>/dev/qa-e2e/run.sh admin-balance.js
```

## 6. Testing booking without spending money

Point the module at an unreachable host or at a small fake Quickshipper
served inside the store's PHP environment, by overriding
`carriers/shuboqshpr/sandbox_api_url` (and `sandbox_auth_url`) with
`bin/magento config:set`; restore them afterwards and flush the config cache.
The `dispatch` and `status` suites already cover the booking logic with a
stub client.

## 7. Sandbox tools (cost money or change the account)

| Tool | Does |
|---|---|
| `dev/checks/qshpr-webhook.php` | lists, registers or switches webhooks on the sandbox account (there is no delete) |
| `dev/webhook-watch/run.sh` | a public HTTPS URL (Cloudflare quick tunnel + capture container) that records Quickshipper's webhook calls; can forward them to the store |

All refuse to run unless the store is in Sandbox Mode.

## 8. Live checks (with an agreed sandbox order)

1. Checkout with a Quickshipper courier (Wolt works in the sandbox; some
   couriers' orders fail there).
2. Ship → Submit Shipment: tracking number `SQ…`/`XR…` on the shipment, order
   comment with courier, number and prices, row `dispatched`.
3. Status: webhook (store reachable and connected) or the 15-minute cron adds
   shipment notes.
4. Cancel Quickshipper Delivery: shipment kept, track removed, row
   `cancelled`; Quickshipper returns the price (may keep the fee).

Record new Quickshipper behaviour in the vendor skill's
`reference/live-findings.md` with a `[live YYYY-MM-DD]` tag.

## 9. Known issues

Tracked in `docs/QA_ISSUES.md`.
