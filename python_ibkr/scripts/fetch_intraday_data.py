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


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Fetch intraday market snapshot data from IBKR for one or more symbols."
    )
    parser.add_argument("symbols", nargs="+", help="Ticker symbols, e.g. AAPL MSFT")
    parser.add_argument("--bar-size", default="5 mins", help="IBKR bar size setting")
    parser.add_argument("--duration", default="1 D", help="IBKR duration string")
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
        "snapshot_type": "intraday",
        "symbols": [],
    }

    client = IBKRClient(settings)
    try:
        client.connect()
        for symbol in args.symbols:
            symbol = symbol.upper().strip()
            try:
                snapshot = client.fetch_intraday_snapshot(
                    symbol=symbol,
                    bar_size=args.bar_size,
                    duration=args.duration,
                )
                payload["symbols"].append(
                    {
                        "symbol": symbol,
                        "status": "ok",
                        "snapshot_type": "intraday",
                        "metrics": {
                            "current_price": snapshot["current_price"],
                            "session_high": snapshot["session_high"],
                            "session_low": snapshot["session_low"],
                            "intraday_vwap": snapshot["intraday_vwap"],
                        },
                        "bars": snapshot["bars"],
                    }
                )
            except Exception as exc:
                payload["symbols"].append(
                    {
                        "symbol": symbol,
                        "status": "error",
                        "snapshot_type": "intraday",
                        "error": str(exc),
                        "metrics": {
                            "current_price": None,
                            "session_high": None,
                            "session_low": None,
                            "intraday_vwap": None,
                        },
                        "bars": [],
                    }
                )
    except Exception as exc:
        for symbol in args.symbols:
            payload["symbols"].append(
                {
                    "symbol": symbol.upper().strip(),
                    "status": "error",
                    "snapshot_type": "intraday",
                    "error": f"connection_failed: {exc}",
                    "metrics": {
                        "current_price": None,
                        "session_high": None,
                        "session_low": None,
                        "intraday_vwap": None,
                    },
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
