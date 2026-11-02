# Changing the module

How to make the usual changes so they match the rest of the module. Code
style and gates: `conventions.md`.

## Before you start

1. Read the docblock of every class you touch and `decisions.md` for the
   area. If the change would undo a decision, stop and ask.
2. Ground every Quickshipper fact in the `quickshipper-integration-skill`
   (tags `[docs]`, `[spec]`, `[live …]`). If it is not there, it is an
   open question — do not guess field names, statuses or behaviour.
3. Work on a branch; keep one change per pull request.
4. Reproduce the problem (or show the missing behaviour) on a local store
   before changing code, so you can show the change fixes the same case.

## Add an admin setting

1. `etc/adminhtml/system.xml` — the field in its group, a `<comment>`
   written for a merchant, deliberate scope flags (credentials
   `showInStore="0"`; a field's scope works only if its group allows it).
   Secrets: `type="obscure"`, backend `Encrypted`, and a `TypePool`
   `sensitive` item in `etc/di.xml`.
2. `etc/config.xml` — a default if the merchant may tune it.
3. `Model/Carrier/Config` — a `private const string FIELD_…` and a typed
   getter with `?int $storeId`, in its topic group; fall back to a named
   default for empty/zero values where 0 means nothing.
4. `i18n/ka_GE.csv` — the label and comment in Georgian (keys are the
   English text exactly).
5. Add the setting to `configuration.md`.

Never read `carriers/shuboqshpr/…` paths anywhere but `Config`.

## Add a geocoder

1. A class implementing `Model/Geocoding/ProviderInterface` (asks its
   service, returns `Location` with a `Precision`; throws
   `GeocodingException` for service failures, returns null for "not found").
2. Register it in `etc/di.xml` under `Geocoder` → `providers`:
   `<item name="<code>">` with `label` (translatable) and `model`.
3. Its settings use `geocoding/<code>_<setting>` in `system.xml` with
   `<depends>` on the geocoder, read through `Config`'s general getter.
4. If it loads anything in the browser, add the host to
   `etc/csp_whitelist.xml`. Respect the service's usage policy (rate limits,
   caching rules) and say so in the class docblock.
5. The `Geocoder` decides precision, city match and caching — do not
   duplicate that in the provider.

## Change what is sent to Quickshipper

- Price request: `Model/Rates/RateQuoter::quote()` (the query is also the
  cache key). Booking's fresh price: `Dispatcher::freshPrice()` — keep both
  asking the same question, or the booked courier differs from the quoted.
- Booking body: `Model/Dispatch/OrderPayloadBuilder::build()` only; the
  client never builds payloads.
- A new endpoint: a case in `Model/Endpoint` (+ `path()` / method), a public
  method in `QshprClient` that goes through its private `request()`.
- Amounts: integer tetri inside the module, `Money::toGelAmount()` only
  when writing the payload.

## Add a delivery state or column

- `etc/db_schema.xml` (with a `comment`) **and**
  `etc/db_schema_whitelist.json` (regenerate with
  `bin/magento setup:db-declaration:generate-whitelist --module-name=Shubo_Qshpr`).
- A state: a `DeliveryLog::STATE_…` constant and a transition method whose
  UPDATE lists the allowed source states in its WHERE and returns whether a
  row moved. States only move forward. Update the diagram in
  `architecture.md` and the column comment.
- Queries for crons need an index named `SHUBO_QSHPR_DELIVERY_<COLS>`.

## Add a cron, observer or plugin

- Cron: `etc/crontab.xml` (group `default`, comment why) → a thin class in
  `Cron/` delegating to a `Model/` service. Take the order's `DeliveryLock`
  with wait 0 and skip busy orders. Bounded batch per run.
- Observer: global `etc/events.xml` unless the event fires in one area only
  — orders are placed in `frontend` **and** `webapi_rest`. Never let an
  observer fail order placement; log instead.
- Plugin over preference; admin-only behaviour in `etc/adminhtml/di.xml`.
- Logger: bind `ShuboQshprLogger` to the class in `etc/di.xml`; the
  constructor docblock says "Bound to ShuboQshprLogger in di.xml."
- After any `di.xml` or constructor change: clear `generated/code` and
  `generated/metadata`, run `setup:di:compile`.

## Change checkout (Luma / Knockout)

- AMD `define`, `'use strict'`, event handlers bound once at mount.
- A mixin goes into `view/frontend/requirejs-config.js`, beside the existing
  one; never replace a core template wholesale if a mixin can do it.
- A new image or script host → `etc/csp_whitelist.xml` (checkout CSP is
  enforced).
- The browser never decides business state: anything the customer picks is
  re-checked on the server.

## Messages and texts

- Every user-facing message through `__()`, English source, Georgian in
  `i18n/ka_GE.csv`.
- `DispatchException` messages are shown to the merchant in the admin: say
  what happened, whether the shipment was saved and what to do next.

## When you are done

1. Gates green (`conventions.md`), and the change shown working on a store
   with a Quickshipper sandbox account — the case reproduced before the
   change and fixed after it. Creating sandbox orders costs money: use a
   stub or an unreachable API URL where you can.
2. Update this skill: the reference file of the area, and `decisions.md` if a
   design choice was made. Vendor facts learned → the vendor skill.
3. In the pull request: what changed, why, how it was verified (before /
   after on a store), what was not verified. Note any PHP feature newer than
   8.3 the change uses.
