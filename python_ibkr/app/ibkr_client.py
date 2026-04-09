from __future__ import annotations

from datetime import date, datetime, timezone
from typing import Any

from ib_insync import IB, LimitOrder, Order, Stock, StopOrder

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

    def place_limit_buy_order(self, symbol: str, quantity: float, limit_price: float) -> int:
        if self.settings.mode != "paper":
            raise ValueError("Only paper trading is supported for order placement.")

        contract = Stock(symbol=symbol, exchange="SMART", currency="USD")
        self.ib.qualifyContracts(contract)

        order = LimitOrder(action="BUY", totalQuantity=float(quantity), lmtPrice=float(limit_price))
        trade = self.ib.placeOrder(contract, order)
        self.ib.sleep(1.0)

        order_id = trade.order.orderId if trade.order and trade.order.orderId else order.orderId
        if not order_id:
            raise RuntimeError("IBKR did not return an order id.")

        return int(order_id)

    def place_entry_bracket_order(
        self,
        *,
        symbol: str,
        setup_type: str,
        quantity: float,
        entry_price: float,
        stop_price: float,
        target1_price: float,
        breakout_stop_limit_buffer: float,
    ) -> dict[str, Any]:
        if self.settings.mode != "paper":
            raise ValueError("Only paper trading is supported for order placement.")

        contract = Stock(symbol=symbol, exchange="SMART", currency="USD")
        self.ib.qualifyContracts(contract)

        parent_id = self.ib.client.getReqId()
        take_profit_id = self.ib.client.getReqId()
        stop_loss_id = self.ib.client.getReqId()

        if setup_type == "breakout":
            parent_order = Order(
                orderId=parent_id,
                action="BUY",
                orderType="STP LMT",
                totalQuantity=float(quantity),
                auxPrice=float(entry_price),
                lmtPrice=float(entry_price + breakout_stop_limit_buffer),
                transmit=False,
            )
        elif setup_type == "pullback":
            parent_order = LimitOrder(
                orderId=parent_id,
                action="BUY",
                totalQuantity=float(quantity),
                lmtPrice=float(entry_price),
                transmit=False,
            )
        else:
            raise ValueError(f"Unsupported setup_type: {setup_type}")

        take_profit_order = LimitOrder(
            orderId=take_profit_id,
            action="SELL",
            totalQuantity=float(quantity),
            lmtPrice=float(target1_price),
            parentId=parent_id,
            transmit=False,
        )
        stop_loss_order = StopOrder(
            orderId=stop_loss_id,
            action="SELL",
            totalQuantity=float(quantity),
            stopPrice=float(stop_price),
            parentId=parent_id,
            transmit=True,
        )

        parent_trade = self.ib.placeOrder(contract, parent_order)
        take_profit_trade = self.ib.placeOrder(contract, take_profit_order)
        stop_loss_trade = self.ib.placeOrder(contract, stop_loss_order)
        self.ib.sleep(1.0)

        return {
            "broker_order_ids": {
                "parent": int(parent_id),
                "take_profit": int(take_profit_id),
                "stop_loss": int(stop_loss_id),
            },
            "broker_statuses": {
                "parent": parent_trade.orderStatus.status,
                "take_profit": take_profit_trade.orderStatus.status,
                "stop_loss": stop_loss_trade.orderStatus.status,
            },
        }


def _normalize_to_utc(value: Any) -> str:
    if isinstance(value, datetime):
        dt = value if value.tzinfo else value.replace(tzinfo=timezone.utc)
        return dt.astimezone(timezone.utc).isoformat().replace("+00:00", "Z")

    if isinstance(value, date):
        return datetime(value.year, value.month, value.day, tzinfo=timezone.utc).isoformat().replace(
            "+00:00", "Z"
        )

    text = str(value)
    try:
        dt = datetime.fromisoformat(text.replace("Z", "+00:00"))
        dt = dt if dt.tzinfo else dt.replace(tzinfo=timezone.utc)
        return dt.astimezone(timezone.utc).isoformat().replace("+00:00", "Z")
    except ValueError:
        return text
