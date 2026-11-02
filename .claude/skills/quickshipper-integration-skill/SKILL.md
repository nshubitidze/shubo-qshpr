---
name: quickshipper-integration-skill
description: Quickshipper (qshpr.com, Georgian delivery aggregator — Wolt, Glovo, OnWay and other couriers behind one API) Delivery API facts — OAuth token, prices (/v1/Order/fees), order create / confirm / cancel / read, webhooks, statuses, error shapes, sandbox vs production, and how the API actually behaves. Use when changing, testing or debugging code that talks to Quickshipper, including the Shubo_Qshpr Magento carrier.
---

# Quickshipper Delivery API

Facts about Quickshipper's API that the module relies on. **Never invent
Quickshipper behaviour**: if it is not here, it is in
`reference/open-questions.md` or unknown.

Every fact carries its source:

- **[docs]** — Quickshipper's public docs:
  https://app.theneo.io/quickshipper/delivery-en/quickshipper-delivery-api
  (incomplete, wrong in places).
- **[spec]** — the OpenAPI spec the servers publish (more complete, prefer it):
  sandbox https://delivery-test.quickshipper.ge/swagger/v1/swagger.json,
  production https://delivery.quickshipper.app/swagger/v1/swagger.json.
- **[live YYYY-MM-DD]** — observed against the sandbox; wins when it
  disagrees with the other two.

## Files

1. `reference/api.md` — hosts, authentication, response shapes, the
   endpoints the module uses, statuses.
2. `reference/live-findings.md` — how the API actually behaves: prices,
   booking, Drafts, cancelling, webhooks, sandbox quirks, terms that matter.
3. `reference/open-questions.md` — what is still unknown.

For any field not described here, read the spec.

## Five facts that shape any integration

1. **OAuth2 password grant** with the merchant's dashboard login, on a
   separate auth host, form-encoded. The account's ApiKey does not
   authenticate the Delivery API. [docs][spec][live]
2. **Coordinates are mandatory.** Quickshipper does not geocode; an address
   sent as text only is priced at 0,0. [live]
3. **HTTP 200 does not mean success.** Business errors are HTTP 200 with
   `success:false` and the real code in the body's `httpStatusCode`; auth and
   validation errors use two other shapes. [live]
4. **Money is decimal GEL**, and a price's `amount` already includes
   Quickshipper's `serviceFee`. [live]
5. **The delivery price is taken from the prepaid balance when the order is
   created**; whether it is created as a Draft depends on the merchant's
   dashboard setting. [live]

## Credentials

Only Quickshipper's public sandbox OAuth client
(`DeliveryApiClient:DeliveryApiSecret`, from the docs) may appear in code.
Merchant logins, the production client and the ApiKey are secrets: never in
a repository, always masked in logs.
