# Lean Swing Assistant (Self-Use) — v1 Skeleton

This repository is the **locked v1 foundation** for a lean, local, self-use swing trading assistant.

## Scope (Locked for v1)

- **Single user (self-use only)**
- **Local-first operation**
- **Laravel as the primary orchestrator**
- **MySQL as storage**
- **Small Python connector for IBKR connectivity only**
- **OpenAI used only for prompt-based ranking/interpretation**
- **Paper trading by default**
- **Live trading disabled unless explicitly enabled by environment confirmation**

### Explicit Non-Goals (v1)

- No multi-user architecture
- No heavy abstractions or microservices
- No large UI surface
- No advanced infra/orchestration
- No feature logic implementation in this phase

---

## Repository Structure

```text
/
├── README.md
├── .gitignore
├── docs/
│   └── architecture.md
├── laravel_app/
│   └── .env.example
└── python_ibkr/
    └── .env.example
```

---

## Environment Contract Overview

Two separate environment files are required and included:

- `laravel_app/.env.example`
- `python_ibkr/.env.example`

### Responsibility Split

#### Laravel (`laravel_app/.env.example`)
Owns:
- app-level configuration
- DB (MySQL) configuration
- OpenAI configuration
- trading mode and live-enable guard

#### Python IBKR Connector (`python_ibkr/.env.example`)
Owns:
- IBKR host/port/client-id resolution based on mode
- connector runtime mode validation
- hard guard to block live unless explicitly confirmed

---

## Paper vs Live Mode Rules (Canonical)

### 1) Default mode is paper
Both env files default to:
- `TRADING_MODE=paper`

### 2) Live requires two conditions
Live operation is allowed **only** when:
1. `TRADING_MODE=live`
2. `LIVE_TRADING_ENABLED=true`

If mode is `live` but confirmation flag is not `true`, the process **must fail fast**.

### 3) Env-only switching
Switching between paper/live must require **only environment changes**. No code edits are needed.

### 4) No secrets in repository
- No API keys, DB passwords, or IBKR credentials are hardcoded.
- Local runtime values must be provided in untracked `.env` files.

---

## Quick Start for Next Task (T02)

1. Copy env templates:
   - `cp laravel_app/.env.example laravel_app/.env`
   - `cp python_ibkr/.env.example python_ibkr/.env`
2. Fill local values (do not commit secrets).
3. Keep `TRADING_MODE=paper` and `LIVE_TRADING_ENABLED=false` for initial development.

This repository is now prepared for implementation work in **T02**.
