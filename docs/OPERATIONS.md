# Operating PlastiLink

## Daily / weekly / monthly

| Task | How often | How |
|---|---|---|
| Check `/health` | continuous (uptime monitor, e.g. a free monitor pointed at `https://yourdomain/health`) | alert if it is not `{"status":"ok"}` |
| Review the approvals queue | daily | `/approvals` |
| Review pending payouts and reconciliation | daily | `/payouts?status=pending_approval`, `/finance/reconciliation` |
| Check the **ledger checks** | weekly | `/finance` (money) and `/inventory` (stock) must say "passed" |
| Check failed SMS and stuck payments | weekly | database: `sms_outbox` with `status = 'failed'`; payments left `processing` |
| Check backups exist and were verified | weekly | `php backup.php list`, read the cron output |
| Copy a backup off the server | at least weekly | see below |
| Practise a restore | quarterly | see "Restoring" |
| Review audit trail for odd activity | monthly | `/admin/audit` (failed sign-ins, access denials, exports, target changes) |
| Review users | monthly | `/users`: suspend leavers, check roles |
| Apply updates | as released | `docs/DEPLOYMENT.md` section 11 |

## Scheduled jobs

See `docs/DEPLOYMENT.md` section 9. If a job stops running: messages stay queued (nothing is lost), payments stay "processing" (they are not paid twice), invoices are also issued whenever an order is closed.

## Backups

`php backup.php run` writes to `storage/backups/` (not web-accessible):

- `plastilink-YYYYMMDD-HHMMSS.sql.gz.enc` — the whole database, encrypted with `app.backup.key` (AES-256-GCM)
- `…-files.zip.enc` — uploaded files (receipts, uploads)
- `….manifest.json` — table counts, checksums, control totals (no personal data)

It is taken inside a consistent snapshot, verified straight away, and old backups are pruned (14 daily + 8 weekly, `backup.keep_daily` / `keep_weekly`).

**Backups on the same server are not enough.** If the account is deleted, hacked or the disk fails, they go too. At least weekly, copy the newest set somewhere else: download it with hPanel File Manager / SFTP to a laptop that is itself backed up, or to cloud storage you control. Because the files are encrypted, a lost copy does not expose personal data, **but the key matters**: keep `app.backup.key` in a password manager that is *not* on the server. **Without the key a backup cannot be restored.**

Check a backup at any time: `php backup.php verify [set]`. On hosts where the database user cannot create a scratch database it does a structural check only and says so; do a full restore rehearsal on your own computer at least once.

### Restoring

1. **Stop the damage first**: put the site in maintenance (rename `public/index.php` temporarily, or ask hPanel to disable the site) so nobody writes new data.
2. Take a fresh backup of the *current* state if the database is still readable (`php backup.php run`): you may want it later.
3. Choose the backup set: `php backup.php list`.
4. Prove it first: `php backup.php verify plastilink-20261107-020001`.
5. Restore (destructive: replaces the live database; you must type the database name):
   ```bash
   php backup.php restore plastilink-20261107-020001 --yes-overwrite=u123456789_plastilink
   ```
6. The same command also restores the uploaded files (receipts, uploads) from the matching `…-files.zip`. Their names are checked so nothing can be written outside those two folders.
7. Run `php migrate.php migrate`, `php deploy/preflight.php`, open `/finance` and `/inventory` and confirm both ledger checks pass, then bring the site back.
8. Write down what happened, what data was lost (everything since the backup) and tell the affected hubs so they can re-enter or reconcile.

## Incidents

**A device or account may be compromised**
Suspend the user (`/users` → Suspend: they are signed out on their next request), reset their password, and review `/admin/audit` for that user and time period. If a Super Administrator account is involved, change all passwords, rotate `app.backup.key` and any provider keys, and review exports (`report.exported` entries).

**Provider keys leaked** (MTN, Airtel, Africa's Talking, webhook token)
Revoke/rotate in the provider's portal first, then update `config/app.local.php`. Review recent payments and SMS.

**A payment looks wrong**
Never edit the database by hand. Payments and ledger entries are append-only: reverse with a reason (`/payments/{id}` → Reverse), which posts an opposite entry and keeps the history. Run the ledger check.

**The ledger check or stock check fails**
Stop recording until understood. The failing lines name the account or hub/material. Compare with the latest verified backup; do not "fix" balances by editing rows. Ask for a developer review.

**Site down**
`/health` shows whether the database answers. Check hPanel for PHP version changes (the app needs 8.2+), disk space, and `storage/logs/` for the error. The public error page never shows details; the log does.

## What is stored where

| Data | Where | Notes |
|---|---|---|
| People, collections, money, stock | database | backed up nightly |
| Receipts, uploaded files | `storage/receipts`, `public/uploads` | backed up in the files archive |
| Passwords | database, hashed (bcrypt) | never readable, not in backups in clear |
| Secrets and keys | `config/*.local.php` on the server only | not in git, not in the package, not in backups |
| Logs | `storage/logs` | errors; no passwords or payment details |
| Audit trail | database (`audit_logs`) | append-only record of sensitive actions |

## Data protection housekeeping

See `docs/DATA_PROTECTION.md`: consent text, who can see what, retention and what to do when someone asks for their data to be corrected or removed.
