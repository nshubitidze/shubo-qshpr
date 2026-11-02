# Code conventions

How the module's code is written. New code follows the same rules.

## PHP

- `declare(strict_types=1);` in every file. PHP 8.3+: typed class constants
  (`private const int DEFAULT_TIMEOUT_SECONDS = 20;`), backed enums for
  closed value sets (`Endpoint`, `OrderStatus`, `Precision`), constructor
  property promotion with `private readonly`, trailing commas, one argument
  per line.
- No magic strings or numbers repeated across code — name them as constants.
- PHPDoc on every method (`@param`, `@return`, `@throws`) with short class
  names imported by `use`; array shapes like `array<string, mixed>`.
- Named arguments for flags: `getPassword($storeId, strict: true)`.
- `static fn` for closures that do not need `$this`.
- Cast raw input (config values, request data, API answers), not what a
  typed API already returns.
- Value objects: small classes with `public readonly` promoted properties
  (`Offer`, `Booking`, `PickupPoint`, `Location`).
- User-facing and exception messages through `__()`, English source,
  Georgian in `i18n/ka_GE.csv`. Exceptions extend `LocalizedException`.
- No `ObjectManager::getInstance()`, no `final` classes (plugins must be able
  to intercept), no superglobals.
- Comments explain **why** (Quickshipper quirks, Magento traps,
  invariants), never what the next line does; class docblocks state the
  class's role and invariants. Write them for an outside reader.

## Module rules

- `Model/Carrier/Config` is the only code that knows config paths
  (`private const string FIELD_*`, typed getters with `?int $storeId`).
- Money is integer **tetri** inside the module; `Model/Money` converts at the
  API boundary.
- All HTTP to Quickshipper goes through `Model/QshprClient`; payloads are
  built outside it (`OrderPayloadBuilder`, `RateQuoter`).
- Every logged payload goes through `Model/LogSanitizer`; the logger is the
  `ShuboQshprLogger` virtual type, bound per class in `etc/di.xml`.
- Module table states only move forward, enforced in the UPDATE's WHERE
  (`DeliveryLog`); one `DeliveryLock` per order around bookings.
- Declarative schema only: `etc/db_schema.xml` (every column commented) +
  `etc/db_schema_whitelist.json`; no `setup_version`.
- Plugins over preferences, observers on real events, admin-only wiring in
  `etc/adminhtml/di.xml`; never edit core.
- Every external host the storefront loads is in `etc/csp_whitelist.xml`.
- Every `Magento\<Module>\` / `Magento_<Module>` used is listed in
  `composer.json` and in `etc/module.xml` `<sequence>`.
- Frontend (Luma/Knockout): AMD `define`, `'use strict'`, handlers bound
  once; the browser never decides business state.

## Gates

- `phpcs --standard=Magento2` (0 errors; no new warnings),
  `phpstan` level 2 with `bitexpert/phpstan-magento`, and
  `bin/magento setup:di:compile` after clearing `generated/`.
