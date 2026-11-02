# Shubo_Qshpr

Quickshipper delivery — a shipping carrier module for Magento Open Source
2.4.9 (PHP 8.3–8.5, Luma/Knockout checkout, not Hyvä). Quickshipper
(qshpr.com) is a Georgian delivery aggregator: Wolt, Glovo, OnWay and other
couriers behind one Delivery API. Composer package `shubo/module-qshpr`,
installed as `app/code/Shubo/Qshpr` or through Composer.

Module rules (code style, layout, gates): @.claude/rules/module-standard.md

## What it does

Checkout offers one shipping method per Quickshipper courier and speed, at
Quickshipper's price for the customer's address (the module geocodes it —
Quickshipper does not). Submit Shipment in the admin books the chosen courier
with a fresh price and puts Quickshipper's order number on the shipment as
its tracking number; paid orders can be booked automatically. A webhook and a
polling cron follow each delivery; the shipment page can cancel or book a
courier again. Every delivery is recorded in `shubo_qshpr_delivery`.

## Where to read

- **Module skill** `.claude/skills/shubo-qshpr-carrier/` — start with its
  `SKILL.md`: architecture, settings, flows, design decisions and why,
  how to change things, troubleshooting and known limits.
- **Vendor skill** `.claude/skills/quickshipper-integration-skill/` —
  Quickshipper API facts, live sandbox behaviour, business terms, open
  questions. Every Quickshipper fact comes from here.
- `docs/DEVELOPMENT_PLAN.md` — what is built, what is on branches, what is
  open and not decided.
- `docs/QA_GUIDE.md` — gates, check suites, browser tests, sandbox rules.
- `docs/QA_ISSUES.md` — known issues.
- `dev/checks/` — PHP check suites (`QSHPR_OFFLINE=1` sends nothing to
  Quickshipper) and sandbox tools; `dev/qa-e2e/` — Playwright browser tests;
  `dev/webhook-watch/` — captures Quickshipper's webhook calls.

## Working rules

- Read the class docblocks and the skill's `decisions.md` before changing an
  area. Do not undo a decision as a side effect; if a change needs to, ask
  first. Items under "Open — not decided" in the plan are not to be built
  without agreement.
- Ground every Quickshipper fact in the vendor skill with its source tag
  (`[docs]`, `[spec]`, `[live YYYY-MM-DD]`). Not covered → an open question,
  never a guess. Live behaviour wins over the docs; record it in the vendor
  skill's `reference/live-findings.md`.
- Sandbox only. Creating a Quickshipper order or webhook costs money even in
  the sandbox — keep tests offline unless the account owner agrees.
- Credentials never go into this repository; keep them in the host
  project's untracked env file.
- Prefer free or self-hostable third-party services (lowest running cost for
  the merchant); paid ones only as an option.
- No module-created product attributes: let the merchant pick an existing
  attribute in a setting.
- Small, verifiable changes. Before calling a change done: gates green, the
  relevant suites ALL PASS, the behaviour shown before/after on a store, and
  the module skill updated in the same change.
