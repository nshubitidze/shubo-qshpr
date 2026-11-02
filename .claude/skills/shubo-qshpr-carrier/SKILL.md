---
name: shubo-qshpr-carrier
description: How the Shubo_Qshpr Magento 2 module (Quickshipper delivery carrier for Georgian shops — Wolt, Glovo, OnWay and other couriers) works and how to change it safely — checkout rates, geocoding, booking from Submit Shipment or automatically, Draft confirm and reuse, status webhook and polling, cancel / book again / cancel on refund, cash on delivery, car delivery, courier logos, admin balance; its settings, delivery table, crons, logs, design decisions, known limits, and the checks to run after a change. Use when reading, modifying, extending, configuring, testing or debugging Shubo_Qshpr.
---

# Shubo_Qshpr — the module skill

Shubo_Qshpr is a Magento Open Source 2.4.9 shipping carrier (PHP 8.3–8.5,
Luma/Knockout checkout, not Hyvä) for **Quickshipper** (qshpr.com), a
Georgian delivery aggregator. One Quickshipper account gives a shop many
courier companies (Wolt, Glovo, OnWay, …) behind one Delivery API.

What the module does, end to end:

1. **Checkout** — locates the customer's address on the map (Quickshipper
   does not geocode), asks Quickshipper for prices and offers one shipping
   method per courier and speed (`shuboqshpr_<courierId>_<priceId>`).
2. **Order placed** — records the choice in `shubo_qshpr_delivery`.
3. **Booking** — Submit Shipment in the admin books the chosen courier with a
   fresh price and puts Quickshipper's order number on the shipment as its
   tracking number; or a cron books and ships paid orders by itself.
4. **Following** — a webhook and a polling cron bring each delivery's status
   onto the shipment; cancel, book again and cancel-on-refund handle the rest.

## Read this first

- How the code is written: `reference/conventions.md`. New code follows it.
- Quickshipper API facts: the `quickshipper-integration-skill` next to this
  one. **Never invent Quickshipper behaviour** — if that skill does not
  say it, it is an open question (its `reference/open-questions.md`).

## Which reference to read

| You want to… | Read |
|---|---|
| Find the class that does something; understand the delivery table, its states, the lock | `reference/architecture.md` |
| Know what an admin setting does, its default and scope | `reference/configuration.md` |
| Follow a flow: rates, order placed, booking, Draft, automatic booking, status, cancel, refund, cash, car | `reference/flows.md` |
| Know **why** something is built the way it is, before changing it | `reference/decisions.md` |
| Add or change something (a setting, a geocoder, a booking field, a state, a cron, checkout JS) | `reference/changing.md` |
| Debug a problem, or know the module's limits | `reference/troubleshooting.md` |
| Write code the way the module does; the gates | `reference/conventions.md` |

## Rules that are easy to break

- The carrier code is **`shuboqshpr`, without an underscore** — Magento
  splits a shipping method on its first underscore to find the carrier.
- Anything that books, confirms or cancels a courier holds the order's
  **`DeliveryLock`**.
- A booking request is **never retried automatically**: a timeout may still
  have created (and charged) the order at Quickshipper.
- A webhook body is only a hint: Quickshipper does not sign webhooks, so the
  order is always read back from Quickshipper.
- **No module-created product attributes**: a feature that needs product
  data gets a setting where the merchant picks one of their own attributes.
- Creating anything at Quickshipper (an order, a webhook) costs money even
  in the sandbox (its balance is prepaid by card) — test against a stub or
  an unreachable URL unless the account owner agrees.

## Keep this skill true

A change that alters behaviour, a setting, a state, a decision or a limit
updates the matching reference file in the **same** change. A Quickshipper
fact learned on the way goes into the vendor skill (`reference/live-findings.md`,
tagged `[live YYYY-MM-DD]`).
