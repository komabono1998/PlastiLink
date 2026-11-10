# Deploying PlastiLink to Hostinger

This is a step-by-step runbook. It assumes a Hostinger plan with **PHP 8.2+, MariaDB/MySQL, SSL and cron jobs** (all Premium/Business/Cloud shared plans have them). Wherever hPanel's menu names differ slightly from this text, look for the same idea.

> PlastiLink handles money and personal data. **Do a full rehearsal on a staging address first** (a free subdomain such as `staging.yourdomain`), run the UAT scripts in `docs/UAT.md` there, and only then repeat these steps for the live domain.

## 0. Before you start

You need: the domain pointing at Hostinger, the package from `php deploy/build.php` (see step 1), and a safe place to keep secrets (a password manager). Decide who will be the first Super Administrator (name, email, phone).

## 1. Build the package (on your own computer)

```bash
php deploy/build.php
```

This creates `deploy/dist/plastilink.zip` and `deploy/dist/SHA256SUMS.txt`. The zip contains only what the server needs: **no** tests, docs, `.git`, demo seeder, local configuration, logs, backups or uploaded files. Keep the checksum so you can confirm the upload arrived intact.

*Layout A (default, simplest):* one zip, unpacked into the domain's `public_html`. The root `.htaccess` sends requests to `public/` and blocks every internal folder.
*Layout B (more private):* `php deploy/build.php --split` creates `plastilink-private.zip` (goes in a folder **next to** `public_html`, called `plastilink`) and `plastilink-public.zip` (goes **inside** `public_html`). Nothing but the front controller and static files is then reachable from the web at all. Prefer B if your plan lets you place folders outside `public_html`.

## 2. Create the database (hPanel → Databases → MySQL Databases)

1. Create a database and a database user with a **long random password**. Note the three values (database name, user name, password). Hostinger prefixes them (for example `u123456789_plastilink`).
2. Give the user **all privileges on that database only**.
3. Note the database host (usually `localhost`).

## 3. Choose PHP 8.2 and the extensions (hPanel → Advanced → PHP Configuration)

Select **PHP 8.2 or newer**. Make sure these extensions are ticked: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `zlib`, `json`, `ctype`. Set `display_errors` to **Off**.

## 4. Upload and unpack

1. hPanel → File Manager (or FTP/SFTP). For Layout A upload `plastilink.zip` into `public_html` and **Extract** it there. For Layout B upload `plastilink-private.zip` next to `public_html` (rename the extracted folder to `plastilink`) and `plastilink-public.zip` into `public_html`.
2. Delete the zip files after extracting.
3. Confirm the files arrived intact: compare the checksum of the zip you uploaded with `SHA256SUMS.txt` (hPanel's terminal: `sha256sum plastilink.zip`) before you delete it.

## 5. Configuration (secrets stay on the server)

1. In the app folder, copy `config/app.production.php.example` to **`config/app.local.php`** and fill it in: `url` (your real https address), `backup.key` (random, 32+ characters), and the SMS / payment settings only if you are ready to use them.
2. Copy `config/database.local.php.example` to **`config/database.local.php`** and enter the database name, user and password from step 2. Use `localhost` as host unless hPanel says otherwise.
3. Set file permissions: both `.local.php` files **640**, folders **755**, `storage/` and its subfolders writable by your account (**750** or **755**).
4. Never put these values in the code, in git, in a ticket or in an email. The package does not contain them.

## 6. SSL

hPanel → Security → SSL: install/enable the free SSL for the domain and turn on **Force HTTPS**. The shipped `.htaccess` also redirects to HTTPS and sends HSTS.

## 7. Create the tables and the first administrator (hPanel → Advanced → SSH, or "Run a PHP script" / cron one-off if your plan has no SSH)

```bash
cd path/to/app           # the folder containing migrate.php
php migrate.php migrate
php create-admin.php --email=you@yourdomain.org --name="Your Name" --phone=07XXXXXXXX
```

The second command prints a one-time temporary password. Sign in with it and choose a new password immediately. **Never run `seed.php` on the server**: it creates demo accounts with known data and is not part of the package.

If your plan has **no SSH**: ask Hostinger support to enable SSH, or run the two commands once as cron jobs ("run once") and read the output from the cron email/log, then delete those cron entries.

Then set the real data in the web app: neighbourhoods and their coordinates, hubs, hub staff, plastic prices (`/prices`), recyclers. **Do not leave the default demo prices.**

## 8. Pre-flight check

```bash
php deploy/preflight.php
```

(`deploy/preflight.php` is the only file of the `deploy/` folder that is shipped. If you cannot run PHP commands on your plan, check by hand: `env` is `production`, `debug` is `false`, https works, `/health` says `ok`, and the URLs in the checklist below are refused.) Fix every `[FAIL]` before opening to users. A `[WARN]` is a judgement call you should be able to explain (for example "SMS is not delivered because no gateway is configured").

## 9. Scheduled jobs (hPanel → Advanced → Cron Jobs)

Use the full path to PHP 8.2 (hPanel shows it, for example `/usr/bin/php`) and your app path.

| When | Command | What it does |
|---|---|---|
| every minute (or every 2 minutes) | `php /home/USER/…/jobs.php send-sms` | sends queued text messages |
| every 5 minutes | `php …/jobs.php poll-payments` | settles mobile-money payouts that are still "processing" |
| every 15 minutes | `php …/jobs.php expire-orders` | expires unanswered marketplace negotiations |
| every hour | `php …/jobs.php generate-invoices` | safety net for invoices |
| every night at 02:00 | `php …/backup.php run` | encrypted database + files backup, verified, old ones pruned |

## 10. Post-deploy checklist

- [ ] `https://yourdomain/health` shows `{"status":"ok", ...}`; `http://` redirects to `https://`.
- [ ] `https://yourdomain/config/app.php`, `/.git/config`, `/storage/`, `/migrate.php`, `/backup.php` all answer 403 or 404.
- [ ] Sign in as the Super Administrator, change the password, create a Hub Manager and a Hub Operator, sign in as each.
- [ ] Record one real test collection with a real scale reading and approve it as a second person; check receipt, earnings, stock.
- [ ] `php backup.php run` works; `php backup.php list` shows it; **copy the file off the server** (see `docs/OPERATIONS.md`).
- [ ] The cron jobs above are listed in hPanel.
- [ ] You can describe where the backup key copy is kept and who has it.

## 11. Updating later

1. Build a new package. Take a **fresh backup first** (`php backup.php run`) and copy it off the server.
2. Upload the new zip and extract it over the old files. **Do not delete `config/*.local.php`, `storage/` or `public/uploads/`** (the package does not contain them, so extracting over them is safe).
3. `php migrate.php migrate` then `php deploy/preflight.php`; open the site and run the smoke checks from step 10.

## 12. Rolling back

If an update misbehaves: re-extract the previous package over the files. If a **migration** was applied and must be undone, restore the pre-update backup (`docs/OPERATIONS.md`, "Restoring"): `php migrate.php rollback` is for development and should not be used on live data.

## Going live with real providers

SMS (Africa's Talking), MTN MoMo and Airtel Money are off by default. Switch each on **separately**, in its own sandbox first, with small real amounts, and only after the provider has confirmed your account. See "External integrations" in the README. Until then payouts are recorded manually and no SMS is delivered.
