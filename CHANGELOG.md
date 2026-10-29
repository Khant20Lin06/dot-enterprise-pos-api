# CHANGELOG.md — Release & Feature Change Log

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Added
- **Phase 2 Implementation:**
  - Production `docker-compose.yml` with 6 services: `app` (PHP-FPM), `web` (Nginx), `db` (MySQL 8.0), `redis` (Redis 7), `reverb` (WebSockets), `node-worker` (Async Receipt/Barcode Service).
  - Multi-stage `docker/php/Dockerfile` configured with `pdo_mysql`, `bcmath`, `pcntl`, `redis`, `gd`, `zip`, `intl`, `opcache`.
  - Nginx reverse proxy configuration (`docker/nginx/default.conf`) with WebSocket routing for Laravel Reverb and Node worker.
  - Node.js Microservice Worker (`services/node-worker/`) with Express, Redis Pub/Sub, `bwip-js` barcode generation, and ESC/POS thermal receipt formatting.
  - Initialized clean Laravel 11.x project structure with Sanctum, Reverb, Horizon, Scramble, and Pest PHP.
  - Configured `.env` and `.env.example` with Redis queues, Reverb WebSockets, and database settings.
  - Created Domain-Driven Modular folders (`app/Domains/Workshop`, `Inventory`, `SalesAndPos`, `PricingAndPromotions`, `CreditAndLedger`, `Purchasing`, `SecurityAndAudit`).

- **Phase 1 Implementation:**
  - `AGENTS.md`: Operational instructions, coding standards, and Definition of Done protocol for AI agents and human developers.
  - `PROGRESS.md`: Live milestone tracker and task checklist covering all 9 project phases.
  - `docs/ARCHITECTURE.md`: High-level Domain-Driven Modular Clean Architecture specification.
  - `docs/DATABASE_SCHEMA.md`: Complete relational schema blueprint linking Workshop, Inventory, POS, and Customers.
  - `docs/API_SPEC.md`: Exhaustive REST API contract matching the `dot-pos-next` Next.js frontend models.
  - Architectural Decision Records:
    - `docs/adr/0001-modular-domain-action-pattern.md`: Rationale for Domain-Driven Action Pattern over monolithic MVC.
    - `docs/adr/0002-pessimistic-locking-for-stock.md`: Rationale for Pessimistic Row Locking (`lockForUpdate()`) to prevent overselling.
    - `docs/adr/0003-laravel-reverb-for-websockets.md`: Rationale for choosing Laravel Reverb over Pusher/Socket.io.

---

## [0.1.0] - 2026-10-30
### Added
- Initial project architecture definition and requirement specification tailored for Enrich Insights / Tech Mart MM backend benchmark.
