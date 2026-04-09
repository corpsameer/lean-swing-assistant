from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from app.config import load_settings
from app.ibkr_client import IBKRClient


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Place a minimal IBKR paper LIMIT BUY order.")
    parser.add_argument("--symbol", required=True, help="Ticker symbol, e.g. AAPL")
    parser.add_argument("--entry-price", required=True, type=float, help="Limit entry price")
    parser.add_argument("--quantity", required=True, type=float, help="Order quantity")
    return parser.parse_args()


def main() -> int:
    args = parse_args()

    try:
        settings = load_settings()
        if settings.mode != "paper":
            print(
                json.dumps(
                    {
                        "status": "error",
                        "symbol": args.symbol.upper().strip(),
                        "error": "Only paper trading is supported.",
                    }
                )
            )
            return 1
    except Exception as exc:
        print(json.dumps({"status": "error", "symbol": args.symbol.upper().strip(), "error": str(exc)}))
        return 1

    client = IBKRClient(settings)
    symbol = args.symbol.upper().strip()

    try:
        client.connect()
        order_id = client.place_limit_buy_order(
            symbol=symbol,
            quantity=float(args.quantity),
            limit_price=float(args.entry_price),
        )
        print(
            json.dumps(
                {
                    "status": "success",
                    "order_id": str(order_id),
                    "symbol": symbol,
                    "price": float(args.entry_price),
                }
            )
        )
        return 0
    except Exception as exc:
        print(json.dumps({"status": "error", "symbol": symbol, "error": str(exc)}))
        return 1
    finally:
        client.disconnect()


if __name__ == "__main__":
    raise SystemExit(main())
