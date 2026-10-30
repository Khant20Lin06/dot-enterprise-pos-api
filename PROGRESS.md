# PROGRESS.md — Live Project Roadmap & Execution Board

**Project:** DOT Enterprise ERP, Workshop & POS Backend API (Laravel 11)  
**Status:** In Active Development  
**Last Updated:** 2026-10-30  

---

## 📊 High-Level Milestone Tracker

| Milestone | Description | Status | Progress |
| :--- | :--- | :---: | :---: |
| **Phase 1** | Enterprise Documentation & Architectural Foundation | 🟢 Completed | 100% |
| **Phase 2** | Docker Infrastructure & Laravel 11 Bootstrap | 🟢 Completed | 100% |
| **Phase 3** | Database Schemas, Foreign Keys & Migrations | 🟢 Completed | 100% |
| **Phase 4** | Core Domain Actions (Checkout, Stock Locks, Work Orders) | 🟢 Completed | 100% |
| **Phase 5** | Laravel Reverb WebSockets & Real-Time Sync | 🟢 Completed | 100% |
| **Phase 6** | Redis Queues & Laravel Horizon Integration | 🟢 Completed | 100% |
| **Phase 7** | Node.js Asynchronous Worker Microservice Bridge | 🟢 Completed | 100% |
| **Phase 8** | RESTful Controllers, JsonResources & CI/CD Pipeline | 🟢 Completed | 100% |
| **Phase 9** | AI Agent MCP Server Integration & Automated Testing | 🟢 Completed | 100% |
| **Audit I** | Red-Team Concurrency & Security Hardening (`RED_TEAM_ENTERPRISE_AUDIT.md`) | 🟢 Completed | 100% |
| **Audit II** | Forensic Enterprise Module Gap Audit (`ENTERPRISE_MODULES_GAP_AUDIT.md`) | 🟢 Completed | 100% |
| **Phase A** | Enterprise Sales Return & Refund Module (Migration, Action, API, Reverb) | 🟢 Completed | 100% |
| **Phase B** | Roles & Staff Management CRUD API (RoleController, StaffController, Redis Cache) | 🟢 Completed | 100% |
| **Phase C** | Multi-Warehouse & Multi-Branch Engine (Warehouses, Two-Step Transfers, Location Stocks) | 🟢 Completed | 100% |
| **Phase D** | Product Variants: Size & Color EAV Matrix (Attributes Master, Cartesian Matrix, Barcode Resolution) | 🟢 Completed | 100% |
| **Phase E** | Payment Gateways, Cashier Shifts & Reconciliation (Shift Open/Close, Blind Count, MMQR, X/Z-Reports) | 🟢 Completed | 100% |
| **Phase F** | Enterprise Red-Team Hardening & Vulnerability Remediation (CRIT-01 to CRIT-04, HIGH-01 to HIGH-04, MED-01 to MED-03) | 🟢 Completed | 100% |
| **Phase G** | Multi-UOM Packaging Conversions & Automotive Vehicle Fitment Matrix (Drum/Box/Carton, ACES/PIES compatibility) | 🟢 Completed | 100% |
| **Phase H** | Master Data CRUD, Inbound Procurement & Workshop Workflow Harmonization (Customers, Vehicles, Purchases, Suppliers, Stock Adjustments) | 🟢 Completed | 100% |

---

## 📝 Detailed Task Checklist

### Phase 1: Master Documentation & System Specifications
- [x] Create `AGENTS.md` (Rules of engagement, code guidelines, testing standards)
- [x] Create `PROGRESS.md` (Live Execution Board & milestone tracker)
- [x] Create `CHANGELOG.md` (Keep-a-Changelog standard)
- [x] Create `docs/ARCHITECTURE.md` (Domain-Driven Clean Architecture & layer interactions)
- [x] Create `docs/DATABASE_SCHEMA.md` (Full relational entity models, foreign keys, indexes)
- [x] Create `docs/API_SPEC.md` (Complete REST API request/response contracts for Next.js)
- [x] Create Architecture Decision Records (ADRs):
  - [x] `docs/adr/0001-modular-domain-action-pattern.md`
  - [x] `docs/adr/0002-pessimistic-locking-for-stock.md`
  - [x] `docs/adr/0003-laravel-reverb-for-websockets.md`

---

### Phase 2: Docker Environment & Laravel 11 Bootstrap
- [x] Create `docker-compose.yml` (PHP 8.3 FPM, Nginx, MySQL 8, Redis 7, Reverb, Node.js Worker)
- [x] Create multi-stage `Dockerfile` with optimized PHP extensions (pdo_mysql, redis, pcntl, bcmath, intl, zip, gd, opcache)
- [x] Create Nginx reverse proxy configuration (`docker/nginx/default.conf`) with Reverb WebSocket proxy
- [x] Initialize clean Laravel 11 project structure
- [x] Configure `.env` and `.env.example` with Redis, Reverb, MySQL, Sanctum and Node Worker
- [x] Setup Node.js Async Worker Microservice (`services/node-worker/`) with ESC/POS & Barcode generation
- [x] Install core packages (`laravel/sanctum`, `laravel/reverb`, `laravel/horizon`, `pestphp/pest`, `dedoc/scramble`)
- [x] Create Domain-Driven Modular architecture directories under `app/Domains/`

---

### Phase 3: Relational Database Schemas & Migrations
- [x] **Security & Staff:** `staff_users`, `roles`, `permissions`, `audit_logs`
- [x] **Inventory & Products:** `categories`, `units_of_measure`, `products`, `product_options`, `stock_adjustments`
- [x] **Workshop & Automotive:** `vehicles`, `fleets`, `mechanics`, `work_orders`, `work_order_items`, `outsourced_parts`, `mechanic_commissions`
- [x] **Sales & POS:** `sales_orders`, `sales_order_items`, `payment_splits`, `held_orders`, `credit_settlements`
- [x] **Purchases & Suppliers:** `suppliers`, `purchases`, `purchase_items`, `purchase_returns`
- [x] **Pricing & Promotions:** `price_lists`, `price_list_items`, `promotions`
- [x] **Credit & Customers:** `customers`, `fleets`, `credit_settlements`
- [x] Seed comprehensive mock data matching the `dot-pos-next` dataset
- [x] Created Eloquent models under `app/Domains/{Domain}/Models/` with strict casts and relationships
- [x] Authored and passed Pest feature tests (`tests/Feature/DatabaseRelationalIntegrityTest.php`)

---

### Phase 4: Core Domain Actions & Business Logic
- [x] `ProcessCheckoutAction` (ACID transaction, Pessimistic row locking via `lockForUpdate()`, payment splits)
- [x] `DeductStockAction` & `ReplenishStockAction`
- [x] `ConvertWorkOrderToInvoiceAction` (Parts auto-deducted from stock catalog & mechanic commission)
- [x] `CalculateMechanicCommissionAction` (Auto compute labor percentage & log commission)
- [x] `SettleCustomerCreditAction` (Partial & Full settlement with receipt ledger)
- [x] `ResolveDynamicPriceAction` (Customer tier + Volume break price evaluation)
- [x] Custom exceptions: `InsufficientStockException` and `CreditLimitExceededException`
- [x] Comprehensive Pest feature test suite: `tests/Feature/DomainActionsTest.php` (100% pass)

---

### Phase 5: Real-Time WebSockets (Laravel Reverb)
- [x] Setup Laravel Reverb on port 8080 and publish configs (`config/reverb.php`, `config/broadcasting.php`)
- [x] Create `StockUpdatedEvent` (Broadcast on `stock.channel` for live multi-counter stock sync)
- [x] Create `OrderCompletedEvent` (Broadcast on `pos.orders` for live screen updates)
- [x] Create `WorkOrderStatusChangedEvent` (Broadcast on `workshop.bay` for workshop monitor display)
- [x] Configure channel authorization and presence channel in `routes/channels.php`
- [x] Connect Reverb broadcast events to `DeductStockAction`, `ProcessCheckoutAction`, and `ConvertWorkOrderToInvoiceAction`
- [x] Authored and passed comprehensive feature test suite: `tests/Feature/ReverbBroadcastingTest.php` (100% pass)

---

### Phase 6: Redis Queues & Laravel Horizon
- [x] Setup Laravel Horizon dashboard (`/horizon`) & multi-queue supervisors (`receipts`, `reports`, `notifications`, `default`)
- [x] Create `GenerateReceiptPdfJob` (Queued background thermal slip/PDF generation)
- [x] Create `SendDailySalesSummaryJob` (Daily executive sales and workshop reports)
- [x] Implement failed job retry mechanisms and backoff strategies (`$tries = 3`, `$backoff`, `failed()` audit alerts)
- [x] Authored and passed comprehensive feature test suite: `tests/Feature/QueuedJobsTest.php` (100% pass)

---

### Phase 7: Node.js Microservice Async Worker Bridge
- [x] Lightweight Node.js worker service under `services/node-worker/`
- [x] Implement high-speed Barcode/QR generation (`bwip-js`)
- [x] Implement ESC/POS binary slip formatting
- [x] Connect Laravel to Node.js via Redis Pub/Sub (`pos:print-job`, `pos:barcode-gen`) & HTTP Bridge (`NodeWorkerBridgeService.php`)

---

### Phase 8: RESTful API Controllers, Pest Test Suite & CI/CD
- [x] Implemented Thin RESTful Controllers under `app/Http/Controllers/Api/V1/` (`AuthController`, `PosController`, `WorkOrderController`, `CustomerCreditController`, `ProductController`, `ReportController`)
- [x] Created strict Form Requests under `app/Http/Requests/V1/` (`LoginRequest`, `VerifyPinRequest`, `CheckoutRequest`, `CreateWorkOrderRequest`, `SettleCreditRequest`)
- [x] Created consistent API Json Resources under `app/Http/Resources/V1/` (`StaffUserResource`, `ProductResource`, `SalesOrderResource`, `WorkOrderResource`, `CustomerResource`)
- [x] Registered 23 versioned RESTful routes under `/api/v1/` with Sanctum Bearer token authentication
- [x] Auto-generated OpenAPI v3 documentation via Dedoc Scramble (`/docs/api`, `/docs/api.json`)
- [x] Feature Test: `ApiEndpointsTest` (Auth, Pin verify, Search products, POS checkout, Parked cart, Job card conversion, Credit settlement, Daily summary)
- [x] Automated CI/CD pipeline configured with GitHub Actions (`.github/workflows/ci.yml`) for PHP 8.3 & 8.4 with MySQL and Redis

---

### Phase 9: Laravel MCP Server Integration
- [x] Created dedicated MCP Server under `C:\Users\Admin\.gemini\antigravity\mcp\laravel-pos/` with `instructions.md`
- [x] Implemented MCP Tool Schemas:
  - `pos_checkout.json`: AI direct invocation of ACID POS transactions with multi-split tenders
  - `query_stock.json`: AI real-time stock levels and variant pricing queries
  - `workshop_job_card.json`: AI automotive job card inspection and invoice conversion
  - `customer_credit_settle.json`: AI customer debt recovery and ledger balance checks
  - `daily_sales_summary.json`: AI real-time executive sales compilation
- [x] Created Artisan CLI testing tool `php artisan pos:test {action}` for rapid developer & agent verification
- [x] Full test suite verification: **32 passed (140 assertions) — 100% pass rate**

---

### Phase D: Product Variants (Size & Color EAV Matrix)
- [x] Schema Migration: `2026_10_30_000011_create_product_attributes_and_variants_tables.php`
  - Created `attributes` table (`id`, `code`, `name`, `description`).
  - Created `attribute_values` table (`id`, `attribute_id`, `value`, `color_hex`, `sort_order`).
  - Created `product_variants` table (`id`, `product_id`, `sku`, `barcode`, `price`, `cost_price`, `stock`, `is_active`).
  - Created `variant_attribute_values` pivot table (`product_variant_id`, `attribute_value_id`).
  - Added `has_variants` boolean flag to `products`.
  - Added `product_variant_id` foreign key to `sales_order_items`.
- [x] Eloquent Models with Strict Relationships:
  - `Attribute`: `hasMany(AttributeValue::class)`
  - `AttributeValue`: `belongsTo(Attribute::class)`, `belongsToMany(ProductVariant::class)`
  - `ProductVariant`: `belongsTo(Product::class)`, `belongsToMany(AttributeValue::class)`
  - `Product`: `hasMany(ProductVariant::class)`, `has_variants` casting
  - `SalesOrderItem`: `belongsTo(ProductVariant::class, 'product_variant_id')`
- [x] Domain Business Actions:
  - `CreateAttributeAction`: Validates attribute uniqueness and creates nested values with sort order.
  - `GenerateVariantMatrixAction`: Computes Cartesian product permutations across N-axes of attributes, produces structured SKUs (`{barcode}-{SIZE}-{COLOR}`), assigns unique barcodes, and synchronizes master product stock (`products.stock = sum(variants.stock)`).
  - `ResolveBarcodeAction`: Ultra-fast dual-resolution scanner prioritizing variant barcodes and SKUs, falling back to master product barcodes.
  - `DeductStockAction`: Enhanced to support row-locking deduction on specific `ProductVariant` rows alongside master inventory.
  - `ProcessCheckoutAction`: Seamlessly records `product_variant_id` on `sales_order_items` and deducts variant stock with concurrency-safe locking.
- [x] RESTful API Endpoints & Resources:
  - `AttributeController`: `GET /api/v1/attributes`, `POST /api/v1/attributes`, `GET /api/v1/attributes/{id}`.
  - `ProductController` variant extensions: `GET /api/v1/products/{id}/variants`, `POST /api/v1/products/{id}/variants/matrix`, `GET /api/v1/products/barcode/{barcode}`.
- [x] Pest Feature Test Suite:
  - `tests/Feature/ProductVariantMatrixTest.php`: 6 passed (51 assertions).
  - Full Test Suite at Phase D: **72 passed (364 assertions)**.

---

### Phase E: Payment Gateways, Cashier Shifts & Cash Reconciliation
- [x] Schema Migration: `2026_10_30_000012_create_pos_sessions_and_payment_gateways_tables.php`
  - Created `pos_sessions` table (`session_no`, `cashier_id`, `terminal_id`, `warehouse_id`, `opening_float`, `status`, `opened_at`, `closed_at`, `counted_cash`, `expected_cash`, `cash_variance`, tender totals).
  - Created `payment_transactions` table (`transaction_no`, `pos_session_id`, `sales_order_id`, `provider`, `amount`, `currency`, `qr_payload`, `status`, `gateway_ref`).
  - Added `pos_session_id` foreign key to `sales_orders` and `sales_returns`.
  - Added `status`, `gateway_provider`, `gateway_payload` to `payment_splits`.
- [x] Payment Gateway Driver Pattern & Contracts:
  - `PaymentGatewayDriverInterface`: contracts for QR generation, webhook verification, status queries, and refunds.
  - `KbzPayGatewayDriver`: MMQR EMV standard specification and KBZPay deeplink generator.
  - `WavePayGatewayDriver`: Wave Money deeplink and QR intent generator.
  - `PaymentGatewayManager`: driver resolution by provider slug.
- [x] Domain Business Actions & Hardened Logic:
  - `OpenPosSessionAction`: Enforces single-active-session invariant per cashier and per terminal with pessimistic row locking; generates atomic sequential session numbers (`SHIFT-YYYYMMDD-0001`).
  - `ClosePosSessionAction`: Blind cash count drawer reconciliation. Computes `expected_cash = opening_float + cash_sales - cash_refunds` and detects cash variance (Over/Short) with automated audit log severity flags.
  - `GenerateShiftAuditReportAction`: Real-time compilation of interim **X-Report** (during open shift) and final **Z-Report** (upon shift close) with tender breakdown and drawer variance audit.
  - `CreatePaymentTransactionAction`: Asynchronous payment intent generation for customer display terminals.
  - `ProcessCheckoutAction` & `ProcessSaleReturnAction`: Automatically associates transactions with active open cashier shift session.
- [x] Real-Time WebSockets (Laravel Reverb):
  - `PosSessionOpenedEvent`: Broadcasts on `pos.sessions` channel upon shift startup.
  - `PosSessionClosedEvent`: Broadcasts on `pos.sessions` channel with Z-Report summary upon shift close.
- [x] RESTful API Endpoints & Resources:
  - `PosSessionController`: `GET /`, `GET /current`, `POST /open`, `GET /{id}`, `POST /{id}/close`, `GET /{id}/x-report`, `GET /{id}/z-report`.
  - `PaymentGatewayController`: `POST /generate-qr`, `GET /transactions/{no}`, `POST /simulate-webhook`.
- [x] Pest Feature Test Suite:
  - `tests/Feature/PosSessionAndPaymentReconciliationTest.php`: 9 passed (50 assertions).
  - Full Test Suite: **81 passed (414 assertions) — 100% pass rate in 4.20s**.

---

### Phase F: Enterprise Red-Team Hardening & Vulnerability Remediation
- [x] Schema Migration: `2026_10_30_000013_security_and_enterprise_hardening.php`
  - Added `pos_session_id` nullable FK to `credit_settlements` table.
  - Added `product_variant_id` nullable FK to `sales_return_items` table.
  - Added `change_amount` decimal(14,2) to `sales_orders` table.
- [x] **CRIT-01: Cryptographic Webhook Security & Auth Boundary**
  - Moved `POST /api/v1/payments/webhook` outside `auth:sanctum` to a public boundary.
  - Created `VerifyPaymentGatewaySignature` middleware validating `X-Signature` via HMAC-SHA256 (`PAYMENT_GATEWAY_WEBHOOK_SECRET`).
  - Added immutable audit trail for verified and forged webhook callbacks.
- [x] **CRIT-02: Workshop & Credit Settlement Shift Drawer Reconciliation**
  - `ConvertWorkOrderToInvoiceAction`: Automatically links generated sales order to active cashier register shift (`pos_session_id`).
  - `SettleCustomerCreditAction`: Associates credit settlement receipts with active cashier shift.
  - `ClosePosSessionAction`: Unified cash drawer formula: `expected_cash = opening_float + net_cash_sales + credit_settlement_cash - cash_refunds`.
  - `GenerateShiftAuditReportAction`: Real-time X/Z-Reports reflect credit settlement collections and cash change breakdown.
- [x] **CRIT-03: Multi-Warehouse & Product Variant Sales Return Restock**
  - `ReplenishStockAction`: Enhanced with `?int $productVariantId = null` to increment both `ProductVariant::stock` and `WarehouseStock::stock_qty`.
  - `ProcessSaleReturnAction`: Captures `product_variant_id` and targets cashier's active warehouse location for sellable returns.
- [x] **CRIT-04: Deterministic Global Lock Hierarchy (Deadlock Prevention)**
  - `DispatchStockTransferAction`: Standardized ascending `product_id` lock acquisition ordering: locks `Product` master before `WarehouseStock`.
  - `ReceiveStockTransferAction` & `CancelStockTransferAction`: Symmetrical ascending lock order to eliminate circular lock waits with POS Checkouts.
- [x] **HIGH-01: Shift Opening Race Condition Protection**
  - `OpenPosSessionAction`: Protected via atomic cache lock (`pos_session_open_cashier_{id}`) before transaction execution.
- [x] **HIGH-02: Loyalty Points Arbitrage Protection**
  - `ProcessSaleReturnAction`: Mitigates points depletion exploit by clawing back equivalent cash from refund amount when customer loyalty balance is insufficient.
- [x] **HIGH-03: Strict RBAC Route Middleware**
  - Enforced `permission:pos,create` on checkout and shift actions; `permission:pos,view` on orders and sessions; `permission:settings,manageRoles` on staff management endpoints.
- [x] **HIGH-04: Trusted Proxies Configuration**
  - Configured `$middleware->trustProxies()` in `bootstrap/app.php` with standard private IP ranges and `X-Forwarded-*` headers.
- [x] **MED-01, MED-02, MED-03 Hardening**
  - MED-01: Recorded `change_amount` on `SalesOrder` and netted against cash drawer calculations.
  - MED-02: Automatically revokes stale `pin-session` tokens upon new PIN verification in `AuthController`.
  - MED-03: Reconciles lost/damaged-in-transit goods in `ReceiveStockTransferAction` with `StockAdjustment` (Damage / Scrap) and asset reduction.
- [x] Pest Feature Test Suite:
  - `tests/Feature/SecurityHardeningAuditTest.php`: 12 passed (38 assertions).
  - Full Test Suite: **93 passed (452 assertions) — 100% pass rate in 5.55s**.

---

### Phase G: Multi-UOM Packaging Conversions & Automotive Vehicle Fitment Matrix
- [x] Schema Migration: `2026_10_30_000014_create_multi_uom_and_vehicle_fitment_tables.php`
  - Created `product_uom_conversions` table (`id`, `product_id`, `uom_id`, `conversion_factor`, `barcode`, `sell_price`, `cost_price`, `is_active`).
  - Created `product_vehicle_fitments` table (`id`, `product_id`, `make`, `model`, `year_start`, `year_end`, `engine_code`, `chassis_code`, `notes`).
  - Added packaging UOM tracking columns to `sales_order_items`: `uom_conversion_id`, `uom_id`, `conversion_factor`, `packaging_qty`.
  - Added packaging UOM tracking columns to `work_order_items`: `uom_conversion_id`, `uom_id`, `conversion_factor`.
- [x] Eloquent Models with Strict Relationships:
  - `ProductUomConversion`: `belongsTo(Product::class)`, `belongsTo(UnitOfMeasure::class)`.
  - `ProductVehicleFitment`: `belongsTo(Product::class)`.
  - `Product`: added `uomConversions()` and `vehicleFitments()`.
  - `UnitOfMeasure`: added `uomConversions()`.
  - `SalesOrderItem` & `WorkOrderItem`: added `uomConversion()` and `uom()` relationships.
- [x] Domain Business Actions:
  - `CreateProductUomConversionAction`: Enforces positive conversion factors, blocks conversions using product's base UOM, prevents duplicate UOM rules per product, and asserts unique packaging barcodes.
  - `AttachVehicleFitmentAction`: Validates year range boundaries (`year_end >= year_start`), sanitizes uppercase Make/Model, and links fitment criteria.
  - `SearchVehicleCompatiblePartsAction`: Database-agnostic cross-matching between registered customer vehicles (make, model, year, engine code) and spare parts catalog.
  - `ResolveBarcodeAction`: Ultra-fast scanner prioritization matching packaging UOM barcodes first (`type: packaging_uom`), returning base product and conversion metadata.
  - `ProcessCheckoutAction`: Automatically resolves packaging unit conversions, calculates packaging line totals, computes base quantity `baseQty = ceil(qty * conversion_factor)`, and safely deducts master inventory with pessimistic locking.
- [x] RESTful API Endpoints & Resources:
  - `ProductUomConversionController`: `GET /api/v1/products/{id}/uom-conversions`, `POST /api/v1/products/{id}/uom-conversions`, `DELETE /api/v1/products/{id}/uom-conversions/{conversionId}`.
  - `VehicleFitmentController`: `GET /api/v1/products/{id}/fitments`, `POST /api/v1/products/{id}/fitments`, `DELETE /api/v1/products/{id}/fitments/{fitmentId}`, `GET /api/v1/vehicles/{id}/compatible-parts`, `GET /api/v1/catalog/compatibility-search`.
- [x] Pest Feature Test Suite:
  - `tests/Feature/MultiUomAndVehicleFitmentTest.php`: 10 passed (37 assertions).
  - Full Test Suite: **103 passed (489 assertions) — 100% pass rate in 5.49s**.

---

### Phase H: Master Data CRUD, Inbound Procurement & Workshop Workflow Harmonization
- [x] **Bug Fix & Schema Remediation:**
  - Fixed `CustomerCreditController` `current_due` SQL bug (`due_amount`), resolving MySQL 8.0 500 error.
  - Removed missing `code` column query from customer search filter.
- [x] **Master Data Full CRUD Endpoints:**
  - `CustomerController`: `GET|POST /api/v1/customers`, `GET|PUT|DELETE /api/v1/customers/{id}` with debt guards (`due_amount > 0`) and transaction history safeguards.
  - `VehicleController`: `GET|POST /api/v1/vehicles`, `GET|PUT|DELETE /api/v1/vehicles/{id}` with plate number uniqueness and active work order guards.
  - `CategoryController`: `GET|POST /api/v1/categories`, `GET|PUT|DELETE /api/v1/categories/{id}` with linked product deletion guards.
  - `UnitOfMeasureController`: `GET|POST /api/v1/units-of-measure`, `GET|PUT|DELETE /api/v1/units-of-measure/{id}` with in-use guards.
  - `ProductController`: Implemented missing `store()`, `update()`, and `destroy()` methods with automatic warehouse initial stock reconciliation and sales history protection.
- [x] **Inbound Procurement & Supplier Supply Chain:**
  - `SupplierController`: `GET|POST|PUT|DELETE /api/v1/suppliers` with purchase history protection.
  - `ReceivePurchaseAction`: ACID transaction recalculating Weighted Moving Average Cost, updating physical warehouse stock and master inventory with row locking, and crediting supplier payables.
  - `PurchaseController`: `GET|POST /api/v1/purchases`, `GET /api/v1/purchases/{id}`, `POST /api/v1/purchases/{id}/receive` (GRN), `POST /api/v1/purchases/{id}/cancel`.
  - `StockAdjustmentController`: `GET|POST /api/v1/stock-adjustments` (Cycle Count, Scrap/Damage, write-offs) with staff audit logging and negative stock violation guards.
- [x] **Workshop Operations & Payment Harmonization:**
  - `WorkOrderController`: Implemented `PUT /work-orders/{id}` for live job card edits (recalculating parts, labor, and grand totals), `PATCH /work-orders/{id}/status` state machine, and `POST /work-orders/{id}/cancel`.
  - `ConvertWorkOrderToInvoiceAction`: Refactored to support multi-tender split payments (Cash + KPay + Wave + Card + Credit) and warehouse-context stock deduction.
  - Broadcasts `WorkOrderStatusChangedEvent` on Reverb `workshop.bay` channel on status updates and cancellations.
- [x] **Business Logic & Accounting Invariant Alignment:**
  - `ProcessSaleReturnAction`: Aligned with Packaging UOM returns, restoring base stock proportionally and refunding full packaging price.
  - `ProcessCheckoutAction`: Integrated `ResolveDynamicPriceAction` for automatic customer wholesale tier pricing and volume breaks; resolved cashier active shift session warehouse to eliminate stock drift.
- [x] **Pest Feature Test Suite:**
  - `tests/Feature/MasterDataCrudTest.php`: 4 passed (39 assertions).
  - `tests/Feature/PurchasesAndSuppliersTest.php`: 3 passed (22 assertions).
  - `tests/Feature/WorkshopWorkflowAndPricingAlignmentTest.php`: 5 passed (23 assertions).
  - Full Test Suite: **115 passed (573 assertions) — 100% pass rate in 6.22s**.
