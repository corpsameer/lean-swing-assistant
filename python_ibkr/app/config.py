from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path

from dotenv import load_dotenv


@dataclass(frozen=True)
class IBKRSettings:
    mode: str
    live_trading_enabled: bool
    host: str
    port: int
    client_id: int
    connect_timeout_seconds: int


def _to_bool(value: str | None) -> bool:
    return (value or "").strip().lower() in {"1", "true", "yes", "on"}


def _must_int(name: str, default: int) -> int:
    raw = os.getenv(name, str(default)).strip()
    try:
        return int(raw)
    except ValueError as exc:
        raise ValueError(f"{name} must be an integer, got: {raw!r}") from exc


def load_settings() -> IBKRSettings:
    env_path = Path(__file__).resolve().parents[1] / ".env"
    load_dotenv(env_path if env_path.exists() else None)

    mode = (os.getenv("IBKR_MODE") or os.getenv("TRADING_MODE") or "paper").strip().lower()
    if mode not in {"paper", "live"}:
        raise ValueError("IBKR_MODE/TRADING_MODE must be 'paper' or 'live'")

    live_enabled = _to_bool(os.getenv("LIVE_TRADING_ENABLED"))
    if mode == "live" and not live_enabled:
        raise ValueError(
            "Live mode blocked: set LIVE_TRADING_ENABLED=true to allow IBKR_MODE=live"
        )

    prefix = "IBKR_PAPER" if mode == "paper" else "IBKR_LIVE"
    host = os.getenv(f"{prefix}_HOST", "127.0.0.1").strip()
    port = _must_int(f"{prefix}_PORT", 7497 if mode == "paper" else 7496)
    client_id = _must_int(f"{prefix}_CLIENT_ID", 1 if mode == "paper" else 2)
    timeout = _must_int("IBKR_CONNECT_TIMEOUT_SECONDS", 10)

    return IBKRSettings(
        mode=mode,
        live_trading_enabled=live_enabled,
        host=host,
        port=port,
        client_id=client_id,
        connect_timeout_seconds=timeout,
    )
