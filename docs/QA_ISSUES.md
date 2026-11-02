# QA issues

Problems found while testing the module. Legend: 🔴 blocks use · 🟡 wrong
or confusing, workaround exists · 🟢 cosmetic · ✅ fixed (kept for one
release, then removed).

| # | Issue | Status |
|---|---|---|
| 1 | `qshpr-client-check.php` "timeout from config" reads the store's saved API Timeout and fails on a store that changed it | 🟡 fixed on `fix/client-suite-timeout`, not merged |
| 2 | `qshpr-balance-check.php` "hidden by default" reads the store's saved setting and fails on a store that switched the balance on | 🟡 fixed on `fix/balance-suite-default`, not merged |
| 3 | Invoice page with "Create Shipment": a failed booking after an online capture leaves the money captured; booking before the capture in that path would fix it | 🟡 open — use Ship for Quickshipper orders |
