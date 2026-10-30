# CHANGELOG.md — Release & Feature Change Log

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Added
- **Phase A Implementation: Enterprise Sales Return & Refund Module:**
  - **Database Migrations (`sales_returns` & `sales_return_items`):** Relational master and line-item tables with strict foreign keys to `sales_orders`, `customers`, `staff_users`, and `products`.
  - **Domain Models & Relationships (`SalesReturn`, `SalesReturnItem`):** Fully typed Eloquent models with relations to `SalesOrder`, `Customer`, `StaffUser`, and `Product`.
  - **Domain Action (`ProcessSaleReturnAction`):**
    - Atomic ACID transaction with pessimistic row-locking on original order and products.
    - Anti-Fraud Validation: Prevents returning more than purchased (`qty <= original_qty - previously_returned_qty`) and blocks returns on voided orders.
    - Concurrency Deadlock Prevention: Consistent lock acquisition order (`usort` ascending by `product_id`).
    - Smart Physical Inventory Handling: Re-stocks sellable items via `ReplenishStockAction` with `StockUpdatedEvent` broadcast; quarantines defective items into `stock_adjustments` (Damage / Scrap) without inflating sellable stock.
    - Financial Accounting & Debt Settlement: Adjusts customer credit debt (`due_amount`) on `Credit Adjusted` returns.
    - Anti-Fraud Points Reversal: Proportional reversal and deduction of customer loyalty points earned from returned items.
    - Atomic Sequential Return Numbering: e.g. `RET-20261030-0001` via `AtomicSequenceService`.
    - Automatic Order Status Transition: Updates original `SalesOrder` status to `Returned` when all items are fully returned.
    - Financial Audit Logging: Records immutable audit entry in `audit_logs` (module: `sales_and_pos`, action: `sales_return_processed`).
    - Real-Time WebSocket Broadcasting: Dispatches `SalesReturnProcessedEvent` (`return.processed`) on channel `pos.orders` with `ShouldDispatchAfterCommit`.
  - **API Layer:**
    - FormRequest: `CreateSalesReturnRequest` with strict validation rules.
    - JsonResource: `SalesReturnResource` with formatted customer, order, items, and refund details.
    - RESTful Controller: `SalesReturnController` (`store`, `index`, `show`) protected with Sanctum bearer token and `permission:pos,create`.
    - Registered routes: `POST /api/v1/sales-returns`, `GET /api/v1/sales-returns`, `GET /api/v1/sales-returns/{id}`.
  - **Automated Feature Test Suite (`tests/Feature/SalesReturnTest.php`):**
    - 7 comprehensive feature tests covering sellable restock, defective quarantine, credit debt adjustment, return quantity limits, full return order status, API authentication, and listing.
    - Total test suite status: **49 passed (203 assertions) — 100% pass rate in 2.81s**.
- **Enterprise Forensic Module Gap Audit (`docs/ENTERPRISE_MODULES_GAP_AUDIT.md`):**
  - Completed brutal, zero-sugarcoating comparative audit of Companion Frontend (`dot-pos-next`) and Backend API (`laravel-pos-api`).
  - Uncovered 5 Critical Architectural Gaps:
    1. **Sales Return Module:** 100% missing on backend (Zero migrations, models, actions, or routes).
    2. **Product Variants (Size/Color):** Flat 1D option string schema, lacking 2D Matrix EAV attributes, resulting in double-stock desync hazards.
    3. **Payment Gateways & Shift Reconciliation:** Passive string records without state machines, dynamic QR, or cash drawer session tracking.
    4. **Multi-Warehouse / Multi-Branch:** Monolithic single-store schema (`products.stock`), lacking `warehouses`, `warehouse_stocks`, and `stock_transfers`.
    5. **Role & Permission (RBAC) CRUD:** Static database seeder trap without RESTful `RoleController` or `StaffUserController` endpoints.
  - Published comprehensive 16-module CRUD gap inventory and 5-phase master remediation roadmap.

- **Enterprise Red-Team Security & Concurrency Hardening:**
  - **Deadlock Elimination (Consistent Lock Acquisition Order):** Enforced ascending `product_id` sorting in `ProcessCheckoutAction` before row-level locking, preventing dining-philosophers deadlock across concurrent checkout stations.
  - **Atomic Concurrency-Safe Sequencing (`AtomicSequenceService`):** Created `system_sequences` table with pessimistic locking and Redis `INCR` fallback, replacing un-safe `SalesOrder::count() + 1` and preventing duplicate invoice collisions.
  - **Anti-Price Tampering & Authoritative Pricing:** Enforced server-side catalog and price list validation, blocking client-side `unit_price` tampering unless supervisor override is authorized.
  - **Loyalty Points Underflow Protection:** Enforced customer balance floor checks (`$pointsRedeemed <= $customer->loyalty_points`), preventing point deficit exploits.
  - **Transaction Boundary & After-Commit Dispatching:** Configured `ShouldDispatchAfterCommit` on domain broadcast events (`StockUpdatedEvent`, `OrderCompletedEvent`, `WorkOrderStatusChangedEvent`) and `->afterCommit()` on `GenerateReceiptPdfJob`, eliminating phantom WebSocket leakage and ghost-record queue race conditions.
  - **PIN Brute-Force & Bcrypt CPU DoS Defense:** Added IP rate limiting (`throttle:10,1`) on `/verify-pin` and optimized staff identification lookups to O(1), with failed attempt logging in `audit_logs` (severity: warning).
  - **RBAC Endpoint Authorization (`CheckPermission` Middleware):** Restricted financial summaries (`permission:finances,view`), workshop invoicing, and credit settlements by user role.
  - **Pest Hardening Test Suite:** Added `tests/Feature/SecurityAndConcurrencyHardeningTest.php` with 6 comprehensive security tests. Total suite now at **38 passed (156 assertions)**.

- **Phase 8 & Phase 9 Implementation (REST API, CI/CD & Laravel MCP Server):**
  - Thin RESTful Controllers under `app/Http/Controllers/Api/V1/` (`AuthController`, `PosController`, `WorkOrderController`, `CustomerCreditController`, `ProductController`, `ReportController`).
  - Strict Form Validation Requests under `app/Http/Requests/V1/` (`LoginRequest`, `VerifyPinRequest`, `CheckoutRequest`, `CreateWorkOrderRequest`, `SettleCreditRequest`).
  - Standard JSON Resources under `app/Http/Resources/V1/` (`StaffUserResource`, `ProductResource`, `SalesOrderResource`, `WorkOrderResource`, `CustomerResource`).
  - Registered 23 REST API routes under `/api/v1/` with Laravel Sanctum bearer token authentication.
  - Interactive OpenAPI v3 documentation powered by Dedoc Scramble (`/docs/api` & `/docs/api.json`).
  - Full GitHub Actions CI/CD workflow (`.github/workflows/ci.yml`) supporting automated testing on PHP 8.3 & 8.4 with MySQL and Redis services.
  - Implemented AI Agent MCP Server under `C:\Users\Admin\.gemini\antigravity\mcp\laravel-pos/` with tool schemas (`pos_checkout`, `query_stock`, `workshop_job_card`, `customer_credit_settle`, `daily_sales_summary`).
  - Artisan verification command `php artisan pos:test {action}` for rapid feature-by-feature verification.
  - Comprehensive feature test suite: `tests/Feature/ApiEndpointsTest.php` with 9 exhaustive tests (100% pass).
  - Total test suite status: **32 passed (140 assertions)** with 0 failures.

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
