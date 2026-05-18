# T12 — Paper trade/order status sync

Adds a lean sync loop for paper-order status reconciliation.

## Command

```bash
php artisan trades:sync-paper-status
```

The command:
1. selects non-terminal `orders` with a `broker_order_id`
2. fetches latest broker status from IBKR paper via Python
3. normalizes broker states into local status values
4. updates `orders.status` + `orders.meta_json.sync_snapshot`
5. updates related `trade_setups.status` (`entered`, `cancelled`, `closed` when clearly applicable)
6. prints a concise summary

## Local order status mapping

- `PendingSubmit` / `PreSubmitted` / `Submitted` -> `submitted_paper`
- `Filled` -> `filled_paper`
- `PartiallyFilled` -> `partially_filled_paper`
- `Cancelled` / `ApiCancelled` -> `cancelled`
- `Rejected` -> `broker_rejected` or `broker_rejected_cash`
- `Inactive` -> `inactive_broker` or `broker_rejected_cash`
- anything else -> `unknown_broker_state`

## Python fetch script

- Script: `python_ibkr/scripts/fetch_order_status.py`
- Uses existing IBKR paper connectivity config and returns normalized snapshot rows keyed by `broker_order_id`.

## Testing quick-start

```bash
php artisan test --filter=SyncPaperTradeStatusCommandTest
```

Covers:
- no relevant open orders
- submitted order snapshot persistence
- broker rejection -> order/trade setup cancellation
- filled entry order -> trade setup entered
