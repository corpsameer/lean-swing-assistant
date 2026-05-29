# VPS Cron + Logging Setup (T14.4)

This document defines the **single cron entry** and logging setup needed to run the Laravel scheduler continuously on VPS.

## Target VPS Paths

Expected deploy layout:

```text
/var/www/lean-swing-assistant/
    laravel_app/
    python_ibkr/
```

Laravel app path:

```text
/var/www/lean-swing-assistant/laravel_app
```

Python IBKR path:

```text
/var/www/lean-swing-assistant/python_ibkr
```

## Single Required Cron Entry

Use **one OS cron entry only**:

```cron
* * * * * cd /var/www/lean-swing-assistant/laravel_app && php artisan schedule:run >> /var/www/lean-swing-assistant/laravel_app/storage/logs/scheduler-cron.log 2>&1
```

Notes:
- This one cron line triggers Laravel scheduler every minute.
- Laravel scheduler decides which command(s) should run at that minute.
- Do **not** create separate OS cron entries per command.

## Scheduler Lifecycle

Final scheduler lifecycle:

- Sunday 17:00 ET: `universe:build-nasdaq`
- Sunday 18:00 ET: `workflow:weekend-scan`
- Weekdays 05:30 ET: `workflow:daily-refine`
- US weekdays 09:30-15:45 ET: `prompt:intraday-validate`
- US weekdays 09:30-16:10 ET: `trades:simulate-status`
- Weekdays 16:30 ET: `prompt:trade-review --limit=20`

Heavy universe/workflow jobs run outside intraday market workflows and use extended overlap locks.

## Scheduler Log Files

Expected scheduler-related logs:

- `storage/logs/scheduler-nasdaq-universe.log`
- `storage/logs/scheduler-weekend-scan.log`
- `storage/logs/scheduler-daily-refine.log`
- `storage/logs/scheduler-intraday-validate.log`
- `storage/logs/scheduler-simulate-status.log`
- `storage/logs/scheduler-trade-review.log`
- `storage/logs/scheduler-cron.log`

Scheduler command logs are configured through Laravel `storage_path('logs/...')` output paths.

## VPS `.env` Notes

Set/confirm values in `laravel_app/.env` for VPS deployment:

```dotenv
APP_ENV=production
APP_DEBUG=false

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=lean_swing_assistant
DB_USERNAME=...
DB_PASSWORD=...

EXECUTION_DRIVER=simulated
EXECUTION_ENABLED=true
BROKER_TRADING_MODE=paper
LIVE_TRADING_ENABLED=false

WORKFLOW_SYMBOLS=AAPL,MSFT,NVDA
PYTHON_IBKR_BASE_PATH=/var/www/lean-swing-assistant/python_ibkr
EXECUTION_PYTHON_EXECUTABLE=/var/www/lean-swing-assistant/python_ibkr/venv/bin/python
```

## Ownership + Permissions

Required:
- `laravel_app/storage` must be writable.
- `laravel_app/bootstrap/cache` must be writable.
- `.env` must not be committed and must not be publicly exposed.

Common setup (adjust user/group for your server):

```bash
chmod 600 .env
chown -R www-data:www-data storage bootstrap/cache
```

Ownership may vary depending on deployment user/process (for example, non-`www-data` setups).

## VPS Validation Commands

Run from `laravel_app`:

```bash
cd /var/www/lean-swing-assistant/laravel_app
php artisan optimize:clear
php artisan schedule:list
php artisan universe:build-nasdaq
php artisan workflow:weekend-scan
php artisan workflow:daily-refine
php artisan prompt:intraday-validate
php artisan trades:simulate-status
php artisan prompt:trade-review --limit=20
php artisan schedule:run
```

## Cron Verification Commands

```bash
crontab -l

tail -f storage/logs/scheduler-cron.log
tail -f storage/logs/scheduler-intraday-validate.log
tail -f storage/logs/scheduler-simulate-status.log
```

## Safety Notes (Current Phase)

- Keep `EXECUTION_DRIVER=simulated` on VPS for now.
- Do **not** enable IBKR live execution.
- Keep `LIVE_TRADING_ENABLED=false`.
- Nasdaq universe build is scheduled Sunday 17:00 `America/New_York`.
- Weekend scan is scheduled Sunday 18:00 `America/New_York`.
- `universe:build-ibkr` remains available for manual testing only; it is not scheduled.
- T15 exit outcome/PnL tracking runs through `trades:simulate-status`.
- Command-level guards also enforce the intraday validation and simulated status market windows, so unexpected cron/scheduler timezone behavior skips safely outside those windows.
- T16 trade reviews run automatically via `prompt:trade-review --limit=20` (16:30 `America/New_York`, after the 16:10 final simulated status sync).
- IBKR Gateway/TWS setup is handled separately in **T14.5**.
- Scheduler can run the simulated system once data connectivity is available.
