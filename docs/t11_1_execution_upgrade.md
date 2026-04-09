# T11.1 Execution Upgrade (Paper Only)

This upgrade keeps execution **strictly paper-only** and adds setup-aware bracket order behavior.

## Configuration

Set in `laravel_app/.env`:

- `BROKER_TRADING_MODE=paper`
- `EXECUTION_ENABLED=false`
- `EXECUTION_DRY_RUN=true`
- `PAPER_ORDER_QUANTITY=1`
- `BREAKOUT_STOP_LIMIT_BUFFER=0.10`

## Entry behavior

- `setup_type=breakout` → parent entry is `BUY STP LMT`.
  - stop trigger = `entry_price`
  - limit price = `entry_price + BREAKOUT_STOP_LIMIT_BUFFER`
- `setup_type=pullback` → parent entry is `BUY LMT` at `entry_price`

Both setup types attach fixed children:
- `take_profit` = `SELL LMT` at `target1_price`
- `stop_loss` = `SELL STP` at `stop_price`

## Safety gates

Execution is skipped unless both are true:

1. `EXECUTION_ENABLED=true`
2. `BROKER_TRADING_MODE=paper`

Any non-paper broker mode remains blocked.

## Test path

### Test A — Disabled mode

1. Set `EXECUTION_ENABLED=false`.
2. Run intraday validation flow that creates `trade_setup`.
3. Confirm no `orders` row is created.
4. Confirm logs show `paper execution skipped`.

### Test B — Dry-run mode

1. Set `EXECUTION_ENABLED=true` and `EXECUTION_DRY_RUN=true`.
2. Run intraday validation flow.
3. Confirm `orders.status=simulated_dry_run`.
4. Confirm no broker order id is stored.
5. Confirm `orders.meta_json` contains full parent/take-profit/stop-loss structure.

### Test C — Actual paper mode

1. Start IBKR TWS paper and API access.
2. Set `EXECUTION_ENABLED=true` and `EXECUTION_DRY_RUN=false`.
3. Run intraday validation flow.
4. Confirm `orders.status=submitted_paper`.
5. Confirm parent broker id exists and child ids are present in `orders.meta_json.broker_order_ids`.

