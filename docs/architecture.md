# Architecture Summary (v1, Locked)

## 1) Purpose

A lean local assistant for discretionary swing-trading support:
- gather/normalize signal inputs (future phases)
- rank/interpret candidates via OpenAI prompts
- route paper trade intents via IBKR connector

This is **self-use only** and intentionally minimal.

---

## 2) Core Components

## 2.1 Laravel App (`/laravel_app`)
Primary orchestrator.

Responsibilities:
- central workflow control
- DB interactions (MySQL)
- app configuration management
- OpenAI prompt invocation and response handling
- mode governance (`paper` vs `live` with safety gate)

Non-goals in v1:
- no multi-user auth architecture
- no heavy domain abstraction layers
- no feature-rich UI

## 2.2 Python IBKR Connector (`/python_ibkr`)
Tiny integration boundary for IBKR only.

Responsibilities:
- connect to IBKR endpoints using env-defined settings
- resolve host/port/client-id by mode
- enforce mode safety checks before any live-capable action

Non-goals in v1:
- no strategy logic
- no orchestration ownership
- no persistence ownership

## 2.3 MySQL
Used by Laravel for minimal persistent state needed by v1 features (to be defined in T02+).

## 2.4 OpenAI
Used by Laravel for prompt-based ranking/interpretation only.

---

## 3) Control Flow (High-Level)

1. Laravel loads app/env config.
2. Laravel determines active mode (`paper`/`live`) and validates live guard.
3. Laravel invokes Python connector when broker connectivity is required.
4. Python connector independently validates mode + live guard.
5. Python selects IBKR endpoint based on mode env values.
6. Laravel remains the source of orchestration truth.

Both layers validate mode to avoid accidental live behavior.

---

## 4) Environment Contract

## 4.1 Shared Rules

- `TRADING_MODE` allowed values: `paper`, `live`
- default must be `paper`
- live requires explicit confirmation flag: `LIVE_TRADING_ENABLED=true`
- if `TRADING_MODE=live` and confirmation is not true, fail immediately

## 4.2 Laravel Env Ownership

Laravel `.env` includes:
- app identifiers (`APP_NAME`, `APP_ENV`, `APP_URL`)
- DB settings (`DB_*`)
- OpenAI settings (`OPENAI_*`)
- trading safety settings (`TRADING_MODE`, `LIVE_TRADING_ENABLED`)

## 4.3 Python Env Ownership

Python `.env` includes:
- connector mode settings (`TRADING_MODE`, `LIVE_TRADING_ENABLED`)
- per-mode endpoint values:
  - `IBKR_PAPER_HOST`, `IBKR_PAPER_PORT`, `IBKR_PAPER_CLIENT_ID`
  - `IBKR_LIVE_HOST`, `IBKR_LIVE_PORT`, `IBKR_LIVE_CLIENT_ID`
- optional runtime toggles (`LOG_LEVEL`, timeouts)

---

## 5) Safety Model (Paper First)

- v1 always starts in paper mode by default.
- live mode is opt-in and must be explicitly confirmed in env.
- accidental live activation is prevented by dual checks:
  - Laravel check
  - Python connector check

This layered guard is mandatory for v1.

---

## 6) v1 Boundaries (Do Not Expand Yet)

- No feature logic implementation in this task.
- No database schema implementation in this task.
- No UI implementation in this task.
- No deployment/infrastructure complexity in this task.

This document intentionally locks architecture and scope for the next implementation phase (T02).
