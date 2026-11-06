# PlastiLink

*Turning Plastic Waste into Income.* A marketplace connecting waste pickers, collection hubs and recycling companies in Gulu City, Uganda.

**Stack:** PHP 8.2+ (custom MVC, no framework) · PDO · MySQL/MariaDB · Bootstrap 5 · Bootstrap Icons · Chart.js. No Composer or Node needed.

**Status:** Phases 1 (foundation) and 2 (authentication and RBAC) complete. See "Roadmap".

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

### Useful commands

| Command | Purpose |
|---|---|
| `php migrate.php migrate` | Run pending migrations |
| `php migrate.php status` | Show applied / pending |
| `php migrate.php rollback [n]` | Roll back the last n batches |
| `php tests/phase1_smoke.php` | Run Phase 1 tests (needs Apache running) |
| `php tests/phase2_auth.php` | Run Phase 2 auth/RBAC tests (creates and removes temporary test users) |

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
3. Administration and user management
4. Collection hub operations
5. Inventory
6. Recycler marketplace
7. Financial management
8. Reports and analytics
9. External integrations (SMS, USSD, MTN MoMo, Airtel Money; need provider credentials)
10. Geographic and programme monitoring
11. Testing and deployment

PHP extensions `gd`, `zip` and `intl` are not enabled in this XAMPP install. They will be needed for Phase 8 exports.
