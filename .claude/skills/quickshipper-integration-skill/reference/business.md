# Quickshipper — company, terms, pricing

Researched 2026-09-29 from public sources only (no account signup forms, no
contact). Every fact carries its source; **INFERRED** marks our reading.
Re-verify before relying on prices or terms — they change (T&C §3.8).

## Company

- **"QuickShipper" LLC** (შპს „ქვიქშიფერი"), Georgian tax ID **405547877**,
  Tbilisi. T&C: https://qshpr.com/en/post/53 (EN) · https://qshpr.com/ka/post/53 (KA).
- Address published two ways: 5 Petre Kavtaradze St.
  (https://quickshipper.ge/terms) and Kavtaradze Str. 19, Tbilisi 0126
  (Shopify listings, https://apps.shopify.com/quickshipper-1).
- Founded 2022, 2–10 staff (https://ge.linkedin.com/company/quickshipper);
  grew out of the Georgian food-delivery company Elvis (https://quickshipper.ge/marketplace
  "Backed by"; ownership **INFERRED only**). GITA, Startup Academy and 500Georgia
  alumnus (https://qshpr.com/en/post/8); TechCrunch Disrupt 2025 Battlefield,
  $180k raised (https://techcrunch.com/startup-battlefield/company/quickshipper/).
- Brands and domains: qshpr.com (company site/blog), quickshipper.ge,
  quickshipper.app, dashboard.quickshipper.app (production dashboard).
  Different companies share the name: quickshipper.com, quickshipper.eu and
  "ShipExpress by Quickshipper" are not this one.

## What the product is

A SaaS **technology intermediary, not a carrier** — "QuickShipper does not
have own couriers" (FAQ, https://quickshipper.ge/); T&C: "acts as a
technological intermediary… does not participate in the sale or delivery".
Two products:

1. **Marketplace / Hub** — live quotes from third-party couriers, one account,
   centralised billing, no separate courier contracts
   (https://quickshipper.ge/marketplace). Courier list per courier-terms page
   v2026-03-09 (https://qshpr.com/en/post/57): Glovo On-Demand, Wolt Drive,
   Deliverers, Delivo, EasyWay, OnWay, Georgian Post. Our sandbox account
   has Wolt, Glovo and OnWay.
2. **Fleet management** for a merchant's own couriers (courier app, routing).
   Not relevant to a carrier module.

Coverage: Georgia, with same-day/express service in Tbilisi. Marketplace pricing also covers
Armenia, Romania, Bulgaria and Greece. The FAQ mentions intercity delivery,
but no per-city coverage list is published, and the sandbox quotes intra-city only.

## Prior art (integrations)

- **Shopify**: two official apps — "Delivery Options" ($0.50/dispatched order)
  and "Local Delivery" ($0.50/order + $15/month from month 3, needs a Google Maps key)
  (https://apps.shopify.com/quickshipper-1, https://apps.shopify.com/quickshipper-courier-app-1).
- **WooCommerce**: third-party paid plugin by Plug and Pay LLC, ₾330/year,
  ionCube-encoded (https://plugandpay.ge/product/woocommerce-quickshipper-method/,
  docs https://docs.plugandpay.ge/woocommerce/shipping/quickshipper). Uses the
  merchant's username/password, a Google Maps key and the store's lat/lng; the
  customer picks a map location at checkout; rates depend on distance and weight;
  it registers parcels manually or automatically after payment; has a test mode.
  Quickshipper co-promotes it (https://qshpr.com/en/post/61, 2026-09-20).
  The closest reference for what merchants expect.
- **Magento: no existing integration found** (GitHub, Packagist, web; Adobe
  Marketplace search unavailable).

## How a merchant uses it

- **Signup**: self-serve at dashboard.quickshipper.app. Courier companies may
  need activation on request (dashboard: "External delivery companies are not
  activated for your account… Request activation"). For API access: "register…
  connect with our team to initiate the integration" (API docs).
- **Paying for deliveries** — two models appear:
  - T&C §3.4–3.7: monthly invoice by email, paid by bank transfer within 5
    working days; 0.5%/day late penalty; access may be restricted after 15 days overdue.
  - Dashboard: **prepaid balance topped up by card** ("Insufficient funds for
    dispatching… top up your balance"; charges split into "Service fee" and "Delivery fee").
  - INFERRED: prepaid balance is the default for self-serve accounts; invoices
    are for contracted ones. The API exposes `balance` on `GET /v1/Account`.
- **Customer-pays options** exist on their side (payment links, Delivery
  Button with card or Apple Pay; "Delivery Fee Sharing": fixed fee or
  merchant covers an amount or % by cart value) — posts 58–60 on qshpr.com.
- **Cash on delivery**: a dashboard field exists; support depends on the
  courier. Glovo OD forbids cash; OnWay and EasyWay mention cash collection. The payout
  of collected COD money to the merchant is **not documented**. Sandbox providers all report `allowCashOnDelivery:false`.
- **Returns**: "Return Order (A→B→A)" feature. Wolt: no-show after 5 min and
  2 calls → parcel returned to pickup, fee applies. Glovo: 1 attempt + 3 contacts.
- **SMS to the recipient** (T&C 2.4–2.5, read 2026-11-02): when an order is
  placed, the tracking link is sent by SMS to the recipient's number — in
  Quickshipper's name with a standard text, or in the merchant's name and
  text with the paid branded package (3.2.1, 0.20 ₾ + VAT). Whether it can
  be switched off is not written (asked, question 19 of the 2026-11-02 list).
- **Wolt: parcel value ≤ 1,000 ₾ incl. VAT** (Wolt annex at
  https://qshpr.com/ka/post/54, read 2026-11-02): Wolt may refuse a parcel
  whose declared or real value is higher and is not liable above that
  amount. The sandbox still quotes Wolt at `CartAmount` 5000 (live-findings
  2026-11-02). Hiding Wolt for carts above 1,000 ₾ is a possible module
  change — PROPOSED, pending the maintainer.
- **Cancellation**: nothing in Quickshipper's own T&C; the courier's rules apply
  (Wolt: fee once confirmed; Glovo: fee table by stage). API: cancel only if no
  courier is assigned yet or the provider allows it (API docs).
- **Liability (T&C §5.2)**: **no guarantee** for goods, courier quality or timing,
  loss, damage or delay, or courier availability. Claims **via chat within 12 h of the order
  closing**. When a courier accepts compensation, the merchant sends Quickshipper an
  itemised VAT invoice within 12 h, and Quickshipper pays **only after the courier pays it**.
  Contacting the courier directly voids Quickshipper's involvement. The merchant must hand
  over the right parcel (check the order code) and pays for any repeat visit.
- **Insurance**: none offered (marketing word only). Glovo OD requires the
  merchant to hold civil-liability insurance for goods' value.
- **Weight/size**: no Quickshipper limit; each courier has its own (T&C §5.4). Volumetric
  weight L×W×H cm in the dashboard. Blog 2023: >8 kg needs a car at about +25%.
  OnWay 10 kg door pickup (matches our sandbox brackets, max 10 kg).
- **Prohibited items**: courier lists apply. Wolt: illegal, age-verified, cold-chain,
  dangerous, **fragile**, medicines. Glovo: food, groceries or own products only.

## Pricing

Published figures conflict:

| Source | Platform fee per order |
|---|---|
| **T&C §3.1** (2026-02-26 / 03-09) | **0.60 GEL + VAT per order, whatever the courier**; add-ons: branded communication 0.20, ad banner 0.30, Drop-the-Pin 0.20 (+VAT) |
| quickshipper.ge homepage | "Level 1 $0.25/order", Level 2 custom |
| quickshipper.ge/marketplace | Georgia ₾0.54/order |
| Dashboard onboarding | first **10 orders commission-free**, then "Level 1 $0.20" |
| Shopify apps | $0.50/order (+$15/mo Local Delivery) |
| Fleet-management plans | $10 / $99 / $149 / $299 per month (50 / 500 / 1000 / 3000 orders), 30-day trial |

- The API's **`serviceFee: 0.6`** matches the T&C platform fee (whether it
  includes VAT is unknown).
- **Courier price** = the courier's live offer (varies with time, load, weather,
  traffic), shown together with the platform fee. **T&C §2.3/§3.3: the price may change
  after the courier checks the order.** This matters: the checkout-quoted shipping price is not
  guaranteed to be the final charge.
- No markup on courier prices is stated. No API access fee found. Promos have included
  50 free orders (with Plug&Pay) and 30% off for Bank of Georgia SMEs.

## Terms relevant to an integrator

T&C / Service Agreement (https://qshpr.com/en/post/53, 2026-02-26; KA
republished https://qshpr.com/en/post/56, 2026-03-09; accepted by placing an
order or signed through the "Signify" platform):

- Non-exclusive right to use the platform; **API and plugin use explicitly allowed**.
- Consent to data processing through the API integration. §4: confidentiality and
  personal data, **5,000 GEL + VAT fine** per breach. Quickshipper may share data with
  partners and use the merchant's email for direct marketing.
- §2.8: the merchant supplies its own Google Maps, Intercom and SMS accounts.
- §3.8: tariffs may change with advance notice (platform or email).
- 1-year term, auto-renews (1 month's notice to stop); either side may terminate
  on **5 calendar days'** email notice. No assignment without consent.
- Georgian law; disputes go to **arbitration at DRC** (Tbilisi) after negotiation.
- **No API-specific terms: no rate limits, no SLA, no acceptable-use policy.**
- Courier terms (https://qshpr.com/en/post/57) apply once a courier is chosen (§5.5).
- Privacy policy (https://quickshipper.ge/terms) is a courier-app template, undated;
  it allows transfer outside Georgia.

## Contacts

- info@qshpr.com · +995 32 2 502 502 (https://quickshipper.ge/ka/terms)
- In-app Intercom chat — the official channel for claims (T&C §2.9).
- Calendly meeting links for sales/onboarding are in the site JS and https://qshpr.com/en/post/52.
- No dedicated API/tech-support contact, no SLA published.
