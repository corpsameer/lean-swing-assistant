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
```

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

## Safety

- `IBKR_MODE=live` is blocked unless `LIVE_TRADING_ENABLED=true`.
- `TRADING_MODE` is supported as a fallback for compatibility with T01.
