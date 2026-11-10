# Security

## Controls in place

| Area | Control |
|---|---|
| Access control | 6 roles, database-driven permissions, middleware on every route, a second check inside services, hub scoping in queries. A new route without a reviewed rule fails the security test. |
| Authentication | bcrypt hashes; lockout after repeated failures (per account and per IP); identical messages for unknown user / wrong password / reset; temporary passwords shown once and must be changed; sessions: HttpOnly, SameSite=Lax, Secure on https, strict mode, id regenerated at sign-in, sign-out invalidates server-side |
| CSRF | token on every state-changing request (400 otherwise); only token-authenticated provider callbacks and USSD are exempt |
| Injection | PDO prepared statements everywhere (no request data in SQL text; enforced by test); integer-only money and weights |
| XSS | all output escaped with `e()`; **strict Content-Security-Policy** (`script-src 'self'`, no inline scripts or event handlers, `object-src 'none'`, `frame-ancestors 'self'`, tiles only from the configured map server) |
| Transport | HTTPS redirect and HSTS in production (`deploy/htaccess.production`, `SecurityHeaders`) |
| Headers | CSP, `nosniff`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, COOP, `no-store` for signed-in pages, no `X-Powered-By` |
| Files | `app/`, `config/`, `database/`, `storage/`, `tests/`, backups and dotfiles are blocked by `.htaccess`; directory listing off; the deployment package contains no secrets, tests or demo seeder |
| Secrets | only in `config/*.local.php` (git-ignored, not packaged); test scans tracked files for credential-like strings |
| Money | append-only ledgers, four-eyes approvals, idempotency keys, row locking, provider callbacks never move money (they trigger a re-check with the provider) |
| Privacy | no national IDs or phone numbers in reports/exports; individual pickers never mapped; exports audited |
| Audit | sensitive actions, denials, failed sign-ins, exports, target changes |
| Backups | AES-256-GCM encrypted, checksummed, verified, pruned; restore is a typed-confirmation command |
| Errors | users see a generic page; details go to `storage/logs` only; debug is off in production (`deploy/preflight.php` fails otherwise) |

## How it is tested

`php tests/phase11_security.php` (needs the local web server and database; creates and removes its own data):

1. **Route table walk:** every registered route is classified (public / guest / signed-in) and every signed-in route is requested as each of the six roles. Roles the permissions allow must not get 403 or a server error; every other role must get 403. State-changing routes are also posted to by every role that lacks the permission. Guests are redirected from every protected route.
2. **CSRF:** every POST route with no token, a wrong token and a token from another session → 400.
3. **Headers and CSP compatibility:** headers on public and signed-in pages; every page reachable by the roles is scanned for inline scripts, inline handlers and `javascript:` links.
4. **Sessions and cookies:** flags, new id at sign-in, replay of a cookie after sign-out, attacker-chosen session id.
5. **Authentication abuse:** enumeration (messages), lockout (even with the right password), reset.
6. **Injection:** SQL probes in every query-string field of every page the Super Administrator can open, and in the sign-in and reset forms.
7. **XSS:** a script payload stored in hub, neighbourhood, user, recycler, collection note, price note and audit data; reflected payloads in query strings.
8. **IDOR:** pickers, hub managers and recyclers requesting other people's or hubs' records by id.
9. **Exposure:** 40+ URLs for source, config, git, tests, migrations, storage, backups, traversal and encoding tricks.
10. **Open redirect, mass assignment** (role/status fields on public registration, password change, user routes).
11. **Static checks:** no tracked secrets, no dangerous functions, no request data in SQL text, hashed passwords, production defaults.

Also run: `php tests/run_all.php` (all suites), `php tests/phase11_journey.php` (end-to-end), `php tests/perf_load.php` (load), `php deploy/preflight.php` (server).

## Test log

Record each run here (date, commit, who, result). A release should not go live with an unexplained failure.

| Date | Commit | Run by | Security suite | Journey | Load test | Notes |
|---|---|---|---|---|---|---|
| 2026-11-10 | Phase 11 commit | Claude (automated) | 62 passed, 0 failed | 47 passed, 0 failed | 20,000 collections, 20 concurrent sessions: all pages p95 < 0.4 s | Findings fixed in this run: app was also reachable under /public/ (now blocked); preflight showed locally-tolerated settings as ok (now [skip]). Everything else passed first time after test-script fixes. |

## Known and accepted risks

- **Account enumeration at registration:** the public form says when a phone number or email is already used (needed so people can fix typos). Sign-in and password reset do not reveal accounts.
- **USSD has no PIN:** the caller is identified by the number the network reports. USSD can read the picker's own figures and ask for a small, capped, once-per-session payout that still needs approval and goes only to the registered phone.
- **`style-src 'unsafe-inline'`:** inline `style=` attributes are allowed (they cannot run code). Scripts are strictly same-origin.
- **OpenStreetMap tiles:** a viewer's browser contacts the tile server when they open a map (their IP is visible to it). Switch provider via `integrations.maps.tile_url`, or do not use the map.
- **Shared hosting:** the application trusts the host's PHP and file isolation. Use Layout B (private folder above `public_html`) where possible; keep `config/*.local.php` at mode 640.
- **Provider integrations are tested against local fakes only** until each has been run in its own sandbox.
- **Backups are processed in memory**, which suits a programme of this size; very large databases need the host's own backup tools as well.

## Reporting a vulnerability

Tell the Super Administrator privately (not in a public ticket). Include steps to reproduce and what you saw. Do not test against live data.
