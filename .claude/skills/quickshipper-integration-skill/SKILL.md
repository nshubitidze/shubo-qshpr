---
name: quickshipper-integration-skill
description: Quickshipper (qshpr.com, Georgian delivery aggregator — Wolt, Glovo, OnWay and other couriers behind one API) ground truth for the Delivery API and the Delivery Button widget — OAuth token, rate quotes (/v1/Order/fees, /weights), order create/edit/confirm/cancel/track, waybill, webhooks, statuses, error shapes, sandbox vs production hosts, business terms and pricing. Use when designing, implementing, reviewing, testing or debugging any Quickshipper integration, including the Shubo_Qshpr Magento carrier.
---

# Quickshipper integration skill

Third-party ground truth for **Quickshipper**. Never invent Quickshipper
behaviour: every fact here is tagged with its source, and anything not
covered is an **open question** (see `reference/open-questions.md`), not
something to guess.

Source tags used throughout:

- **[docs]** — public docs at https://app.theneo.io/quickshipper/delivery-en/quickshipper-delivery-api
  Incomplete, and wrong in places.
- **[spec]** — the OpenAPI spec the servers publish themselves
  (https://delivery-test.quickshipper.ge/swagger/v1/swagger.json and
  https://delivery.quickshipper.app/swagger/v1/swagger.json; our readable
  summary: `source/openapi-summary.md`). **More complete and more accurate than
  the docs — prefer it.**
- **[live YYYY-MM-DD]** — observed against the sandbox. Wins over both when
  they disagree. See `reference/live-findings.md`.

## Read in this order

1. `reference/api.md` — hosts, auth, every endpoint we care about, response
   envelope, error shapes, statuses.
2. `reference/live-findings.md` — how the sandbox actually behaves (rates by
   city and weight, error envelopes, doc errors).
3. `reference/delivery-button.md` — the hosted checkout widget (ApiKey-based).
4. `reference/business.md` — company, how merchants use it, terms, pricing.
5. `reference/open-questions.md` — what is still unknown / must be asked.

## Five facts that shape any integration

1. **Two credentials, two integrations.** The server-side Delivery API uses an
   OAuth2 **password grant** (merchant's Quickshipper login) → Bearer token.
   The Delivery Button widget uses the per-account **ApiKey** (Profile →
   Integration). `POST /v1/Order/confirm` accepts either. [docs][spec]
2. **Coordinates are mandatory.** Quotes and orders are priced from
   latitude/longitude, not postcodes. Street/city strings alone are not enough.
   [docs][spec][live]
3. **HTTP 200 does not mean success.** Business errors come back as HTTP 200
   with `success:false` and the real code in the body's `httpStatusCode`.
   Transport/auth and validation errors use two other, different JSON shapes.
   [live 2026-09-29]
4. **Money is decimal GEL** (`amount: 6.6`, `currencyName: "GEL"`). Convert
   to integer tetri at our boundary. `serviceFee` is reported separately
   from `amount`. [live 2026-09-29]
5. **Coverage is intra-city.** In the sandbox, quotes exist for
   Tbilisi→Tbilisi and Batumi→Batumi only; intercity and Tbilisi→Rustavi return an
   empty `fees` list. An empty list is a normal "no delivery offered" answer.
   [live 2026-09-29]

## Credentials policy

Only sandbox credentials, never in shipped files. The sandbox OAuth client
`DeliveryApiClient:DeliveryApiSecret` is public (published in the docs); the
production client credentials are **not** published — ask Quickshipper.
