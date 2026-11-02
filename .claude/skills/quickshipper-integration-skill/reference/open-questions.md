# Open questions for Quickshipper

Unknowns that block or shape the design. Ask info@qshpr.com / in-app chat.
When answered, record the answer (with date and who answered) in
`live-findings.md` or `business.md` and strike it here.

## Reviewed 2026-11-02 — what goes to Quickshipper

The list below grew to 39 and was never sent. On 2026-11-02 the maintainer
went through it question by question; it was cut to 19 questions, written in
Georgian, to send to Quickshipper. The old questions stay
below as history. Where each one went (new = number in the new list):

| Old | Outcome |
|---|---|
| 1 | Webhooks → new 6 (retry; our 503s were not repeated), 7 (signature), 8 (fixed sender IP). `CourierLocation` dropped: no courier map in the module |
| 2 | Dropped: answered live (portal setting decides Draft / ReadyForPickup; price taken at creation) |
| 3 | Dropped: answered live; sandbox top-up is by card |
| 4 | → new 1 (price change after the courier checks; T&C 2.3, 3.3). Quote lifetime dropped: booking asks a fresh price |
| 5 | Dropped: `amount` includes `serviceFee` (live 2026-11-01). VAT → new 2 (T&C 3.1 says "0.60 + დღგ") |
| 6 | Dropped by the maintainer (OnWay absent for `CartWeight` ≤ 1 kg; still true 2026-11-02, see live-findings). Glovo part no longer reproduces; `minWeightForCarDelivery` dropped with 21 |
| 7, 22 | → new 3 (intercity; is the empty answer the sandbox?). Service-area list dropped: an empty `/fees` already says it |
| 8, 29, 31 | → new 4 ა–გ (cancel after courier assignment; dropping a Draft; can a courier refuse at `/confirm`) |
| 9 | Dropped: the module reads neither `providerType` nor `isFake` |
| 10 | → new 5 (rate limit on `/fees`). Versioning and SLA dropped |
| 11 | Dropped: the module does not use the Delivery Button |
| 12, 13 | → new 10 (production client id/secret; one per integration or per shop), 11 (IP whitelist) |
| 14 | Dropped: T&C 3.1 is clear (0.60 + VAT whatever the courier) |
| 15 | → new 12 (what the monthly invoice of T&C 3.4 covers, when the balance is charged per order) |
| 16, 37 | → new 13 (COD payout; meaning of `parcelPrice` / `deliveryPrice`). 37 dropped: the module blocks COD for couriers without cash |
| 17, 18 | Dropped (business / legal, not for the module now) |
| 19 | Dropped by the maintainer (slow first `/fees`; the timeout is 20 s since #28) |
| 20 | Dropped by the maintainer (Drop-the-Pin not wanted) |
| 21 | Dropped by the maintainer; weight limits are each courier's own terms (T&C 5.4, post/54) |
| 23 | Dropped: `/fees` with `ScheduleDelivery.*` already tells whether a courier takes a time; courier hours are the couriers' terms |
| 24 | Dropped: answered (no courier filter; the module filters the answer) |
| 25, 28 | → new 16 (can a sandbox order be moved through the statuses). OnWay failure and test credit dropped by the maintainer |
| 26 | → new 17 (find an order by `integrationOrderId`). The refused-create leftover dropped (balance unchanged) |
| 27 | Dropped: the module does not use the waybill |
| 30 | → new 9 (`autoAssign`) |
| 32 | Dropped: answered (fee-free grant; price at creation, fee kept on cancel) |
| 33 | Dropped (the module stores the tracking link as returned) |
| 34 | → new 18 (`CartAmount`) |
| 35 | Dropped (a test-setup concern; the webhook ignores orders it did not book) |
| 36 | → new 14 (`parcelDimensions`: price effect, why `/fees` takes no size) |
| 38 | → new 19 (SMS; T&C 2.4–2.5 say the tracking link goes by SMS at placement) |
| 39 | → new 15 (Draft confirm replaces `scheduledTime`; automatic mode keeps it, live 2026-11-01) |

## API behaviour
1. **Webhooks**: payload format per type (`OrderStatusChange`,
   `CourierLocation`), how calls are authenticated/signed, retry schedule,
   expected response code, timeout. (Not in the docs or the spec.)
   *(Partly answered live 2026-10-01: `OrderStatusChange` payload captured —
   see live-findings; it carries no signature or secret header. Still to
   ask: is there a way to sign or authenticate webhook calls, a fixed
   sender IP list, the retry schedule and timeout, and `CourierLocation`.)*
2. *(Partly answered live 2026-10-01: a Wolt order went Draft →
   ReadyForPickup by itself in 2.6 s; the delivery price is taken from the
   balance at creation, the 0.60 fee is kept on cancel.)*
   **`POST /v1/Order`**: does it create `Draft` or `ReadyForPickup`? Is that
   controlled per request (`autoAssign`?) or by an account setting? When does
   the platform charge the balance — on create or on confirm?
3. ~~**Balance**: what error does order creation return when the prepaid balance is
   too low?~~ Answered live 2026-10-01: HTTP 200, `success:false`, body
   `httpStatusCode 400`, "Not enough balance"; nothing is created. Still to
   ask: how to top up the **sandbox** balance, and whether API accounts can be
   invoiced monthly instead (question 15).
4. **Quote validity**: how long is a `/fees` quote (`providerFeeId`) valid? Can
   the charged price differ from the quote (T&C §2.3/§3.3), and is the change
   reported (webhook / `GET /v1/Order` `orderPrice`)?
5. **`serviceFee`**: is it VAT-inclusive? Is the merchant charged
   `amount + serviceFee`?
   *(Inference from order 42609, 2026-10-01, not confirmed: the balance
   fell by 5.50 ₾ at creation — the fees `amount` and the create answer's
   `deliveryFee` — and 4.90 came back on cancel with 0.60 kept, so the fee
   looks included in `amount`, not added. If so, the checkout's shipping
   cost `amount + serviceFee` counts the fee twice. Code review 2026-10-04.)*
   *(Confirmed live 2026-11-01: while the fee is waived every courier is
   quoted exactly 0.60 cheaper — `amount` includes `serviceFee`; see
   `live-findings.md`. Still to ask: VAT, and whether production works the
   same.)*
6. **Weights**: are the bracket bounds `(min, max]` (1 kg returned no OnWay while the
   label says "0 - 2 kg")? Why does Glovo disappear whenever `CartWeight` is
   sent? Unit of `minWeightForCarDelivery` (11000 — grams?).
7. **Coverage**: is there an API list of providers' service areas? Is
   intercity (Tbilisi→Batumi) available in production with other providers
   (Georgian Post, Delivo…)?
8. **Cancellation**: which status value cancels (`Cancelled`?), which providers allow
   API cancellation, and what fee applies at each stage?
9. **`providerType`** enum meanings (0–10); `isFake` meaning.
10. Rate limits, API versioning/deprecation policy, uptime SLA.
11. Delivery Button: shape of the `onOrderCreate` payload; how an account picks
   scenario 1 (direct ReadyForPickup) vs scenario 2 (Draft + confirm).
   *(Partly answered 2026-10-01: a dashboard setting for API orders —
   assign the courier automatically, or after confirmation; see
   live-findings. Still to ask: charging in Draft mode, Draft lifetime, and
   whether `autoAssign` overrides the setting per request.)*

## Access and commercial
12. **Production OAuth client credentials** (only the sandbox
   `DeliveryApiClient:DeliveryApiSecret` is published). Can merchants get
   `client_credentials` instead of storing their dashboard password?
13. Is IP whitelisting mandatory in production? Is there an approval step?
14. Which platform fee applies to API orders (0.60 GEL + VAT per T&C vs ₾0.54 /
   $0.20 / $0.25 elsewhere)? Do the 10 free orders apply?
15. Prepaid balance or monthly invoice for API accounts?
16. Cash on delivery via API: which couriers, payout schedule, fees.
17. Do they know of or plan a Magento integration? Would they list or
   co-promote ours (as they do Plug&Pay's WooCommerce plugin)?
18. Does passing end-customer data through the API fall under T&C §4
   data-sharing and direct-marketing clauses? (GDPR-style concern for merchants.)

## Added 2026-09-30
19. **Fees latency**: in the sandbox the first `GET /v1/Order/fees` after
   ~10 idle minutes takes 9–10+ s (then under 1.3 s). Is production the
   same? What timeout do they recommend for checkout rate lookups?
20. **Drop-the-Pin**: `POST /v1/Order` has `dropThePin` (boolean) and the T&C
   price it as a 0.20 GEL add-on. What does it do — does the recipient get a
   link to confirm or correct the delivery point before the courier leaves?
   Could it be our safety step for wrongly geocoded addresses?
21. **Weight limits and car delivery** (2026-10-01): in the sandbox Wolt and
   Glovo quote any weight (even 25 kg) at the same price, with or without
   `CarDelivery=true`; only OnWay stops at 10 kg. Wolt reports
   `minWeightForCarDelivery: 11000` (grams?). What are each courier's real
   limits in production, and should an integration send `CarDelivery=true`
   when the cart is heavier than `minWeightForCarDelivery`?
22. **Couriers on an account** (2026-10-01): the sandbox account has only
   Wolt, Glovo and OnWay. How does a merchant get EasyWay, Georgian Post,
   Deliverers, Delivo activated — per courier on request? Is Georgian Post (or
   another) the courier for intercity deliveries (e.g. Tbilisi → Batumi)?

## Added 2026-10-01
23. **Instant couriers and hours**: is there a documented way to tell an
   instant (45–60 min) price from a scheduled or multi-day one (live: price
   `id` "asap"/"ASAP")? What are Wolt's and Glovo's hours and how far ahead
   can a delivery be scheduled (the sandbox quotes 03:00 and past times)?
   What happens when an "asap" order is created hours after the quote, or
   outside the courier's hours?
24. **Courier filter**: can `GET /v1/Order/fees` be limited to some couriers
   per request (the spec has no parameter), instead of switching a courier
   off for the whole account?
25. **Sandbox orders** (2026-10-01): OnWay orders from the sandbox API fail
   with "OnWay service failed" (orders 42607 and a later one on the probe
   account). What does OnWay need — a real phone number, other fields — or
   does the OnWay sandbox not work? Why must the sandbox balance be topped up
   with real money, and can it get test credit?
26. **`integrationOrderId`**: shown in the dashboard as the internal order
   code, but not returned by `GET /v1/Order` or `/v1/Order/list`
   (`externalOrderId` held another id, `6abd7b3c72e58806e5c995cb` — Wolt's?).
   Can the API return it or search the list by it? And why does a refused create return `orderId 0` while leaving a
   cancelled order in the account?
27. **Waybill in the sandbox** fails with "Unable to load shared library
   'libwkhtmltox'" (2026-10-01). Is the waybill a PDF, and can the sandbox be
   fixed?
28. **What the sandbox does with an order** (2026-10-01): a sandbox Wolt
   order got an id that looks like Wolt's own (`externalOrderId`
   `6abd7b3c72e58806e5c995cb`), and OnWay orders fail with "OnWay service
   failed" — so the sandbox calls the couriers' systems. Are those the
   couriers' test systems (no real courier is sent)? Do sandbox orders move
   through the statuses by themselves, or can a test order be moved (e.g. to
   Delivered) to test an integration? Why does the sandbox balance take real
   money?
29. **Dropping a Draft** (2026-10-01, order 42615): with the portal on
   "assign after confirmation", a never-confirmed Draft could be neither
   cancelled (`POST /v1/Order/status` → "Order status change failed") nor
   deleted (`DELETE /v1/Order` → "Order delete is restricted"), and its
   price stays held. How should an integration drop a Draft it will not
   confirm? Do Drafts expire, and is the money then returned?
30. **`autoAssign`** (order field, boolean in the spec): `true` did not
   override "after confirmation" (order 42615 came back Draft). What does
   it do?
   *Planned test (developer, 2026-10-03), the opposite of T1: switch the
   portal to "assign the courier automatically" and create an order with
   `"autoAssign": false` — does it come back as a Draft? Needs one sandbox
   order and the portal setting changed and restored, so only with the
   developer.*
31. **Confirm refused**: in Draft mode the order reaches the courier company
   at `/v1/Order/confirm`. If the courier refuses there (as OnWay refused at
   create in automatic mode), what state is the order left in, and is the
   held price returned?
32. *(Partly answered 2026-10-01: Quickshipper granted the maintainer 20
   fee-free requests, which explains the 0 fee.)* **Service fee**: the sandbox `serviceFee` was 0.60 ₾ in the morning of
   2026-10-01 and 0 in the afternoon (and Wolt 5.50 → 4.90 on the same
   trip). Is the fee per account, per courier, promotional? Is it charged
   at creation, at cancel, or both?
33. **Tracking link in production** (2026-10-02): the sandbox returns
    `trackingUrl` `https://test.quickshipper.ge/track/<orderNo>`. What is the
    live format, and is a link once returned guaranteed to keep working?
    (The module stores the link as returned and does not build it.)
34. **`CartAmount` on `/v1/Order/fees`** (2026-10-03): the spec has it, the
    written docs do not; `CartAmount=150` changed nothing in the sandbox
    [live 2026-09-29]. What does it change — the account's "Delivery Fee
    Sharing" / discount rules by cart value, a courier's parcel-value
    limit, insurance, cash on delivery? (The module sends it with every
    price request and `cartAmount` with every booking.)
35. **Several environments on one account** (2026-10-03): every active
    webhook of an account gets every order's status change, so local,
    staging and UAT stores sharing one account each receive the others'
    orders. Can a merchant get several sandbox accounts (one per
    environment)? Can a webhook be limited to some orders (e.g. by a field
    sent at creation)? Can webhooks be deleted, or switched off from the
    dashboard?

## Added 2026-10-08 (from the roadmap branches)
36. **`carDelivery` and `parcelDimensions`** (orders 42619, 2026-10-03):
    both are accepted on `POST /v1/Order` but not echoed by
    `GET /v1/Order`, and the price request takes no size. What do they
    change — the courier chosen, the price after pickup, the vehicle sent?
    Does `parcelsQuantity` count every parcel of the order or only those
    with dimensions? (Parcel size is on hold until this is answered.)
37. **`cashOnDelivery` for a courier without cash**: what happens when an
    order carries `cashOnDelivery` but the courier's `hasCashOnDelivery` is
    false — refused with an error, or accepted and ignored? (The module
    stops such bookings itself.) With question 16: what exactly
    `deliveryPrice` and `parcelPrice` mean.
38. **SMS to the customer**: for API orders, does Quickshipper or the
    courier send the customer an SMS with the tracking link (sender, can
    it be switched off)?
39. **`scheduledTime` and Drafts** (order 42619): the delivery time was
    replaced with "now" when the Draft was confirmed. Is a Draft's
    `scheduledTime` meant to survive `/confirm`, and is it kept when the
    portal assigns automatically? Does `scheduledTime` mean the pickup or
    the delivery time?
