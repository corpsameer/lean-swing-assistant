<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Market Data JSON Ingestion (T04)

Use the Artisan command below to ingest Python-fetched daily bars JSON from disk (a sample file is included at `storage/app/sample_daily_bars.json`):

```bash
php artisan market:ingest-json storage/app/sample_daily_bars.json --snapshot=daily
```

Expected top-level payload fields:
- `mode`
- `fetched_at_utc`
- `symbols` (array of symbol objects with at least `symbol` and `status`)

Behavior:
- creates/reuses `symbols` by ticker symbol
- stores one `market_snapshots` row per symbol payload (including status `error` payloads)
- stores `mode`, `fetched_at_utc`, and raw per-symbol data in `payload_json`
- prints summary counts for processed symbols, success/error statuses, and snapshots stored

Path handling notes:
- accepts absolute paths
- accepts paths relative to `laravel_app/`
- accepts `storage`/`storage/app`-relative paths

### Refreshing `sample_daily_bars.json` with real IBKR history

`storage/app/sample_daily_bars.json` is a checked-in example fixture. To store more (real) bars, overwrite it using the Python fetch script output:

```bash
cd ../python_ibkr
python scripts/fetch_daily_bars.py AAPL MSFT NVDA --output ../laravel_app/storage/app/sample_daily_bars.json
```

This preserves the existing ingestion JSON shape while writing a larger per-symbol `bars[]` history into the same file path.

## Daily Metrics Compute Command (T05)

Compute deterministic daily strategy metrics from the latest ingested `daily` snapshot per symbol:

```bash
php artisan metrics:compute-daily
```

Optional filters:

```bash
php artisan metrics:compute-daily --symbol=AAPL
php artisan metrics:compute-daily --limit=20
```

Behavior:
- creates one minimal `runs` record (`run_type=compute_daily_metrics`)
- reads latest `market_snapshots` row with `snapshot_type=daily` for each symbol
- skips symbols with missing/invalid/insufficient bars
- stores metrics as new `market_snapshots` rows with `snapshot_type=derived_daily_metrics`
- prints summary counts: scanned, computed, skipped, errors

Trend-state rule (deterministic):
- `uptrend` if price is above 50-day midpoint and closer to 50-day high than low
- `downtrend` if price is below 50-day midpoint and closer to 50-day low than high
- otherwise `neutral`

## Weekend Prompt A Rank Command (T07)

Rank the latest weekend candidates with one batch OpenAI call:

```bash
php artisan prompt:weekend-rank
```

Behavior:
- creates one `runs` row with `run_type=weekend_prompt_rank`
- loads the latest `watchlist_candidates` rows where `stage=weekend`
- loads each symbol's latest `derived_daily_metrics`
- sends one structured Prompt A payload to OpenAI
- stores request/response in `prompt_logs` (`prompt_type=A`, batch row with nullable `symbol_id`)
- updates matching `watchlist_candidates` rows (`score_total`, optional `setup_type`, `reasoning_text`, `prompt_output_json`)
- prints concise summary counts: sent, ranked, updated, errors

Model note:
- the command does not force `temperature`, which avoids 400 errors on models that only support default temperature behavior (e.g., `OPENAI_MODEL=gpt-5`)

## Intraday Prompt C Validate Command (T09)

Validate active daily-refined candidates for intraday entry readiness with one batch OpenAI call:

```bash
php artisan prompt:intraday-validate
```

Behavior:
- creates one `runs` row with `run_type=intraday_validate`
- loads latest `watchlist_candidates` per symbol where `status` is `keep` or `wait` (latest = highest candidate id)
- loads latest `derived_daily_metrics` and `intraday` snapshot payloads per symbol
- applies deterministic eligibility checks before model call:
  - requires trigger bands
  - requires current price within band or near band
  - skips already-extended symbols via config thresholds
  - skips symbols that already have `planned`/`open` setups
- sends one structured Prompt C payload for eligible symbols only
- stores request/response in `prompt_logs` (`prompt_type=C`, batch row with nullable `symbol_id`)
- creates `trade_setups` (`status=planned`) only for `decision=enter_now` and no active duplicate setup
- updates candidate `reasoning_text` and `prompt_output_json` from model output
- prints concise summary counts: active scanned, sent, enter_now, wait, reject, trade setups created, errors

Config knobs (optional):
- `INTRADAY_NEAR_BAND_TOLERANCE_PERCENT` (default: `0.75`)
- `INTRADAY_MAX_EXTENSION_PERCENT` (default: `1.5`)

### Minimal intraday data path for T09

Generate intraday JSON for only active symbols, then ingest as `intraday` snapshots:

```bash
cd ../python_ibkr
python scripts/fetch_intraday_data.py AAPL MSFT --output ../laravel_app/storage/app/intraday_snapshot.json

cd ../laravel_app
php artisan market:ingest-json storage/app/intraday_snapshot.json --snapshot=intraday
php artisan prompt:intraday-validate
```

Notes:
- keeps the same Python -> JSON -> Laravel ingestion pattern as daily bars
- stores one `market_snapshots` row per symbol payload with `snapshot_type=intraday`
- T09 reads latest intraday snapshot fields including `current_price`, `session_high`, `session_low`, and nullable `intraday_vwap`
