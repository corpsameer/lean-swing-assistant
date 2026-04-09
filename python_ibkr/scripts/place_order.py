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


VALID_SETUP_TYPES = {"breakout", "pullback"}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Place setup-aware IBKR paper bracket orders.")
    parser.add_argument("--symbol", required=True, help="Ticker symbol, e.g. AAPL")
    parser.add_argument("--setup-type", required=True, help="Setup type: breakout or pullback")
    parser.add_argument("--entry-price", required=True, type=float, help="Parent entry price")
    parser.add_argument("--stop-price", required=True, type=float, help="Stop loss price")
    parser.add_argument("--target1-price", required=True, type=float, help="Take profit price")
    parser.add_argument("--quantity", required=True, type=float, help="Order quantity")
    parser.add_argument(
        "--breakout-stop-limit-buffer",
        required=False,
        default=0.10,
        type=float,
        help="Buffer added to breakout stop-limit parent limit price",
    )
    parser.add_argument("--dry-run", action="store_true", help="Build payload only and do not transmit")
    return parser.parse_args()


def build_orders_payload(
    *,
    setup_type: str,
    quantity: float,
    entry_price: float,
    stop_price: float,
    target1_price: float,
    breakout_stop_limit_buffer: float,
) -> dict[str, dict[str, float | str]]:
    if setup_type == "breakout":
        parent = {
            "order_type": "STP LMT",
            "action": "BUY",
            "quantity": float(quantity),
            "stop_price": float(entry_price),
            "limit_price": float(entry_price + breakout_stop_limit_buffer),
        }
    elif setup_type == "pullback":
        parent = {
            "order_type": "LMT",
            "action": "BUY",
            "quantity": float(quantity),
            "limit_price": float(entry_price),
        }
    else:
        raise ValueError(f"Unsupported setup_type: {setup_type}")

    return {
        "parent": parent,
        "take_profit": {
            "order_type": "LMT",
            "action": "SELL",
            "quantity": float(quantity),
            "limit_price": float(target1_price),
        },
        "stop_loss": {
            "order_type": "STP",
            "action": "SELL",
            "quantity": float(quantity),
            "stop_price": float(stop_price),
        },
    }


def main() -> int:
    args = parse_args()
    symbol = args.symbol.upper().strip()
    setup_type = args.setup_type.lower().strip()

    if setup_type not in VALID_SETUP_TYPES:
        print(
            json.dumps(
                {
                    "status": "error",
                    "symbol": symbol,
                    "error": "setup_type must be breakout or pullback",
                }
            )
        )
        return 1

    try:
        settings = load_settings()
        if settings.mode != "paper":
            print(
                json.dumps(
                    {
                        "status": "error",
                        "symbol": symbol,
                        "error": "Only paper trading is supported.",
                    }
                )
            )
            return 1

        orders_payload = build_orders_payload(
            setup_type=setup_type,
            quantity=float(args.quantity),
            entry_price=float(args.entry_price),
            stop_price=float(args.stop_price),
            target1_price=float(args.target1_price),
            breakout_stop_limit_buffer=float(args.breakout_stop_limit_buffer),
        )

        base_response: dict[str, object] = {
            "status": "success",
            "mode": "paper",
            "dry_run": bool(args.dry_run),
            "symbol": symbol,
            "setup_type": setup_type,
            "orders": orders_payload,
        }

        if args.dry_run:
            print(json.dumps(base_response))
            return 0

        client = IBKRClient(settings)
        try:
            client.connect()
            result = client.place_entry_bracket_order(
                symbol=symbol,
                setup_type=setup_type,
                quantity=float(args.quantity),
                entry_price=float(args.entry_price),
                stop_price=float(args.stop_price),
                target1_price=float(args.target1_price),
                breakout_stop_limit_buffer=float(args.breakout_stop_limit_buffer),
            )
            print(json.dumps({**base_response, **result, "dry_run": False}))
            return 0
        finally:
            client.disconnect()
    except Exception as exc:
        print(json.dumps({"status": "error", "symbol": symbol, "error": str(exc)}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
