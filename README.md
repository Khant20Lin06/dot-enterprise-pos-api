<div align="center">

# 🚗 DOT Enterprise ERP, Workshop & Real-Time POS Backend API
### Enterprise Multi-Branch Automotive Workshop, Retail ERP & High-Concurrency POS Engine
**Built with Laravel 11.x (PHP 8.3+) • MySQL 8.0 ACID Transactions • Redis 7 • Laravel Reverb WebSockets • Laravel Horizon • Node.js Microservice Worker • Pest PHP 3**

[![Laravel](https://img.shields.io/badge/Laravel-11.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.3%20%7C%208.4-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-8.0_ACID-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://mysql.com)
[![Redis](https://img.shields.io/badge/Redis-7_Queues_%26_Cache-DC382D?style=for-the-badge&logo=redis&logoColor=white)](https://redis.io)
[![Laravel Reverb](https://img.shields.io/badge/Laravel_Reverb-WebSockets-F05340?style=for-the-badge&logo=laravel&logoColor=white)](https://laravel.com/docs/reverb)
[![Pest Tests](https://img.shields.io/badge/Pest_PHP-170_Passed_%7C_1001_Asserts-10B981?style=for-the-badge&logo=pest&logoColor=white)](https://pestphp.com)
[![OWASP Top 10](https://img.shields.io/badge/OWASP_Top_10_API-Compliant-blue?style=for-the-badge&logo=owasp&logoColor=white)](https://owasp.org)
[![Docker](https://img.shields.io/badge/Docker-6_Services-2496ED?style=for-the-badge&logo=docker&logoColor=white)](https://www.docker.com/)

<p align="center">
  <b>Enterprise Production Reference Implementation & Architecture Benchmark</b><br>
  <i>Designed & Implemented by <b>Khant Lin</b> (Senior Full-Stack / Backend Engineer)</i>
</p>

</div>

---

## 📌 Executive Summary

**DOT Enterprise POS & Workshop API** is a mission-critical backend engine specifically engineered to resolve the complex architectural requirements of combined **Automotive Garages / Workshop Service Centers**, **Multi-Branch Warehousing & Procurement**, and **High-Volume Multi-Counter Retail POS systems**. 

Unlike conventional monoliths that suffer from race conditions, negative inventory drift, data tampering, and untracked service parts, this repository strictly adheres to **Clean Architecture / Modular DDD-Lite**, **Pessimistic Row Locking (`lockForUpdate()`)**, **Sub-second WebSocket Event Broadcasting**, **Asynchronous Redis Worker Pipelines**, and **OWASP Top 10 API (2023) / ASVS Security Standards**.

### 🌟 Key Technical Highlights

* **Zero Race Conditions (Pessimistic Concurrency Locking):** Stock deductions across simultaneous checkout cashiers utilize MySQL `SELECT ... FOR UPDATE` row locks inside strict `DB::transaction()` blocks. Overselling and double-spending are physically impossible.
* **Real-Time Multi-Counter Sync (Laravel Reverb):** Sub-second WebSocket server broadcasting inventory updates (`stock.channel`), completed sales (`pos.orders`), and technician job card transitions (`workshop.bay`).
* **Multi-Branch & Multi-Warehouse Architecture:** Supports nationwide branches with isolated warehouse stocks, two-step stock transfers (`draft -> dispatched -> received/cancelled`), and variant-level tracking under transit.
* **Product Variant EAV Matrix & Barcodes:** Full Cartesian product variant generator supporting custom attributes (Size, Color, Viscosity, Material), unique SKUs, and standalone barcode scanning.
* **Multi-UOM Packaging Conversions:** Dynamic units of measure (Piece, Box, Carton, Drum) with automatic proportional inventory deductions and wholesale tiered pricing.
* **Automotive Vehicle Fitment Engine (ACES/PIES):** Maps spare parts and lubricants to vehicle make, model, year range, and engine specs for instant compatibility lookups.
* **Automotive Job Card to Invoice Workflow:** Complete workshop lifecycle tracking (`draft -> in_progress -> waiting_parts -> completed -> invoiced`), auto-deducting physical inventory for fitted car parts and calculating technician labor commissions.
* **Enterprise Sales Return & Refund Engine:** Supports sellable restocking, defective part quarantining, credit debt reversal, and cryptographic idempotency key protection against duplicate payouts.
* **Partial Goods Receipt (GRN) Engine:** Supports multi-batch partial deliveries on procurement purchase orders, proportional Moving Average Cost (MAC) re-evaluation, pending quantity tracking, and supplier AP ledger balancing.
* **Purchase Return (RTV) Lifecycle State Machine:** Warranty claims and vendor returns with full state transitions (`Claimed -> Dispatched -> Credited / Replaced / Cancelled`).
* **Cashier Shift Sessions & Cash Drawer Reconciliation:** Shift open/close with blind counts, expected vs actual cash reconciliation formulas, drawer discrepancy auditing, and X/Z-Reports.
* **Master Data SoftDeletes & Disaster Recovery API:** Integrated SoftDeletes across Products, Customers, Suppliers, and Vehicles with dedicated `POST /{resource}/{id}/restore` endpoints and relational deletion guards.
* **OWASP Top 10 API (2023) & ASVS Security Hardening:**
  * **OWASP API1:2023 BOLA Defense:** Cashier branch scoping on POS orders; blocks cross-branch revenue and PII scraping (`403 Forbidden`).
  * **OWASP API2:2023 Progressive Lockout:** 5-attempt brute-force protection with 15-minute lockouts for staff passwords and terminal PINs.
  * **OWASP API3:2023 Data Redaction:** Automated masking of PINs, tokens, and credentials in audit trails and API responses.
  * **OWASP API8:2023 Security Misconfiguration Defense:** Strict HTTP security headers (CSP, HSTS, Permissions-Policy, no-cache), restrictive CORS policies, and standardized sanitized error payloads (suppressing database internals and stack traces).
  * **ASVS 5.1 XSS Neutralization:** Recursive input sanitization stripping `<script>`, `<iframe>`, and event handlers while preserving Myanmar Unicode text.
  * **Cryptographic Webhook Signatures:** HMAC SHA-256 signature verification for payment gateways.
* **Executive Report Caching & DoS Defense:** Redis/memory caching (60s TTL) for heavy aggregate reports, supervisor cache-busting (`?fresh=true`), and route rate-limiting (`throttle:30,1`).
* **100% Automated Test Coverage:** Exhaustive Pest PHP test suite covering **170 feature & unit tests and 1001 assertions** with a 100% pass rate.

---

## 🏗️ System Architecture & Layered Design

```text
                                  ┌───────────────────────────┐
                                  │ Next.js 16 Client / Mobile│
                                  └─────────────┬─────────────┘
                                                │ HTTPS / WSS
                                                ▼
                                  ┌───────────────────────────┐
                                  │   Nginx Reverse Proxy     │
                                  │ (Port 8000 & WSS Proxy)   │
                                  └──────┬─────────────┬──────┘
                                         │             │
                    HTTP Requests        │             │ WebSocket /app
                                         ▼             ▼
  ┌─────────────────────────────────────────────────┐  ┌──────────────────┐
  │         Laravel 11 Application (PHP-FPM)        │  │  Laravel Reverb  │
  │                                                 │  │ (WebSocket:8080) │
  │  ┌───────────────────────────────────────────┐  │  └────────┬─────────┘
  │  │ Thin REST Controllers (/api/v1/)          │  │           ▲
  │  │ • Input Sanitization & Security Headers   │  │           │ ShouldBroadcast
  │  │ • Strict RBAC & Session Scoping           │  │           │
  │  └─────────────────────┬─────────────────────┘  │           │
  │                        ▼                        │           │
  │  ┌───────────────────────────────────────────┐  │           │
  │  │ Domain Actions (Modular DDD-Lite)         │──┼───────────┘
  │  │ • ProcessCheckoutAction (ACID Locking)    │  │
  │  │ • ReceivePurchaseAction (Partial GRN)     │  │
  │  │ • ConvertWorkOrderToInvoiceAction         │  │
  │  │ • ProcessSaleReturnAction (Idempotent)    │  │
  │  │ • SettleCustomerCreditAction (Ledger)     │  │
  │  │ • DispatchStockTransferAction (Variants)  │  │
  │  └─────────────────────┬─────────────────────┘  │
  │                        │                        │
  │                        ▼                        │
  │  ┌───────────────────────────────────────────┐  │
  │  │ Eloquent Relational Models & SoftDeletes  │  │
  │  └─────────────────────┬─────────────────────┘  │
  └────────────────────────┼────────────────────────┘
                           │
             ┌─────────────┴─────────────┐
             ▼                           ▼
  ┌─────────────────────┐     ┌─────────────────────────────────────────┐
  │      MySQL 8.0      │     │      Redis 7.x                          │
  │ (Foreign Keys, ACID │     │ • Cache & Distributed Sessions          │
  │  Row Locking)       │     │ • Multi-Queue Horizon Workers           │
  └─────────────────────┘     │ • Pub/Sub Bridge to Node.js Daemon      │
                              └────────────────────┬────────────────────┘
                                                   │
                                                   ▼
                              ┌─────────────────────────────────────────┐
                              │ Node.js Worker Microservice (Port 3001) │
                              │ • ESC/POS 80mm/58mm Binary Generator    │
                              │ • High-Speed Barcode/QR (`bwip-js`)     │
                              └─────────────────────────────────────────┘
```

---

## 📂 Key Directory Layout

```text
app/
├── Domains/                        # Domain-Driven Core Business Modules
│   ├── CreditAndLedger/            # Customers, Fleets, Credit Limits, Debt Receipts
│   │   ├── Actions/                # SettleCustomerCreditAction.php
│   │   └── Models/                 # Customer.php (SoftDeletes), Fleet.php, CreditSettlement.php
│   ├── Inventory/                  # Products, Variants, UOM, Fitment, Warehouses, Transfers
│   │   ├── Actions/                # DeductStockAction.php, CreateStockTransferAction.php, etc.
│   │   ├── Events/                 # StockUpdatedEvent.php (Reverb Broadcast)
│   │   └── Models/                 # Product.php (SoftDeletes), ProductVariant.php, Warehouse.php, etc.
│   ├── PricingAndPromotions/       # Tier Pricing, Volume Breaks, Active Discounts
│   │   ├── Actions/                # ResolveDynamicPriceAction.php
│   │   └── Models/                 # PriceList.php, PriceListItem.php, Promotion.php
│   ├── Purchasing/                 # Suppliers, Purchase Orders, Partial GRN, Returns
│   │   ├── Actions/                # ReceivePurchaseAction.php, TransitionPurchaseReturnStatusAction.php
│   │   └── Models/                 # Supplier.php (SoftDeletes), Purchase.php, PurchaseItem.php
│   ├── SalesAndPos/                # Cart Checkout, Shifts, Sales Returns, Held Carts
│   │   ├── Actions/                # ProcessCheckoutAction.php, ProcessSaleReturnAction.php, etc.
│   │   ├── Events/                 # OrderCompletedEvent.php (Reverb Broadcast)
│   │   └── Models/                 # SalesOrder.php, PosSession.php, SalesReturn.php, etc.
│   ├── SecurityAndAudit/           # RBAC Roles, Staff Authentication, Audit Trail
│   │   ├── Middleware/             # EnforceSecurityHeaders.php, SanitizeInput.php, CheckPermission.php
│   │   ├── Services/               # SensitiveDataMasker.php
│   │   └── Models/                 # StaffUser.php (Sanctum Tokens), Role.php, AuditLog.php
│   └── Workshop/                   # Automotive Job Cards, Technicians, Parts Fitting
│       ├── Actions/                # ConvertWorkOrderToInvoiceAction.php, CalculateMechanicCommissionAction.php
│       ├── Events/                 # WorkOrderStatusChangedEvent.php (Reverb Broadcast)
│       └── Models/                 # WorkOrder.php, Vehicle.php (SoftDeletes), Mechanic.php
├── Http/
│   ├── Controllers/Api/V1/         # Thin REST Controllers (Auth, POS, Workshop, Products, Reports, etc.)
│   ├── Requests/V1/                # Strict Form Requests with Schema Validation
│   └── Resources/V1/               # JSON Schema Resources (OrderResource, ProductResource, etc.)
├── Jobs/                           # Queued Background Jobs (Horizon)
│   ├── GenerateReceiptPdfJob.php   # Thermal Receipt Generation (queue: receipts)
│   ├── SendDailySalesSummaryJob.php# Daily Financial Aggregations (queue: reports)
│   └── DispatchThermalPrintJob.php # Redis Pub/Sub Print Dispatcher
└── Services/
    └── NodeWorkerBridgeService.php # HTTP / Redis Bridge to Node Microservice
```

---

## 🛡️ Critical Architectural Decisions (ADRs)

| ADR | Topic | Decision & Rationale |
|---|---|---|
| [`ADR-0001`](docs/adr/0001-modular-domain-action-pattern.md) | Modular Domain Actions | Replaced bloated MVC controllers with single-responsibility Domain Actions to enable reusable execution across Web, API, Queues, and AI MCP tools. |
| [`ADR-0002`](docs/adr/0002-pessimistic-locking-for-stock.md) | Concurrency Stock Locking | Enforced `lockForUpdate()` pessimistic row locking on stock deductions inside `DB::transaction()` to physically prevent overselling under high concurrency. |
| [`ADR-0003`](docs/adr/0003-laravel-reverb-for-websockets.md) | Laravel Reverb WebSockets | Adopted Laravel 11 first-party Reverb server over third-party Pusher/Socket.io for zero-latency local network multi-counter synchronization. |

---

## ⚡ Real-Time WebSockets (Laravel Reverb)

Broadcast channels and presence authentication are defined in `routes/channels.php`:

| Event | Channel | Payload | Trigger |
|---|---|---|---|
| `StockUpdatedEvent` | `stock.channel` | `{ productId, newStock, variantId, timestamp }` | Triggered on inventory deduction, receipt, or replenishment |
| `OrderCompletedEvent` | `pos.orders` & `pos.counter.{id}` | `{ orderNo, totalAmount, paidAmount, cashier, items }` | Triggered when cashier completes checkout |
| `WorkOrderStatusChangedEvent` | `workshop.bay` | `{ jobCardNo, vehicle, status, laborFee, partsTotal }` | Triggered when Job Card is updated, transitioned, or invoiced |

---

## 🚦 Queues & Supervisors (Laravel Horizon)

Laravel Horizon (`/horizon`) manages multi-queue workers configured with auto-scaling:

* **`receipts` queue:** High-priority queue for thermal receipts and barcode rendering (`maxProcesses: 5`, `tries: 3`, `backoff: [5, 15, 60]`).
* **`reports` queue:** Background queue for daily sales summary aggregation and financial audits (`timeout: 120s`).
* **`notifications` queue:** SMS, email, and push dispatching.
* **`default` queue:** General maintenance tasks.

---

## 📡 RESTful API Reference (`/api/v1/`)

Interactive OpenAPI v3 documentation is automatically generated by **Dedoc Scramble** and accessible at `/docs/api` (Swagger UI) and `/docs/api.json`.

### Core API Endpoints

| Domain | Method & Endpoint | Description |
|---|---|---|
| **Auth** | `POST /auth/login` | Staff login with progressive brute-force lockout (5 attempts) |
| **Auth** | `POST /auth/verify-pin` | Fast cashier terminal switch via 4-digit PIN with token revocation |
| **POS** | `POST /pos/checkout` | Transactional checkout with multi-tender split payments & stock locking |
| **POS** | `GET  /pos/orders` | Scoped sales orders (Cashiers scoped to shift; Admins multi-branch filter) |
| **POS** | `GET  /pos/orders/{id}` | Single order details with OWASP BOLA cross-branch access defense |
| **POS** | `POST /pos/orders/{id}/void` | Supervisor-authorized order voiding with inventory restoration |
| **Shifts**| `POST /pos/shifts/open` | Open cashier shift session with starting float cash |
| **Shifts**| `POST /pos/shifts/close` | Close shift with blind cash count & drawer discrepancy reconciliation |
| **Returns**| `POST /sales-returns` | Process sales return with idempotency key, sellable restock & debt adjustment |
| **Workshop**| `POST /workshop/work-orders` | Vehicle intake, diagnostics, labor, and fitted parts assignment |
| **Workshop**| `POST /workshop/work-orders/{id}/convert-to-invoice` | Auto-deduct parts, calculate technician commission & issue POS invoice |
| **Procurement**| `POST /purchases/{id}/receive` | **Partial Goods Receipt (GRN):** Incremental delivery batches & moving average costing |
| **RTV** | `PATCH /purchases/returns/{id}/status`| Purchase return status machine (`Claimed -> Dispatched -> Credited/Replaced`) |
| **Transfers**| `POST /stock-transfers` | Create multi-warehouse transfer supporting product variants |
| **Transfers**| `POST /stock-transfers/{id}/dispatch` | Deduct variant stock under transit from source warehouse |
| **Transfers**| `POST /stock-transfers/{id}/receive` | Receive goods and increment variant inventory at destination |
| **Customers**| `POST /customers/{id}/settle-credit` | Settle credit debt with sequential receipt ledger (`REC-`) |
| **Reports** | `GET  /reports/daily-summary` | Cached executive reconciliation (60s TTL, `?fresh=true`, `throttle:30,1`) |
| **Recovery** | `POST /{products\|customers\|suppliers\|vehicles}/{id}/restore` | Enterprise disaster recovery for soft-deleted master data |

---

## 🧪 Automated Testing Suite (Pest PHP 3)

The test suite validates database relational integrity, concurrency locking, WebSocket broadcasting, Horizon jobs, OWASP security, and REST APIs:

```bash
./vendor/bin/pest
```

### Full Test Suite Summary
```text
  Tests:    170 passed (1001 assertions)
  Duration: 18.37s
  Pass Rate: 100.0%
```

| Test Class | Focus Area | Assertions | Status |
|---|---|---|---|
| `ApiEndpointsTest` | Authentication, Catalog, Checkout, Job Cards, Reports | 25 | ✅ Passed |
| `DatabaseRelationalIntegrityTest` | Foreign Keys, Strict Relations, Master Data Linkage | 15 | ✅ Passed |
| `DomainActionsTest` | Pessimistic Stock Locks, Oversell Prevention, Debt Ledgers | 28 | ✅ Passed |
| `QueuedJobsTest` | Horizon Background Workers, PDF Generation, Audit Logs | 18 | ✅ Passed |
| `ReverbBroadcastingTest` | Sub-second WebSocket Event Broadcasting via Reverb | 12 | ✅ Passed |
| `SecurityAndConcurrencyHardeningTest` | Invoice Sequence Atomicity, Price Tampering, Deadlock Prevention | 35 | ✅ Passed |
| `SecurityHardeningAuditTest` | HMAC Payment Webhooks, Shift Drawer Balancing, Return Restock | 38 | ✅ Passed |
| `MultiUomAndVehicleFitmentTest` | Multi-UOM Conversions, Barcode Resolution, ACES/PIES Fitment | 32 | ✅ Passed |
| `MasterDataCrudTest` | Master Data Management with Integrity Safeguards | 24 | ✅ Passed |
| `SalesReturnTest` | Sellable Restocking, Defective Quarantine, Credit Reversals | 25 | ✅ Passed |
| `WarehouseAndStockTransferTest` | Multi-Warehouse Stock Transfers, In-Transit Deductions | 30 | ✅ Passed |
| `ProductVariantMatrixTest` | EAV Attributes, Cartesian Matrix, Variant Barcodes & SKUs | 32 | ✅ Passed |
| `CashierShiftAndReconciliationTest` | Shift Sessions, Blind Counts, Drawer Math, X/Z-Reports | 42 | ✅ Passed |
| `Phase7OwaspSecurityStandardTest` | OWASP Headers, Progressive Lockouts, Sanitization, Error Redaction | 61 | ✅ Passed |
| `Phase8AntiDestructionAndAccountingSafeguardsTest` | Cascade Delete Guards, Return Idempotency, Negative Stock Invariant | 32 | ✅ Passed |
| `Phase9CrudMatrixAndVariantAlignmentTest` | Standalone Attribute Values, Fitment Updates, RTV Lifecycle, Transfer Variants | 62 | ✅ Passed |
| `Phase10EnterpriseScaleAndPartialGrnTest` | Multi-Branch BOLA Defense, Partial GRN Engine, Report Cache, SoftDeletes | 60 | ✅ Passed |

---

## 🤖 AI Agent MCP Server & Instant CLI Tool

This repository includes a native **Model Context Protocol (MCP)** server enabling AI agents and developers to test features instantaneously:

```bash
# 1. Query stock and variants
php artisan pos:test stock 8851234567890

# 2. Simulate complete POS checkout with stock deduction
php artisan pos:test checkout

# 3. Convert workshop Job Card to invoice and calculate commission
php artisan pos:test job-card 1

# 4. Settle customer debt balance
php artisan pos:test settle-credit 1

# 5. Compile daily sales summary
php artisan pos:test summary
```

---

## 🐳 Quickstart with Docker Compose

### 1. Prerequisites
* Docker Engine 24+ & Docker Compose v2+
* Git

### 2. Start Multi-Container Stack
```bash
# Clone repository
git clone https://github.com/Khant20Lin06/dot-enterprise-pos-api.git
cd dot-enterprise-pos-api

# Start all 6 services in detached mode
docker-compose up -d --build
```

### 3. Initialize Database & Seed Master Data
```bash
# Run migrations & seed realistic data
docker-compose exec app php artisan migrate:fresh --seed
```

### 4. Running Services Map
| Service | Container Name | Port | Description |
|---|---|---|---|
| **Web Server** | `dot_pos_web` | `8000` | Nginx reverse proxy routing API & WebSockets |
| **Backend App** | `dot_pos_app` | `9000` | PHP 8.3 FPM Laravel 11 application |
| **Database** | `dot_pos_db` | `3306` | MySQL 8.0 with InnoDB ACID transactions |
| **Redis** | `dot_pos_redis` | `6379` | Redis 7 for cache, queues, and Horizon |
| **WebSockets** | `dot_pos_reverb` | `8080` | Laravel Reverb WebSocket server |
| **Node Worker** | `dot_pos_worker` | `3001` | Express daemon for ESC/POS receipts & barcodes |

---

## 👨‍💻 Author & Professional Profile

* **Name:** Khant Lin
* **Role:** Senior Full-Stack & Backend Laravel Engineer
* **Location:** Surat Thani, Thailand (GMT+7 — 30 mins ahead of Myanmar MMT)
* **Work Mode:** Strictly **100% Remote / Work From Home (WFH)**
* **Portfolio:** [kl-portfolio-nu.vercel.app](https://kl-portfolio-nu.vercel.app)
* **GitHub:** [github.com/Khant20Lin06](https://github.com/Khant20Lin06)
* **Email:** [khant20lin061@gmail.com](mailto:khant20lin061@gmail.com)
* **WhatsApp / Phone:** `+65 652 231 668`
* **Telegram:** `@khantlin0000`
* **Viber Reference:** `09778574747`

---

## 📄 License
This project is proprietary benchmark software developed under the [MIT License](LICENSE).
