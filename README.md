# PlastiLink

*Turning Plastic Waste into Income.* A marketplace connecting waste pickers, collection hubs and recycling companies in Gulu City, Uganda.

**Stack:** PHP 8.2+ (custom MVC, no framework) · PDO · MySQL/MariaDB · Bootstrap 5 · Bootstrap Icons · Chart.js. No Composer or Node needed.

**Status:** Phases 1 (foundation), 2 (authentication and RBAC), 3 (administration and registrations) and 4 (collection hub operations) complete. See "Roadmap".

## Local setup (Windows + XAMPP)

1. Start **Apache** and **MySQL** in the XAMPP control panel. Enable `mod_rewrite` (on by default).
2. Place the project in `C:\xampp\htdocs\PlastiLink`.
3. Copy the config templates (both real files are git-ignored):
   ```
   copy config\app.local.php.example config\app.local.php
   copy config\database.local.php.example config\database.local.php
   ```
4. Create the database and a dedicated user (replace the password, and use the same one in `database.local.php`):
   ```sql
   CREATE DATABASE plastilink_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'plastilink'@'localhost' IDENTIFIED BY 'your-password';
   CREATE USER 'plastilink'@'127.0.0.1' IDENTIFIED BY 'your-password';
   GRANT ALL PRIVILEGES ON plastilink_db.* TO 'plastilink'@'localhost';
   GRANT ALL PRIVILEGES ON plastilink_db.* TO 'plastilink'@'127.0.0.1';
   ```
5. Run migrations:
   ```
   C:\xampp\php\php.exe migrate.php migrate
   ```
6. Open <http://localhost/PlastiLink/>. Health check: <http://localhost/PlastiLink/health>.

### Demo accounts (local/staging only)

```
C:\xampp\php\php.exe seed.php demo-users           # create one DEMO account per role
C:\xampp\php\php.exe seed.php demo-users --reset   # new random passwords
```

Passwords are random and printed once in your terminal; they are never stored in the repo. The command refuses to run when `app.env` is `production`. Sign in at `/login` with the email (or phone number, for the picker) it prints. Delete demo accounts before real use.

### Authentication and access control

- Sign in with a **phone number or email** (`0771234567`, `+256771234567` and email all work) plus password. Passwords use bcrypt (`password_hash`). Minimum length is 8 (6 for waste pickers) plus a common-password blocklist.
- 5 failed sign-ins for one account, or 20 from one IP, within 15 minutes lock sign-in until the window passes. Every attempt is stored in `login_attempts`; sign-ins, failures, lockouts, resets and denied access go to `audit_logs`.
- Account states: `pending`, `active`, `suspended`. Suspension takes effect on the user's next request. Sessions end after 2 hours of inactivity.
- Roles: `super_admin`, `project_manager`, `hub_manager`, `hub_operator`, `waste_picker`, `recycler`. Permissions live in the `permissions` and `role_permissions` tables.
- Routes are protected in `routes/web.php` with `AuthMiddleware`, `RoleMiddleware` and `PermissionMiddleware`. The server decides; hidden menu links are cosmetic only.
- Password reset: `/forgot-password` issues a single-use, 60-minute token (only its SHA-256 hash is stored). Until a real email/SMS provider exists, the message is written to `storage/logs/outbox.log` (the log driver refuses to run in production). Set `app.url` in production so emailed links use your real domain.

### Administration, registration and approvals (Phase 3)

- **Screens** (shown in the sidebar only if your role may open them): `/users`, `/pickers`, `/hubs`, `/recyclers`, `/neighbourhoods`, `/approvals`, `/account/password`.
- **Who can do what:** Super Administrators manage everything. Project Managers view users, pickers and hubs and register/approve recyclers. Hub Managers approve pickers at their hubs. Hub Managers and Operators register pickers in person, for their own hubs only.
- **Hub scoping:** hub staff see only pickers whose preferred hub is one of their assigned hubs (`App\Services\HubScope`). Anything outside it returns 404. National ID is visible only to Super Administrators and Hub Managers, and never in lists. Phone numbers are masked for everyone except Super Administrators.
- **Registration:** staff-registered pickers and recyclers are approved immediately and get a random one-time temporary password (shown once; the person must change it at first sign-in). Self-registration (`/register`, `/register/recycler`) always starts as *pending* and is limited to 5 per hour per IP. Picker consent text and version are stored with the record.
- **Picker IDs** look like `PLP-000123`; hub codes like `HUB-001`.
- **Demo hubs, pickers and recyclers** (labelled DEMO, local/staging only): `C:\xampp\php\php.exe seed.php demo-data` (run `demo-users` first). Safe to re-run.

### Collections, prices and receipts (Phase 4)

- **Screens:** `/collections` (list; pickers see only their own as "My collections"), `/collections/new` (record and weigh), `/collections/{id}`, `/receipts/{id}` (printable), `/prices`.
- **Prices:** one national price per material with an optional per-hub override, set by Super Administrators and Project Managers. Prices are append-only history: a change adds a row and never edits or deletes one. **No real prices are seeded**; set them on `/prices` before recording collections. (The local `demo-data` seeder adds one clearly labelled DEMO price.)
- **Arithmetic:** weights are held as hundredths of a kg and money as whole UGX, using integer maths only (`App\Helpers\Weight`). Net = gross − tare; value = net × price per kg, rounded half-up. The price is copied onto the collection when it is weighed, so later price changes never alter it.
- **Workflow:** `pending` → `approved` | `rejected`, and `approved` → `reversed`. The person who recorded a collection can never approve it (this includes Super Administrators). Approved records are locked; reversal needs `collections.reverse` (Super Administrator, Project Manager) and a reason, and voids the receipt. Nothing is deleted. Re-weighing a pending collection keeps every earlier weighing.
- **Duplicate protection:** each form carries a one-time token with a unique database constraint, so a double click or refresh cannot record a delivery twice.
- **References:** `COL-YYYYMMDD-######` for collections, `RCT-YYYYMMDD-######` for receipts. A receipt keeps its own snapshot (no phone number or national ID) so later edits never change it.
- **Stock and payments:** approving a collection does not yet change stock (Phase 5 builds the stock ledger and back-fills it from approved collections) or pay anyone (Phase 7). Picker pages label the value "not yet paid".
- `php seed.php demo-data` now also adds a DEMO price and 8 DEMO collections (6 approved, 2 pending).

### Useful commands

| Command | Purpose |
|---|---|
| `php migrate.php migrate` | Run pending migrations |
| `php migrate.php status` | Show applied / pending |
| `php migrate.php rollback [n]` | Roll back the last n batches |
| `php tests/phase1_smoke.php` | Run Phase 1 tests (needs Apache running) |
| `php tests/phase2_auth.php` | Run Phase 2 auth/RBAC tests (creates and removes temporary test users) |
| `php tests/phase3_admin.php` | Run Phase 3 administration, registration, approval and hub-scoping tests |
| `php tests/phase4_collections.php` | Run Phase 4 price, collection, approval, receipt and scoping tests |
| `php seed.php demo-data` | Create labelled demo hubs, pickers, recyclers, a price and collections |

`/styleguide` shows the shared design system. It is only registered when `app.env` is not `production`.

## Structure

```
app/          Core (router, DB, view, errors), Controllers, Models, Services, Middleware, Helpers
config/       app.php, database.php (+ git-ignored *.local.php overrides)
database/     migrations/, seeds/
public/       index.php (front controller), assets/, uploads/
resources/    views/ (layouts, components, errors, home, ...)
routes/       web.php
storage/      logs/, receipts/ (not web-accessible)
tests/
```

Request flow: `public/index.php` → bootstrap → CSRF check → `routes/web.php` → Controller → View.

## Conventions

- Escape all dynamic output with `e()`. Use `url()` and `asset()` for links so the XAMPP sub-folder works.
- Database access: PDO prepared statements only (`App\Core\Database`). Multi-record writes use `Database::transaction()`.
- Timestamps are stored in UTC and displayed in `Africa/Kampala` (`format_datetime()`). Money is UGX (`format_ugx()`).
- Errors are logged to `storage/logs/`. Details are shown only when `app.debug` is true.
- CSRF is enforced for every POST/PUT/PATCH/DELETE (`csrf_field()` in forms). Failure returns HTTP 400.

## Security notes

- Never commit `config/*.local.php`. They hold real credentials and are in `.gitignore`.
- `app/`, `config/`, `database/`, `resources/`, `routes/`, `storage/`, `tests/` are blocked from the web by `.htaccess`.
- `public/uploads/` blocks script execution.

## Deploying to Hostinger (summary; full guide in Phase 11)

Point the domain's document root at `public/` (the root `.htaccess` is only for XAMPP), create the database and user in hPanel, add `config/app.local.php` (`env: production`, `debug: false`) and `config/database.local.php` on the server, enable SSL, then run migrations.

## Roadmap

1. **Foundation** ✅
2. **Authentication and RBAC** ✅
3. **Administration and user management** ✅
4. **Collection hub operations** ✅
5. Inventory
6. Recycler marketplace
7. Financial management
8. Reports and analytics
9. External integrations (SMS, USSD, MTN MoMo, Airtel Money; need provider credentials)
10. Geographic and programme monitoring
11. Testing and deployment

PHP extensions `gd`, `zip` and `intl` are not enabled in this XAMPP install. They will be needed for Phase 8 exports.
