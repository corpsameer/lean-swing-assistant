from __future__ import annotations

import argparse
import json
import os
import sys
from collections import defaultdict
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

from ib_insync import ScannerSubscription, Stock

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from app.config import load_settings
from app.ibkr_client import IBKRClient


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Build IBKR scanner-based symbol universe.")
    parser.add_argument("--output", type=str, default="")
    return parser.parse_args()


def scanner_codes() -> list[str]:
    raw = (os.getenv("IBKR_SCANNER_CODES") or "MOST_ACTIVE,TOP_PERC_GAIN,HOT_BY_VOLUME,TOP_TRADE_COUNT").strip()
    return [c.strip().upper() for c in raw.split(",") if c.strip()]


def max_symbols() -> int:
    raw = (os.getenv("UNIVERSE_MAX_SYMBOLS") or "200").strip()
    try:
        return max(1, int(raw))
    except ValueError:
        return 200


def main() -> int:
    args = parse_args()
    try:
        settings = load_settings()
    except Exception as exc:
        print(json.dumps({"error": str(exc)}))
        return 1

    codes = scanner_codes()
    cap = max_symbols()

    payload: dict[str, Any] = {
        "mode": settings.mode,
        "fetched_at_utc": datetime.now(UTC).isoformat().replace("+00:00", "Z"),
        "scanner_codes": codes,
        "symbols": [],
        "errors": [],
    }

    by_symbol: dict[str, dict[str, Any]] = {}
    source_codes: dict[str, set[str]] = defaultdict(set)
    client = IBKRClient(settings)

    try:
        client.connect()

        for code in codes:
            try:
                subscription = ScannerSubscription(
                    instrument="STK",
                    locationCode="STK.US.MAJOR",
                    scanCode=code,
                )
                scanner_rows = client.ib.reqScannerData(subscription)
                for rank, row in enumerate(scanner_rows, start=1):
                    details = getattr(row, "contractDetails", None)
                    contract = getattr(details, "contract", None)
                    if contract is None:
                        continue

                    symbol = str(getattr(contract, "symbol", "") or "").upper().strip()
                    if symbol == "":
                        continue

                    source_codes[symbol].add(code)
                    if symbol not in by_symbol:
                        by_symbol[symbol] = {
                            "symbol": symbol,
                            "status": "ok",
                            "source_scanner_codes": [],
                            "con_id": getattr(contract, "conId", None),
                            "exchange": getattr(contract, "exchange", None),
                            "currency": getattr(contract, "currency", None),
                            "sec_type": getattr(contract, "secType", None),
                            "name": getattr(details, "longName", None),
                            "rank": rank,
                        }
            except Exception as exc:
                payload["errors"].append({"scanner_code": code, "error": str(exc)})

            if len(by_symbol) >= cap:
                break

    except Exception as exc:
        payload["errors"].append({"scanner_code": "connection", "error": f"connection_failed: {exc}"})
    finally:
        client.disconnect()

    symbols = list(by_symbol.values())[:cap]
    for row in symbols:
        row["source_scanner_codes"] = sorted(source_codes[row["symbol"]])

    payload["symbols"] = symbols

    output = json.dumps(payload, indent=2)
    if args.output:
        Path(args.output).write_text(output + "\n", encoding="utf-8")
    else:
        print(output)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
