# Python IBKR Connector (T03)

Minimal IBKR connector skeleton for:
- env-based mode resolution (`paper`/`live`)
- safe live-mode guard
- connection test
- daily bars fetch with stable JSON output

## Files

- `app/config.py`: env loading + mode/endpoint resolution
- `app/ibkr_client.py`: tiny IBKR wrapper (connect + daily bars)
- `scripts/test_connection.py`: connectivity smoke test
- `scripts/fetch_daily_bars.py`: fetch daily bars for one or more symbols
- `scripts/fetch_intraday_data.py`: fetch intraday snapshot data for one or more symbols

## Setup

```bash
cd python_ibkr
python -m venv .venv
# Git Bash
source .venv/Scripts/activate
# or Windows cmd
# .venv\Scripts\activate.bat
pip install -r requirements.txt
cp .env.example .env
```

## Usage

### 1) Connection test

```bash
python scripts/test_connection.py
```

This prints success/failure and resolved `mode`, `host`, `port`, and `client_id`.

### 2) Fetch daily bars

```bash
python scripts/fetch_daily_bars.py AAPL MSFT
python scripts/fetch_daily_bars.py AAPL --lookback-days 10 --output bars.json
python scripts/fetch_daily_bars.py AAPL MSFT NVDA --output ../laravel_app/storage/app/sample_daily_bars.json
```

By default, the fetch uses a 90-day daily-bar window. Any lower `--lookback-days` value is internally floored to a 60-day minimum so downstream metric calculations have enough history.

JSON shape:

```json
{
  "mode": "paper",
  "fetched_at_utc": "2026-01-01T00:00:00Z",
  "symbols": [
    {
      "symbol": "AAPL",
      "status": "ok",
      "bars": [
        {
          "datetime_utc": "2026-01-01T00:00:00Z",
          "open": 1.0,
          "high": 1.0,
          "low": 1.0,
          "close": 1.0,
          "volume": 123
        }
      ]
    }
  ]
}
```

### 3) Fetch intraday snapshots

```bash
python scripts/fetch_intraday_data.py AAPL MSFT
python scripts/fetch_intraday_data.py AAPL --bar-size "1 min" --duration "1 D" --output intraday.json
python scripts/fetch_intraday_data.py AAPL MSFT --output ../laravel_app/storage/app/intraday_snapshot.json
```

JSON shape:

```json
{
  "mode": "paper",
  "fetched_at_utc": "2026-01-01T00:00:00Z",
  "snapshot_type": "intraday",
  "symbols": [
    {
      "symbol": "AAPL",
      "status": "ok",
      "snapshot_type": "intraday",
      "metrics": {
        "current_price": 184.7,
        "session_high": 185.2,
        "session_low": 182.4,
        "intraday_vwap": 184.1
      },
      "bars": [
        {
          "datetime_utc": "2026-01-01T14:30:00Z",
          "open": 184.1,
          "high": 184.4,
          "low": 183.9,
          "close": 184.2,
          "volume": 12345
        }
      ]
    }
  ]
}
```

## Safety

- `IBKR_MODE=live` is blocked unless `LIVE_TRADING_ENABLED=true`.
- `TRADING_MODE` is supported as a fallback for compatibility with T01.

## Troubleshooting connection refused

If `test_connection.py` fails with connection refused:

- Verify the running app and port pairing:
  - TWS paper: `7497`, TWS live: `7496`
  - IB Gateway paper: `4002`, IB Gateway live: `4001`
- In TWS/IB Gateway API settings, enable socket clients and ensure localhost (`127.0.0.1`) is allowed.
- If both TWS and IB Gateway are running, make sure `.env` points to the one you intend to hit.
