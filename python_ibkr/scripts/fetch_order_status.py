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
    parser = argparse.ArgumentParser(description="Fetch IBKR paper order statuses by broker order id.")
    parser.add_argument(
        "--order-ids",
        required=True,
        help="Comma-separated IBKR broker order ids (example: 1001,1002)",
    )
    return parser.parse_args()


def parse_order_ids(raw: str) -> list[int]:
    order_ids: list[int] = []
    for token in raw.split(","):
        text = token.strip()
        if text == "":
            continue
        order_ids.append(int(text))
    return order_ids


def main() -> int:
    args = parse_args()

    try:
        settings = load_settings()
        if settings.mode != "paper":
            print(json.dumps({"status": "error", "error": "Only paper mode is supported."}))
            return 1

        order_ids = parse_order_ids(str(args.order_ids))
        if not order_ids:
            print(json.dumps({"status": "success", "mode": "paper", "orders": []}))
            return 0

        client = IBKRClient(settings)
        try:
            client.connect()
            orders = client.fetch_order_statuses(order_ids)
            print(json.dumps({"status": "success", "mode": "paper", "orders": orders}))
            return 0
        finally:
            client.disconnect()
    except Exception as exc:
        print(json.dumps({"status": "error", "error": str(exc)}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
