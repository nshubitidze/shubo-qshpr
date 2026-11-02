# Shubo Magento Module Standard

The rules every `Shubo_*` Magento 2 module follows. Derived from the reference
module **Shubo_Flitt** — when this document is silent, do what Shubo_Flitt
does. Every module ships a copy of this file at
`.claude/rules/module-standard.md` so the rules travel with the module.

Target platform: **Magento Open Source 2.4.9, PHP 8.3–8.5, Luma/Knockout
frontend (NOT Hyvä).**

---

## 1. Packaging — one module, one repo

- Each module is its own git repo; the **module directory is the repo root**.
  It installs into a project as `app/code/Shubo/<Module>` (git clone) or via
  Composer.
- `composer.json`: `"name": "shubo/module-<module>"`, `"type": "magento2-module"`,
  `"php": "~8.3.0||~8.4.0||~8.5.0"`, explicit `magento/module-*` requires for
  every core module used, `"autoload": {"files": ["registration.php"],
  "psr-4": {"Shubo\\<Module>\\": ""}}`. Pin third-party SDKs (`^1.0`).
- `etc/module.xml`: no `setup_version` (declarative schema only); `<sequence>`
  lists every core module the code depends on.
- Magento module name `Shubo_<Module>`; method/carrier code and config node
  `shubo_<module>`; frontName short and branded (Flitt: `flitt`); DB tables
  `shubo_<module>_<entity>`; log file `var/log/shubo_<module>.log`.
- **Shipping carriers: no underscore in the carrier code** — `shubo<module>`
  (Qshpr: `shuboqshpr`), config node `carriers/shubo<module>`. Core splits
  `shipping_method` on the first underscore
  (`Magento\Sales\Model\Order::getShippingMethod(true)`), so `shubo_qshpr`
  would be read back as carrier `shubo` by the admin shipment, packaging and
  label code. Tables and the log file keep the `shubo_<module>` form.

## 2. Claude knowledge ships with the module (mandatory)

A module is not done unless a fresh Claude session, in any project the module
is installed into, can learn how it works from the module alone.

```
<module>/
  README.md                          # for merchants and developers: features, requirements, install, configure
  LICENSE                            # the licence named in composer.json
  CLAUDE.md                          # what it does, where to read, working rules (≈60 lines)
  .claude/
    rules/module-standard.md         # this file (copied verbatim; keep in sync across modules)
    skills/<vendor>-integration-skill/   # third-party API ground truth (distilled from their docs, live findings, open questions)
    skills/shubo-<module>-<role>/    # the module's own skill: SKILL.md + reference/architecture, configuration,
                                     #   flows, decisions (what is settled and why), changing (how to add or
                                     #   modify things), troubleshooting (incl. known limits)
  docs/
    QA_GUIDE.md                      # how to verify a change: gates, suites, browser tests, sandbox rules
    QA_ISSUES.md                     # known issues, status legend 🔴🟡🟢✅
  dev/
    checks/                          # check suites + fixture/driver scripts (php, run from the Magento root)
    qa-e2e/                          # dockerized Playwright harness (BASE_URL env, no hard-coded hosts)
```

- **The module repo describes the module as it is and how to change it —
  not how it got there.** The modules are meant to be open source, so
  everything in the repo is written for an outside developer and their AI
  assistant. Plans and backlogs, design notes of work in progress, session
  logs, research, reviews, investigation tools and anything about particular
  people, machines, stores or accounts are kept outside the module repo.
  Test: would a developer changing this module need it? If not, it does not
  belong in the module.
- A change that alters behaviour, a setting, a decision or a known limit
  updates the module skill in the same change.
- Third-party documentation is not copied verbatim into the module (it is
  not ours to license): link it and keep our own summary and findings.

- The module `CLAUDE.md` must `@`-import its rules
  (`@.claude/rules/module-standard.md`). Claude Code loads a nested module's
  `CLAUDE.md` and skills only when it first reads a file in that module, and
  nested `.claude/rules/` only conditionally, so the import is what reliably
  brings the rules in whether the module is a repo root or sits under `app/code/`.
- `CLAUDE.md` stays short (≈60 lines) and points into `docs/` and the skills;
  depth lives in `docs/` and skill `reference/*.md` files.
- Shipped docs contain **no workstation-specific paths or hostnames** (no
  `/home/<user>/…`, no project CLI names). Describe the environment
  generically; local specifics belong in the host project's CLAUDE.md.
- Never put real credentials in any shipped file. Only the vendor's public
  test credentials, clearly labelled.
- Every third-party API fact (endpoints, fields, statuses, signatures, error
  codes) must be grounded in the vendor skill/docs — **never invented**. When
  the docs and live behaviour disagree, record the live finding in the step doc
  and in the vendor skill notes.

## 3. Directory layout

| Dir | Holds |
|---|---|
| `Gateway/` (payments) · `Model/Carrier/` (shipping) | Pieces that plug into Magento's framework: Adapter commands, value handlers, validators, carrier model, typed `Config` |
| `Model/` | The module's own services: `<Vendor>Client`, `Endpoint` enum, `LogSanitizer`, ledger/lock services, payload builders |
| `Model/Config/Source/` | Admin option sources (with typed consts for their values) |
| `Model/Ui/ConfigProvider.php` | Checkout config + **the single source of truth for the method/carrier code** (`public const string CODE`) |
| `Exception/` (module root) | Module exceptions (core precedent: `module-sales/Exception/`) |
| `Controller/`, `Cron/`, `Observer/`, `Plugin/` | Standard Magento entry points — thin, delegating to `Model/` services |
| `view/frontend/…` | Layout, requirejs-config, KO components/templates, `web/css` |
| `i18n/` | `ka_GE.csv` (source strings are English) |

ViewModels over Blocks with logic; a `Block/` only where core forces one
(e.g. Vault card renderers).

## 4. PHP style

Enforced by the gates (§11) plus these conventions:

- `declare(strict_types=1);` in every file.
- **Typed class constants** (PHP 8.3): `public const string EMBED = 'embed';`,
  `private const int DEFAULT_TIMEOUT_SECONDS = 30;`. No magic string/number
  literals repeated across code — name them.
- **Backed enums** for closed value sets at type boundaries (`enum Endpoint:
  string`), with behaviour methods using `match` (e.g.
  `requiresSignedResponse()`). Consts for internal values.
- Constructor property promotion, `private readonly`, trailing commas,
  one argument per line.
- **PHPDoc on every method** (`@param`, `@return`, `@throws`) with **short
  class names** imported via `use` — never FQCNs in docblocks. Array shapes:
  `array<string, mixed>`, `array{value: string, label: Phrase}`,
  `string[]`.
- Named arguments for boolean/flag parameters: `getSecretKey($storeId, strict: true)`.
- `static fn` for closures that don't need `$this`.
- Cast raw input (config values, request data, API answers), not what a typed
  API already returns; write the same call the same way everywhere.
- Build each config path or key in one place; where a getter for it exists,
  use it inside the class too.
- Value objects: small classes with `public readonly` promoted properties.
- User-facing and exception messages through `__()`; exceptions extend
  `LocalizedException`.
- Money is **integer minor units + ISO currency** at every API boundary.
- No `ObjectManager::getInstance()`, no `final` classes (plugins must be able
  to intercept), no direct superglobals.
- Comments explain **why** — constraints, vendor quirks, Magento traps — not
  what the next line does. Class docblocks state the class's role and
  invariants.
- **Write code comments for an outside reader: the modules are meant to be
  open source.** State the fact itself; never point at workspace-internal
  material — Claude skills (`vendor skill reference/api.md`), `docs/` files,
  step numbers (`step 03`), QA/review ids (`QA-3`, `D0`), approval dates,
  or sections of this standard. That history is kept outside the module
  repo. The vendor's public docs may be cited by name.

## 5. Configuration

- `etc/config.xml` defaults only for values an operator may tune
  (URLs, timeouts, lifetimes, toggles) plus framework flags.
- `etc/adminhtml/system.xml`: every field has a `<comment>` written for a
  merchant; scope flags deliberate (`showInStore="0"` for credentials). A
  field's scope works only if its group and section allow that scope too.
- Secrets: `type="obscure"` + `Magento\Config\Model\Config\Backend\Encrypted`
  + registered as sensitive in `Magento\Config\Model\Config\TypePool` (di.xml).
- **One typed `Config` class** is the only code that knows config field names:
  `private const string FIELD_*`, typed getters taking `?int $storeId`, guards
  that fall back to named defaults for missing/zero values, and `strict:`
  credential getters that throw the module exception when unconfigured.
  Zero counts as "not set" wherever 0 means nothing (coordinates, timeouts,
  limits). Methods grouped by topic; a new getter goes into its group.
- Pluggable providers (e.g. geocoders) keep their settings under
  `<code>_<setting>` and read them through one general getter, so adding or
  removing a provider never changes `Config`.
- A value the vendor requires is validated even when it comes from Magento's
  own settings (e.g. Shipping Origin): missing → the method is not offered
  and a log line says why.

## 6. Third-party API client

- Own the HTTP via `Magento\Framework\HTTP\Client\CurlFactory`. Use a vendor
  SDK only for a correctness-critical primitive (Flitt: signature hash only),
  wrapped behind our own injectable service. Never use SDK static/global state.
- `Model/Endpoint.php` backed enum of paths; per-endpoint policy as enum methods.
- One private `post()`/`request()` in the client that: builds the envelope,
  logs, sends with the configured timeout, validates HTTP status → JSON →
  envelope shape → vendor success flag → signature (if any), and throws
  `<Vendor>ApiException` (carrying the vendor error code when definitive) for
  every failure shape.
- **Payload building stays out of the client** — dedicated builder classes.
- Every call logs one INFO summary (endpoint, elapsed ms, our id, vendor
  status); failures log ERROR with a masked body excerpt and the vendor
  request id; full masked dumps only when the `debug` config flag is on.

## 7. Logging

- Dedicated channel in `etc/di.xml`: `Shubo<Module>LogHandler`
  (`Magento\Framework\Logger\Handler\Base`, `/var/log/shubo_<module>.log`) +
  `Shubo<Module>Logger` (`Magento\Framework\Logger\Monolog`), injected per class
  via `<type><arguments><argument name="logger">`. Constructor docblock says
  `Bound to Shubo<Module>Logger in di.xml.`
- All payload logging goes through `LogSanitizer` (one mask list;
  `line()` flattens control chars against log forging; `excerpt()` truncates).
- Never log or echo secrets, tokens, card data or personal data beyond what
  debugging strictly needs.

## 8. Persistence and state

- Declarative `etc/db_schema.xml` + `db_schema_whitelist.json`; every column
  has a `comment`; indexes named `<TABLE>_<COLS>`; a comment on the table
  explaining its role. No foreign keys where a row must outlive the entity it
  references — say why in the schema comment.
- Module-owned tables are served by a lightweight service over
  `ResourceConnection` (Flitt's `Ledger`): `insertOnDuplicate` for idempotent
  writes, state constants, and **forward-only state transitions enforced
  atomically in the UPDATE's WHERE clause**.
- Core entities go through repositories / service contracts. Known trap:
  `orderRepository->save()` drops payment `additional_information` — use
  `OrderPaymentRepository`.
- Concurrency: one named lock service per entity over `LockManagerInterface`
  (`PREFIX . sha1($key)`, bounded timeout, release in `finally`).

## 9. Trust, webhooks, and reconciliation

- **The browser never decides business state.** JS triggers; the server
  verifies against the vendor's authoritative data before anything is written.
- Inbound webhooks: `HttpPostActionInterface` + `CsrfAwareActionInterface`
  (CSRF bypass justified by vendor authentication); authenticate **before any
  mutation**; a `Result` value object maps outcomes to 200 (final) / 400
  (bad payload/auth — retry can't help) / 503 (transient — vendor retries);
  catch `\Throwable` at the controller → 503; handlers are idempotent.
- When the vendor's answer to our own request already gives the new state,
  record it at once; webhooks and polling carry the changes we did not cause
  (a webhook may arrive while our request still holds the lock).
- A cron reconciler is the safety net for everything a webhook or browser can
  miss; it works from the module's own table via an indexed due-rows query.
- Prefer Magento's native surfaces (admin buttons, standard success/cart
  pages) over custom UI — implement the command behind the native button.

## 10. Magento extension rules

- Plugins over preferences; observers only on real events; never edit core.
- Area-scope configuration (`etc/frontend/di.xml`, `etc/frontend/events.xml`);
  explain in a comment when something must be global (e.g. placement happens in
  `webapi_rest`).
- Every external host the frontend loads gets an `etc/csp_whitelist.xml` entry
  (checkout CSP is **enforced** in 2.4.9).
- Frontend (Luma/KO): AMD `define`, `'use strict'`, `const`; event handlers bound
  **once** at mount (never in click handlers); failure/timeout/decline are
  first-class UI states with a retry path; fence stale async work.
- After any `di.xml` or constructor change: clear `generated/` and run
  `setup:di:compile` to validate (stale `generated/metadata` silently hides new
  arguments).

## 11. Quality gates (must be green before any commit)

Run inside the phpfpm container from the project root:

- `composer lint` — phpcs `Magento2` standard, **0 errors**; warnings on new
  or changed lines are fixed too.
- `composer stan` — phpstan level 2 with `bitexpert/phpstan-magento`, **no errors**.
- `composer fix` — phpcbf + php-cs-fixer (PSR-12 + project rules); it runs over
  all of `app/code`, so revert any unrelated files it touches.
- `bin/magento setup:di:compile` succeeds.
- Unit tests live in `Test/Unit/`; dev tooling in `dev/` — both excluded from
  phpcs/phpstan/php-cs-fixer. `dev/` scripts must locate the Magento root
  themselves (no `../../..` assumptions) and take hosts/ids from env or config.
- The module's `dev/checks/` suites relevant to a change must be ALL PASS.
  A suite sets up the state it assumes (inside its rolled-back transaction)
  instead of depending on whatever data the store has.
- Dependencies: every `Magento\<Module>\` and `Magento_<Module>` used in
  PHP or XML is listed in `composer.json` and in `<sequence>`.

## 12. Working on a module

- Small, verifiable changes; one topic per pull request.
- Verify against vendor docs and live behaviour before asserting how
  something works.
- Test only with the vendor's sandbox/test credentials; never touch
  production. Actions that create something at the vendor cost money even in
  a sandbox: keep tests offline unless the account owner agrees.
- A change is done when the gates are green, the relevant suites pass, the
  behaviour was shown before/after on a store, and the module skill describes
  the new state.
