# Delivery Button (Express Quickshipper widget)

A hosted UI (map, address, courier choice) that the store opens from its
checkout. Authenticated by the account **ApiKey** (dashboard → Profile →
Integration tab), not by the OAuth token. [docs]

## Setup [docs]

```html
<script src="https://express.quickshipper.ge/assets/quickshipper-express.min.js"></script>
<link rel="stylesheet" href="https://express.quickshipper.ge/assets/quickshipper-express.min.css">
<div id="quickshipper-button-wrapper"></div>
<script>
window.Quickshipper.createButton({
  buttonWrapperId: "quickshipper-button-wrapper",
  // lang: "en" | "ka" …  (read from the script; default "en")
  data: {
    pickupInfo: {city, address, latitude, longitude, name, phonePrefix, phone, addressComment},
    dropInfo:   {…same fields, optional — prefill only},
    apiKey: "<ApiKey>"
  }
});
window.Quickshipper.onOrderCreate = (order) => { … };
window.Quickshipper.onError = (err) => { … };
</script>
```

The merchant must set a Google Maps key in the dashboard for the map to work fully. [docs]

## How the script works [live 2026-09-29 — read from the minified script]

- **Environment is chosen by the script host**: `express.quickshipper.ge` is
  hard-wired to `delivery-test.quickshipper.ge`; `express.quickshipper.app`
  to `delivery.quickshipper.app`.
- On click it calls `GET {API}/v1/Order/express` with the pickup/dropoff data +
  `ApiKey` + `Lang` (+ `CarDelivery`, `CartWeight`), receives a
  `https://express.quickshipper.{ge|app}/createorder?data=…` URL and opens it
  in a **full-screen iframe** `#quickshipper-frame`.
- It listens for `postMessage` events from origins `express.quickshipper.ge`,
  `express.quickshipper.app` (and `localhost:3000/3001`):
  `"modalClosed"` → removes the iframe; `{action: ERROR, data}` →
  `Quickshipper.onError(data)`; `{action: CREATE_ORDER, data}` → removes the
  iframe and calls `Quickshipper.onOrderCreate(data)`.
- The shape of `data` passed to `onOrderCreate` is not documented — capture it
  in a live test before relying on it.

## Order flow scenarios [docs]

1. Customer confirms in the widget → Quickshipper creates the order as
   **ReadyForPickup** and dispatches immediately.
2. Order created as **Draft** → store charges the customer for delivery → on
   successful payment the store calls `POST /v1/Order/confirm`
   (`{orderId, apiKey}` or Bearer) → **ReadyForPickup**.

Which scenario applies is presumably an account setting — open question.

## Magento implications (INFERRED, for design discussion)

- Scenario 2 fits Magento: quote in the widget, place the Magento order, confirm
  server-side **after payment**, never from the browser.
- The browser's `onOrderCreate` result must be re-verified server-side with
  `GET /v1/Order` before trusting price/provider (module standard §9).
- Needs CSP entries for `express.quickshipper.ge|app` (script, style, frame)
  and `delivery(-test).quickshipper.*` (connect).
