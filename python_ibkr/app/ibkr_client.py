from __future__ import annotations

from datetime import UTC, date, datetime
from typing import Any

from ib_insync import IB, Stock

from app.config import IBKRSettings


class IBKRClient:
    def __init__(self, settings: IBKRSettings):
        self.settings = settings
        self.ib = IB()

    def connect(self) -> None:
        self.ib.connect(
            host=self.settings.host,
            port=self.settings.port,
            clientId=self.settings.client_id,
            timeout=self.settings.connect_timeout_seconds,
        )

    def disconnect(self) -> None:
        if self.ib.isConnected():
            self.ib.disconnect()

    def fetch_daily_bars(self, symbol: str, lookback_days: int = 30) -> list[dict[str, Any]]:
        contract = Stock(symbol=symbol, exchange="SMART", currency="USD")
        self.ib.qualifyContracts(contract)
        bars = self.ib.reqHistoricalData(
            contract,
            endDateTime="",
            durationStr=f"{lookback_days} D",
            barSizeSetting="1 day",
            whatToShow="TRADES",
            useRTH=True,
            formatDate=2,
        )

        result: list[dict[str, Any]] = []
        for bar in bars:
            dt_utc = _normalize_to_utc(bar.date)
            result.append(
                {
                    "datetime_utc": dt_utc,
                    "open": float(bar.open),
                    "high": float(bar.high),
                    "low": float(bar.low),
                    "close": float(bar.close),
                    "volume": int(bar.volume),
                }
            )
        return result


def _normalize_to_utc(value: Any) -> str:
    if isinstance(value, datetime):
        dt = value if value.tzinfo else value.replace(tzinfo=UTC)
        return dt.astimezone(UTC).isoformat().replace("+00:00", "Z")

    if isinstance(value, date):
        return datetime(value.year, value.month, value.day, tzinfo=UTC).isoformat().replace(
            "+00:00", "Z"
        )

    text = str(value)
    try:
        dt = datetime.fromisoformat(text.replace("Z", "+00:00"))
        dt = dt if dt.tzinfo else dt.replace(tzinfo=UTC)
        return dt.astimezone(UTC).isoformat().replace("+00:00", "Z")
    except ValueError:
        return text
