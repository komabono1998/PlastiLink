# PlastiLink

*Turning Plastic Waste into Income.* A marketplace connecting waste pickers, collection hubs and recycling companies in Gulu City, Uganda.

**Stack:** PHP 8.2+ (custom MVC, no framework) · PDO · MySQL/MariaDB · Bootstrap 5 · Bootstrap Icons · Chart.js. No Composer or Node needed.

**Status:** Phase 1 (foundation) complete. See "Roadmap".

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

### Useful commands

| Command | Purpose |
|---|---|
| `php migrate.php migrate` | Run pending migrations |
| `php migrate.php status` | Show applied / pending |
| `php migrate.php rollback [n]` | Roll back the last n batches |
| `php tests/phase1_smoke.php` | Run Phase 1 tests (needs Apache running) |

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
2. Authentication and RBAC
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
