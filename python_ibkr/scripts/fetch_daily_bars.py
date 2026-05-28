from __future__ import annotations

import argparse
import json
import sys
from datetime import UTC, datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from app.config import load_settings
from app.ibkr_client import IBKRClient


def classify_ibkr_error(errors: list[dict[str, object]]) -> str | None:
    for row in errors:
        code = int(row.get("error_code", 0) or 0)
        message = str(row.get("error_message", "") or "").lower()
        if "different ip address" in message or "duplicate" in message:
            return "duplicate_session"
        if code == 162:
            return "ibkr_error_162"

    return None


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Fetch daily bars from IBKR for one or more symbols.")
    parser.add_argument("symbols", nargs="+", help="Ticker symbols, e.g. AAPL MSFT")
    parser.add_argument("--lookback-days", type=int, default=90)
    parser.add_argument("--output", type=str, default="")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    try:
        settings = load_settings()
    except Exception as exc:
        print(json.dumps({"error": str(exc)}))
        return 1

    payload = {
        "mode": settings.mode,
        "fetched_at_utc": datetime.now(UTC).isoformat().replace("+00:00", "Z"),
        "symbols": [],
    }

    client = IBKRClient(settings)
    try:
        client.connect()
        for symbol in args.symbols:
            symbol = symbol.upper().strip()
            try:
                bars = client.fetch_daily_bars(symbol=symbol, lookback_days=args.lookback_days)
                errors = client.pop_recent_errors()
                if bars == []:
                    error = classify_ibkr_error(errors) or "empty_bars"
                    payload["symbols"].append(
                        {"symbol": symbol, "status": "error", "error": error, "bars": []}
                    )
                    continue

                payload["symbols"].append({"symbol": symbol, "status": "ok", "error": None, "bars": bars})
            except Exception as exc:
                errors = client.pop_recent_errors()
                error = classify_ibkr_error(errors)
                payload["symbols"].append(
                    {
                        "symbol": symbol,
                        "status": "error",
                        "error": error or str(exc),
                        "bars": [],
                    }
                )
    except Exception as exc:
        for symbol in args.symbols:
            payload["symbols"].append(
                {
                    "symbol": symbol.upper().strip(),
                    "status": "error",
                    "error": f"connection_failed: {exc}",
                    "bars": [],
                }
            )
    finally:
        client.disconnect()

    output = json.dumps(payload, indent=2)
    if args.output:
        Path(args.output).write_text(output + "\n", encoding="utf-8")
    else:
        print(output)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
