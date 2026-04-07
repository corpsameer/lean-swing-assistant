from __future__ import annotations

import sys
from pathlib import Path
import socket

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from app.config import load_settings
from app.ibkr_client import IBKRClient


def main() -> int:
    try:
        settings = load_settings()
    except Exception as exc:
        print(f"IBKR connection test: FAILED to load config: {exc}")
        return 1

    client = IBKRClient(settings)
    print(
        "IBKR connection test: trying",
        f"mode={settings.mode}",
        f"host={settings.host}",
        f"port={settings.port}",
        f"client_id={settings.client_id}",
    )

    # quick socket preflight for clearer failures before ib_insync connect flow
    sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    sock.settimeout(2)
    try:
        sock.connect((settings.host, settings.port))
    except OSError as exc:
        print(
            "IBKR connection test: FAILURE",
            f"mode={settings.mode}",
            f"host={settings.host}",
            f"port={settings.port}",
            f"client_id={settings.client_id}",
            f"error=socket_precheck_failed: {exc}",
        )
        print(
            "Hint: enable API in TWS/IB Gateway, confirm API port, and allow localhost 127.0.0.1."
        )
        return 2
    finally:
        sock.close()

    try:
        client.connect()
        print(
            "IBKR connection test: SUCCESS",
            f"mode={settings.mode}",
            f"host={settings.host}",
            f"port={settings.port}",
            f"client_id={settings.client_id}",
        )
        return 0
    except Exception as exc:
        print(
            "IBKR connection test: FAILURE",
            f"mode={settings.mode}",
            f"host={settings.host}",
            f"port={settings.port}",
            f"client_id={settings.client_id}",
            f"error={exc}",
        )
        return 2
    finally:
        client.disconnect()


if __name__ == "__main__":
    raise SystemExit(main())
