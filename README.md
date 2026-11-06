# Shubo_Qshpr — Quickshipper delivery for Magento 2

A shipping carrier for Magento Open Source 2.4 that connects a Georgian shop
to **Quickshipper** (qshpr.com), the delivery aggregator behind Wolt, Glovo,
OnWay and other couriers in Georgia.

- **Checkout:** one delivery option per courier and speed, priced by
  Quickshipper for the customer's exact address, with the courier's logo.
  The module finds the address on the map itself (OpenStreetMap Nominatim by
  default, free) and only offers couriers when the address is found to the
  building.
- **Booking:** Submit Shipment in the admin books the chosen courier with a
  fresh price and puts Quickshipper's order number and tracking link on the
  shipment. Optionally, paid orders are booked and shipped automatically.
- **Following:** delivery statuses arrive by webhook (one button to connect)
  and by a polling cron; they appear as shipment notes and in the customer's
  Track popup. Cancel and "Book Courier Again" buttons on the shipment page;
  optional cancel on full refund.
- **Also:** who pays Quickshipper's service fee (customer or shop), no cash on
  delivery (Quickshipper has no such service: cash payment is hidden for its
  couriers), car delivery by a product
  attribute you choose / weight / default, the prepaid balance in the admin
  header, Georgian translation.

## Requirements

- Magento Open Source 2.4.9 (Luma checkout; Hyvä is not supported), PHP
  8.3–8.5.
- Base currency **GEL**, shipping to Georgia.
- A Quickshipper account with API access. For testing, a test account at
  test.quickshipper.ge (sandbox). No separate API credentials are needed:
  the module uses Quickshipper's standard API client with your dashboard
  login.

## Install

```
# from the Magento root
mkdir -p app/code/Shubo && git clone https://github.com/nshubitidze/shubo-qshpr.git app/code/Shubo/Qshpr
# or: composer require shubo/module-qshpr (when installed from a Composer repository)

bin/magento module:enable Shubo_Qshpr
bin/magento setup:upgrade
bin/magento setup:di:compile          # production mode
bin/magento setup:static-content:deploy   # production mode
bin/magento cache:flush
```

Magento cron must run: booking clean-up, automatic booking and status polling
are cron jobs.

## Configure

**Stores → Configuration → Sales → Delivery Methods → Quickshipper**

1. Enabled, Sandbox Mode (Yes for a test account), Login Email and Password
   of your Quickshipper dashboard (API Client ID and Secret stay empty unless
   Quickshipper gives you your own).
2. Pickup Latitude / Longitude of the place couriers collect from.
3. **Stores → Configuration → Sales → Shipping Settings → Origin**: street and
   city of the pickup point; **General → Store Information**: store name and
   phone. Quickshipper needs all of them.
4. Optional: Book Courier Automatically, Cancel Delivery on Full Refund,
   Customer Pays Quickshipper's Service Fee, courier logos, car delivery,
   balance in the admin header, the geocoder (a self-hosted Nominatim is
   recommended for busy shops).
5. Press **Connect status updates** (Delivery Status Updates) (the store must be
   reachable from the internet) for statuses within seconds; without it they
   are checked every 15 minutes.

Every setting has an explanation under it in the admin.

## Good to know

- Quickshipper accounts are prepaid: a booking is refused when the balance is
  too low.
- Cancelling a delivery returns its price; Quickshipper may keep its service
  fee.
- Shipments created through the REST API or an ERP do not book a courier.
- Log: `var/log/shubo_qshpr.log`.

## Development

Architecture, decisions, code conventions and how to change the module are
in `.claude/skills/shubo-qshpr-carrier/` (written for both people and AI
coding assistants). Quickshipper API notes are in
`.claude/skills/quickshipper-integration-skill/`.

## License

Apache License 2.0 — see `LICENSE`. Quickshipper, Wolt, Glovo and OnWay are
trademarks of their owners; this module is not affiliated with them.
