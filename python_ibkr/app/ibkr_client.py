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

    def fetch_daily_bars(self, symbol: str, lookback_days: int = 90) -> list[dict[str, Any]]:
        contract = Stock(symbol=symbol, exchange="SMART", currency="USD")
        self.ib.qualifyContracts(contract)
        duration_days = max(lookback_days, 60)
        bars = self.ib.reqHistoricalData(
            contract,
            endDateTime="",
            durationStr=f"{duration_days} D",
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
        result.sort(key=lambda bar: bar["datetime_utc"])
        return result

    def fetch_intraday_snapshot(
        self,
        symbol: str,
        bar_size: str = "5 mins",
        duration: str = "1 D",
    ) -> dict[str, Any]:
        contract = Stock(symbol=symbol, exchange="SMART", currency="USD")
        self.ib.qualifyContracts(contract)

        bars = self.ib.reqHistoricalData(
            contract,
            endDateTime="",
            durationStr=duration,
            barSizeSetting=bar_size,
            whatToShow="TRADES",
            useRTH=True,
            formatDate=2,
        )

        normalized_bars: list[dict[str, Any]] = []
        for bar in bars:
            normalized_bars.append(
                {
                    "datetime_utc": _normalize_to_utc(bar.date),
                    "open": float(bar.open),
                    "high": float(bar.high),
                    "low": float(bar.low),
                    "close": float(bar.close),
                    "volume": int(bar.volume),
                }
            )

        normalized_bars.sort(key=lambda row: row["datetime_utc"])

        if normalized_bars == []:
            raise ValueError("No intraday bars returned.")

        current_price = float(normalized_bars[-1]["close"])
        session_high = max(float(row["high"]) for row in normalized_bars)
        session_low = min(float(row["low"]) for row in normalized_bars)

        total_volume = sum(int(row["volume"]) for row in normalized_bars if int(row["volume"]) > 0)
        intraday_vwap = None
        if total_volume > 0:
            vwap_numerator = sum(
                float(row["close"]) * int(row["volume"])
                for row in normalized_bars
                if int(row["volume"]) > 0
            )
            intraday_vwap = vwap_numerator / total_volume

        return {
            "current_price": current_price,
            "session_high": session_high,
            "session_low": session_low,
            "intraday_vwap": intraday_vwap,
            "bars": normalized_bars,
        }


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
