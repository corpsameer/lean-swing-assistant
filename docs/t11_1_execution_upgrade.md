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

## Exact test steps (Laravel command + dummy DB data)

> Command used below inserts dummy data (`symbols`, `runs`, `watchlist_candidates`, `trade_setups`) and then runs the execution service for the requested scenario.

### 0) Prep

```bash
cd laravel_app
php artisan migrate
```

### 1) Test A — Disabled mode (no order, no broker call)

#### Breakout
```bash
php artisan trade:execution-scenario-test disabled breakout --symbol=T11ABRK --entry=184.50 --stop=182.50 --target=188.00 --quantity=1
```
Expected:
- Output: `No order row created (expected for disabled scenario).`
- DB check:
```bash
php artisan tinker --execute="echo \App\Models\Order::whereHas('tradeSetup', fn($q)=>$q->where('notes','like','%T11.1 scenario test%'))->count();"
```

#### Pullback
```bash
php artisan trade:execution-scenario-test disabled pullback --symbol=T11APBK --entry=184.50 --stop=182.50 --target=188.00 --quantity=1
```
Expected: same as breakout disabled test.

### 2) Test B — Dry-run mode (simulated record, no transmit)

#### Breakout
```bash
php artisan trade:execution-scenario-test dry-run breakout --symbol=T11BBRK --entry=184.50 --stop=182.50 --target=188.00 --quantity=1
```
Expected:
- `status=simulated_dry_run`
- `broker_order_id=null`
- `order_type=STP LMT`
- `meta_json.orders.parent.stop_price` exists
- `meta_json.orders.parent.limit_price = entry + BREAKOUT_STOP_LIMIT_BUFFER`

#### Pullback
```bash
php artisan trade:execution-scenario-test dry-run pullback --symbol=T11BPBK --entry=184.50 --stop=182.50 --target=188.00 --quantity=1
```
Expected:
- `status=simulated_dry_run`
- `broker_order_id=null`
- `order_type=LMT`
- `meta_json.orders.parent.stop_price` absent for parent

### 3) Test C — Actual paper mode (IBKR paper transmit)

1. Start IBKR TWS paper + API access.
2. Ensure Python connector env points to paper endpoint. Use a real IBKR-tradable symbol (for example AAPL or MSFT), not a dummy ticker.
3. Run one setup type at a time.

#### Breakout (paper transmit)
```bash
php artisan trade:execution-scenario-test paper breakout --force-paper --symbol=AAPL --entry=184.50 --stop=182.50 --target=188.00 --quantity=1
```

#### Pullback (paper transmit)
```bash
php artisan trade:execution-scenario-test paper pullback --force-paper --symbol=MSFT --entry=184.50 --stop=182.50 --target=188.00 --quantity=1
```

Expected (both):
- `status=submitted_paper`
- `broker_order_id` present (parent)
- `meta_json.broker_order_ids.take_profit` and `meta_json.broker_order_ids.stop_loss` present

## Notes

- `paper` scenario is blocked unless `--force-paper` is explicitly provided.
- No live support, no trailing logic, no dynamic TP/SL updates, no partial exits.

