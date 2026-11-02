# Shubo_Qshpr

Quickshipper delivery — a shipping carrier module for Magento Open Source
2.4.9 (PHP 8.3–8.5, Luma/Knockout checkout, not Hyvä). Quickshipper
(qshpr.com) is a Georgian delivery aggregator: Wolt, Glovo, OnWay and other
couriers behind one Delivery API.

Checkout offers one shipping method per courier and speed at Quickshipper's
price for the customer's address (the module geocodes it — Quickshipper does
not). Submit Shipment in the admin books the chosen courier and puts
Quickshipper's order number on the shipment; paid orders can be booked
automatically. A webhook and a polling cron follow each delivery. Every
delivery is recorded in `shubo_qshpr_delivery`.

Code conventions: @.claude/skills/shubo-qshpr-carrier/reference/conventions.md

## Where to read

- `.claude/skills/shubo-qshpr-carrier/` — how the module works and how to
  change it: architecture, settings, flows, decisions and why, how-tos,
  troubleshooting and known limits. Start with its `SKILL.md`.
- `.claude/skills/quickshipper-integration-skill/` — Quickshipper API facts
  and how the API actually behaves. Every Quickshipper fact comes from here.

## Working rules

- Read the class docblocks and the skill's `decisions.md` before changing an
  area; do not undo a decision as a side effect.
- Never guess Quickshipper behaviour: if the vendor skill does not cover it,
  it is an open question. Record new observed behaviour in its
  `reference/live-findings.md` with a `[live YYYY-MM-DD]` tag.
- Test with a Quickshipper sandbox account only. Creating an order or a
  webhook costs money even there.
- No credentials in this repository.
- No module-created product attributes: a setting lets the merchant pick an
  existing one.
- A change is done when the gates are green, it is shown working on a store,
  and the module skill describes the new state.
