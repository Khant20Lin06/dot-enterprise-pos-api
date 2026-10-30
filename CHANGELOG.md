# CHANGELOG.md — Release & Feature Change Log

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Added
- **Phase 6 & Phase 7 Implementation (Redis Queues, Horizon & Node.js Worker Bridge):**
  - Integrated **Laravel Horizon** for queue monitoring with multi-queue supervisors handling `receipts`, `reports`, `notifications`, and `default`.
  - Configured Horizon authorization gate for administrative roles and system engineers.
  - Implemented Queued Background Jobs:
    - `GenerateReceiptPdfJob`: Asynchronous thermal receipt formatting, file artifact generation (`storage/app/receipts`), and audit logging.
    - `SendDailySalesSummaryJob`: High-performance daily sales and workshop report compilation (`storage/app/reports`), financial reconciliation across POS, customer debt recovery, and mechanic commissions.
    - `DispatchThermalPrintJob`: Redis Pub/Sub dispatcher to node printer workers.
  - Configured resilient retry policies: `$tries = 3`, exponential `$backoff`, and critical failure alerting in `audit_logs`.
  - Implemented `NodeWorkerBridgeService`: Seamless bridge to Node.js microservice (`bwip-js` barcode/QR generation, ESC/POS byte commands, Redis Pub/Sub `pos:print-job`, and offline fallback).
  - Pest Feature test suite: `tests/Feature/QueuedJobsTest.php` with 6 comprehensive tests (100% pass).

- **Phase 5 Implementation:**
  - Integrated **Laravel Reverb** high-performance WebSocket server for real-time multi-counter syncing on port 8080.
  - Published and tuned Reverb & Broadcasting configurations (`config/reverb.php`, `config/broadcasting.php`).
  - Implemented Domain Broadcast Events with `ShouldBroadcast`:
    - `StockUpdatedEvent`: Multi-counter live inventory synchronization across POS cashier terminals and workshop dispatch bays (`stock.channel`).
    - `OrderCompletedEvent`: Live multi-device sales sync with financial totals, split tenders, and counter station isolation (`pos.orders`, `pos.counter.{counterId}`).
    - `WorkOrderStatusChangedEvent`: Live workshop status updates for technicians, service advisors, and display screens (`workshop.bay`).
  - Bound real-time broadcasting seamlessly into Domain Actions (`DeductStockAction`, `ReplenishStockAction`, `ProcessCheckoutAction`, `ConvertWorkOrderToInvoiceAction`).
  - Added Pest Feature tests (`tests/Feature/ReverbBroadcastingTest.php`) verifying event payload structure, channel authorization, and domain action triggering (100% pass).

- **Phase 4 Implementation:**
  - `DeductStockAction` & `ReplenishStockAction`: Concurrency control with Pessimistic Row Locking (`lockForUpdate()`), preventing overselling and supporting variant option stock.
  - `ProcessCheckoutAction`: ACID transaction orchestration, multi-tender payment splits (Cash, KPay, Card, Credit), credit debt accounting, loyalty points accrual, and idempotency key safeguard.
  - `ConvertWorkOrderToInvoiceAction`: Converts automotive Job Cards to official Invoices, automatically deducting physical inventory for fitted car parts and issuing invoices.
  - `CalculateMechanicCommissionAction`: Automatically calculates and logs technician commission on labor fees.
  - `SettleCustomerCreditAction`: Transactional debt settlement, due balance decrement, and sequential receipt issuance.
  - `ResolveDynamicPriceAction`: Customer-tier price list and volume break tier evaluation.
  - Custom exceptions: `InsufficientStockException` (HTTP 422) and `CreditLimitExceededException` (HTTP 422).
  - Feature test suite: `tests/Feature/DomainActionsTest.php` with 7 comprehensive assertions (100% pass).

- **Phase 3 Implementation:**
  - 7 comprehensive database migration files creating 20 relational tables covering:
    - Roles (`roles`) & Staff (`staff_users`) with RBAC JSON permissions and audit logs (`audit_logs`).
    - Pricing lists (`price_lists`), price volume tiers (`price_list_items`), and promotions (`promotions`).
    - Fleets (`fleets`), customers (`customers`), and vehicles (`vehicles` - resolving the frontend audit string gap).
    - Categories (`categories`), units of measure (`units_of_measure`), suppliers (`suppliers`), products (`products`), variants (`product_options`), and stock adjustments (`stock_adjustments`).
    - Mechanics (`mechanics`), job cards (`work_orders`), job card items (`work_order_items` - resolving the zero stock deduction gap), outsourced parts (`outsourced_parts`), and commissions (`mechanic_commissions`).
    - POS invoices (`sales_orders`), line items (`sales_order_items`), multi-tender payment splits (`payment_splits`), customer credit debt repayments (`credit_settlements`), and server-parked carts (`held_orders`).
    - Purchase orders (`purchases`), replenishment items (`purchase_items`), and purchase returns (`purchase_returns`).
  - Implemented 20 Domain Eloquent Models under `app/Domains/{Domain}/Models/` with strict casts, relations, and helper methods.
  - Master Database Seeder (`database/seeders/DatabaseSeeder.php`) seeding realistic enterprise data matching `dot-pos-next`.
  - Comprehensive Pest Feature Test (`tests/Feature/DatabaseRelationalIntegrityTest.php`) verifying all relational foreign key connections (100% pass).

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
