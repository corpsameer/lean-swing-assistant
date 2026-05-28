from __future__ import annotations

import json
import socket
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from app.config import load_settings
from app.ibkr_client import IBKRClient


def main() -> int:
    try:
        settings = load_settings()
    except Exception as exc:
        print(json.dumps({"status": "error", "error": f"failed to load settings: {exc}"}))
        return 1

    sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    sock.settimeout(2)
    try:
        sock.connect((settings.host, settings.port))
    except OSError as exc:
        print(json.dumps({"status": "error", "error": f"socket_precheck_failed: {exc}"}))
        return 2
    finally:
        sock.close()

    client = IBKRClient(settings)
    try:
        client.connect()
    except Exception as exc:
        print(json.dumps({"status": "error", "error": str(exc)}))
        return 2
    finally:
        client.disconnect()

    print(
        json.dumps(
            {
                "status": "ok",
                "mode": settings.mode,
                "host": settings.host,
                "port": settings.port,
                "client_id": settings.client_id,
            }
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
