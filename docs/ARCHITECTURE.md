# Architecture

PlastiLink is a small custom PHP 8.2 MVC application: no framework, no Composer, no Node. Everything the browser needs (Bootstrap, Bootstrap Icons, Chart.js, Leaflet) is bundled in `public/assets/vendor`.

```
Request -> public/index.php (security headers, session, CSRF) -> routes/web.php -> Middleware (auth, permission)
        -> Controller (thin: read input, call a service, pick a view) -> Service (rules) -> Model (SQL) -> MariaDB
```

| Folder | Contents |
|---|---|
| `app/Core` | Router, Request, Session, Database (PDO), View, ErrorHandler, SecurityHeaders, Config |
| `app/Middleware` | `AuthMiddleware`, `PermissionMiddleware`, `AnyPermissionMiddleware`, `RoleMiddleware`, `GuestMiddleware` |
| `app/Controllers` | one per area; they never contain business rules |
| `app/Services` | the rules: collections, stock, payouts, payments, invoices, orders, reports, exports, SMS, USSD, monitoring, backups |
| `app/Models` | SQL only (prepared statements) |
| `app/Helpers` | formatting, validation, money/weight/phone/geo helpers |
| `resources/views` | PHP templates; every dynamic value goes through `e()` |
| `database/migrations` | numbered, reversible schema changes (`php migrate.php migrate`) |
| `tests` | PHP CLI suites (`php tests/run_all.php`) |
| `deploy`, `docs` | packaging, pre-flight check, runbooks (not shipped except `deploy/preflight.php`) |

## Principles

1. **Ledgers, not balances.** Stock (`stock_movements` → `inventory`) and money (`financial_ledger`) are append-only. Only `StockService` and `LedgerService` write them; DB constraints and "ledger check" screens prove the totals. Mistakes are fixed by reversing entries with a reason, never by editing.
2. **Integers for money and weight.** UGX in whole shillings; weights in hundredths of a kilogram; rounding half-up in one place.
3. **State machines in services.** Collections, orders, transfers, dispatches, payments, invoices each have explicit allowed transitions.
4. **Four eyes.** The person who records is never the person who approves.
5. **Defence in depth.** Routes check permissions in middleware; services check again; the database has constraints; scope (hub) rules are enforced in queries.
6. **Idempotency.** One-time submission tokens with unique constraints stop double submits; payment attempts have their own references so a retry can never pay twice.
7. **Providers behind interfaces.** `PaymentProvider` (manual, mock, MTN, Airtel), `SmsGateway` (log, mock, Africa's Talking). Callbacks only trigger a re-check; they never move money.
8. **Nothing invented.** Reports show recorded figures; impact conversions exist only if the programme configures and sources them; no coordinates are guessed.

## Data model (main tables)

users / roles / permissions / role_permissions · neighbourhoods / collection_hubs / hub_users · pickers / recyclers · plastic_categories / price_lists · collection_transactions / weighing_records / receipts · inventory / stock_movements / stock_transfers / stock_dispatches / stock_adjustments · purchase_orders / purchase_order_items / order_offers · financial_ledger / payment_requests / payments / payment_attempts / invoices · sms_outbox / webhook_log · audit_logs / login_attempts / password_resets / system_settings

## Security architecture

See `docs/SECURITY.md`. In short: CSRF token on every POST; sessions HttpOnly + SameSite=Lax (+ Secure on https), regenerated at sign-in; strict CSP (no inline scripts); prepared statements everywhere; escaping on output; lockouts and rate limits; callbacks authenticated by secret token and re-verified with the provider; secrets only in `config/*.local.php`.

## Extending

New feature = migration + model + service (rules and permission check) + controller + view + route with middleware + tests. Add the route to a permission, add a row to the access matrix test (`tests/phase11_security.php` fails on any route without a reviewed rule), and a section to the UAT script.
