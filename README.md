<div align="center">

# 🚗 DOT Enterprise ERP, Workshop & Real-Time POS Backend API
### Enterprise Multi-Branch Automotive Workshop, Retail ERP & High-Concurrency POS Engine
**Built with Laravel 11.x (PHP 8.3+) • MySQL 8.0 ACID Transactions • Redis 7 • Laravel Reverb WebSockets • Laravel Horizon • Node.js Microservice Worker • Pest PHP 3**

[![Laravel](https://img.shields.io/badge/Laravel-11.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.3%20%7C%208.4-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-8.0_ACID-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://mysql.com)
[![Redis](https://img.shields.io/badge/Redis-7_Queues-DC382D?style=for-the-badge&logo=redis&logoColor=white)](https://redis.io)
[![Laravel Reverb](https://img.shields.io/badge/Laravel_Reverb-WebSockets-F05340?style=for-the-badge&logo=laravel&logoColor=white)](https://laravel.com/docs/reverb)
[![Pest Tests](https://img.shields.io/badge/Pest_PHP-32_Passed_%7C_140_Asserts-10B981?style=for-the-badge&logo=pest&logoColor=white)](https://pestphp.com)
[![Docker](https://img.shields.io/badge/Docker-6_Services-2496ED?style=for-the-badge&logo=docker&logoColor=white)](https://www.docker.com/)

<p align="center">
  <b>Production Benchmark Developed for Enrich Insights Consulting Services Co., Ltd / Tech Mart MM</b><br>
  <i>Designed & Implemented by <b>Khant Lin</b> (Senior Full-Stack / Backend Engineer)</i>
</p>

</div>

---

## 📌 Executive Summary

**DOT Enterprise POS & Workshop API** is a mission-critical backend engine specifically engineered to resolve the complex architectural requirements of combined **Automotive Garages / Workshop Service Centers** and **High-Volume Multi-Counter Retail POS systems**. 

Unlike conventional monoliths that suffer from race conditions, negative inventory drift, and untracked service parts, this repository strictly adheres to **Clean Architecture / Modular DDD-Lite**, **Pessimistic Row Locking (`lockForUpdate()`)**, **Sub-second WebSocket Event Broadcasting**, and **Asynchronous Redis Worker Pipelines**.

### 🌟 Key Technical Highlights
* **Zero Race Conditions (Pessimistic Concurrency Locking):** Stock deductions across simultaneous checkout cashiers utilize MySQL `SELECT ... FOR UPDATE` row locks inside strict `DB::transaction()` blocks. Overselling is physically impossible.
* **Real-Time Multi-Counter Sync (Laravel Reverb):** Sub-second WebSocket server broadcasting inventory deductions (`stock.channel`), completed sales (`pos.orders`), and technician job card transitions (`workshop.bay`).
* **Automated Automotive Job Card to Invoice Flow:** Converts automotive job cards to official POS invoices, automatically deducting physical inventory for fitted car parts and calculating technician labor commissions.
* **Multi-Tender Payment Splits & Credit Accounting:** Supports multi-split tender checkout (Cash + KPay + Card + Customer Credit Debt), enforces credit limit constraints, and logs debt settlement receipts (`REC-YYYYMMDD-XXXX`).
* **Microservice Thermal Printing & Barcode Daemon:** Offloads ESC/POS binary receipt byte generation and high-speed Code128/QR generation to an asynchronous Node.js worker with Redis Pub/Sub fallback.
* **100% Automated Test Coverage:** Exhaustive Pest PHP feature test suite covering 32 test cases and 140 assertions across relational integrity, concurrency locking, WebSockets, background queues, and REST APIs.
* **AI Agent & Developer MCP Server:** Native Model Context Protocol (MCP) server enabling AI agents and developers to invoke transactional checkout, stock queries, and job card conversions directly via tools.

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
  │  └─────────────────────┬─────────────────────┘  │           │ ShouldBroadcast
  │                        ▼                        │           │
  │  ┌───────────────────────────────────────────┐  │           │
  │  │ Domain Actions (Modular DDD-Lite)         │──┼───────────┘
  │  │ • ProcessCheckoutAction (ACID Locking)    │  │
  │  │ • ConvertWorkOrderToInvoiceAction         │  │
  │  │ • DeductStockAction (Pessimistic Lock)    │  │
  │  │ • SettleCustomerCreditAction (Ledger)     │  │
  │  └─────────────────────┬─────────────────────┘  │
  │                        │                        │
  │                        ▼                        │
  │  ┌───────────────────────────────────────────┐  │
  │  │ Eloquent Relational Models (20 Tables)    │  │
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
│   │   └── Models/                 # Customer.php, Fleet.php, CreditSettlement.php
│   ├── Inventory/                  # Products, Variants, UOM, Stock Adjustments
│   │   ├── Actions/                # DeductStockAction.php, ReplenishStockAction.php
│   │   ├── Events/                 # StockUpdatedEvent.php (Reverb Broadcast)
│   │   └── Models/                 # Product.php, ProductOption.php, Category.php, UnitOfMeasure.php
│   ├── PricingAndPromotions/       # Tier Pricing, Volume Breaks, Active Discounts
│   │   ├── Actions/                # ResolveDynamicPriceAction.php
│   │   └── Models/                 # PriceList.php, PriceListItem.php, Promotion.php
│   ├── Purchasing/                 # Suppliers, Purchase Orders, Replenishments
│   │   └── Models/                 # Supplier.php, Purchase.php, PurchaseItem.php
│   ├── SalesAndPos/                # Cart Checkout, Split Payments, Parked Carts
│   │   ├── Actions/                # ProcessCheckoutAction.php (ACID Concurrency Locking)
│   │   ├── Events/                 # OrderCompletedEvent.php (Reverb Broadcast)
│   │   └── Models/                 # SalesOrder.php, SalesOrderItem.php, PaymentSplit.php, HeldOrder.php
│   ├── SecurityAndAudit/           # RBAC Roles, Staff Authentication, Audit Trail
│   │   └── Models/                 # StaffUser.php (Sanctum Tokens), Role.php, AuditLog.php
│   └── Workshop/                   # Automotive Job Cards, Technicians, Parts Fitting
│       ├── Actions/                # ConvertWorkOrderToInvoiceAction.php, CalculateMechanicCommissionAction.php
│       ├── Events/                 # WorkOrderStatusChangedEvent.php (Reverb Broadcast)
│       └── Models/                 # WorkOrder.php, WorkOrderItem.php, Vehicle.php, Mechanic.php
├── Http/
│   ├── Controllers/Api/V1/         # Thin Controllers (Auth, POS, Workshop, Products, Reports)
│   ├── Requests/V1/                # Strict Form Requests (Checkout, JobCard, PinVerify, CreditSettle)
│   └── Resources/V1/               # JSON Schema Resources (OrderResource, ProductResource, etc.)
├── Jobs/                           # Queued Background Jobs (Horizon)
│   ├── GenerateReceiptPdfJob.php   # Thermal Receipt Generation (queue: receipts)
│   ├── SendDailySalesSummaryJob.php# Daily Financial Aggregations (queue: reports)
│   └── DispatchThermalPrintJob.php # Redis Pub/Sub Print Dispatcher
├── Services/
│   └── NodeWorkerBridgeService.php # HTTP / Redis Bridge to Node Microservice
└── Console/Commands/
    └── PosTestRunnerCommand.php    # AI Agent & Developer Instant Testing CLI Tool
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
| `StockUpdatedEvent` | `stock.channel` | `{ productId, newStock, variantId, timestamp }` | Triggered on inventory deduction or replenishment |
| `OrderCompletedEvent` | `pos.orders` & `pos.counter.{id}` | `{ orderNo, totalAmount, paidAmount, cashier, items }` | Triggered when cashier completes checkout |
| `WorkOrderStatusChangedEvent` | `workshop.bay` | `{ jobCardNo, vehicle, status, laborFee, partsTotal }` | Triggered when Job Card is updated or invoiced |

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

### Core Endpoints

#### Authentication & Cashier Switching
* `POST /api/v1/auth/login` — Staff login (Email & Password), returns Sanctum token
* `POST /api/v1/auth/verify-pin` — Fast cashier terminal switch via 4-digit PIN code
* `POST /api/v1/auth/logout` — Revoke active token
* `GET  /api/v1/auth/me` — Authenticated profile & permissions

#### Catalog & Inventory
* `GET  /api/v1/products` — Filter by SKU, barcode, category, and low stock status
* `GET  /api/v1/products/{id}` — Single product with option variants
* `POST /api/v1/products/{id}/barcode` — Generate Code128 / QR barcode image via Node daemon

#### Point of Sale (POS)
* `POST /api/v1/pos/checkout` — Transactional checkout with multi-split tender & stock locking
* `GET  /api/v1/pos/orders` — Historical sales orders with filters
* `GET  /api/v1/pos/orders/{id}` — Single invoice with payment splits
* `POST /api/v1/pos/orders/{id}/reprint` — Dispatch thermal receipt reprint job
* `GET  /api/v1/pos/held-orders` — List parked cart sessions
* `POST /api/v1/pos/held-orders` — Park cart temporarily on server

#### Automotive Workshop
* `GET  /api/v1/workshop/work-orders` — List job cards by status (`draft`, `in_progress`, `completed`, `invoiced`)
* `POST /api/v1/workshop/work-orders` — Create vehicle intake, diagnostics, and service items
* `GET  /api/v1/workshop/work-orders/{id}` — Work order details with fitted parts & labor
* `POST /api/v1/workshop/work-orders/{id}/convert-to-invoice` — Auto-deduct part stock, calculate mechanic commission & issue invoice

#### Customer Credit Ledger
* `GET  /api/v1/customers` — List customers with credit limit, debt, and registered fleet vehicles
* `POST /api/v1/customers/{id}/settle-credit` — Debt payment with sequential receipt ledger (`REC-`)
* `GET  /api/v1/customers/{id}/ledger` — Unpaid invoices and historical credit settlement receipts

#### Executive Reports
* `GET  /api/v1/reports/daily-summary` — Real-time daily reconciliation (POS sales, tender breakdown, workshop revenue, mechanic commissions)

---

## 🧪 Automated Testing Suite (Pest PHP 3)

The test suite validates database relational integrity, concurrency locking, WebSocket broadcasting, Horizon jobs, and REST APIs:

```bash
./vendor/bin/pest
```

### Test Suite Output
```text
   PASS  Tests\Unit\ExampleTest
  ✓ that true is true

   PASS  Tests\Feature\DatabaseRelationalIntegrityTest
  ✓ it has seeded master roles and staff with proper permissions
  ✓ it verifies strict relational linkage between customer, fleet, and vehicle
  ✓ it verifies work order items are strictly linked to catalog products
  ✓ it verifies outsourced parts are strictly linked to registered suppliers
  ✓ it verifies sales order creates multi-tender payment splits and links relationally

   PASS  Tests\Feature\DomainActionsTest
  ✓ it deducts inventory with pessimistic locking and blocks overselling
  ✓ it completes POS checkout, deducts stock, records payment splits, and credits points
  ✓ it guarantees idempotency so duplicate requests do not deduct stock twice
  ✓ it blocks sale when credit payment exceeds customer credit limit
  ✓ it converts workshop job card to invoice, automatically deducting part stock and calculating mechanic commission
  ✓ it settles customer debt and decrements due balance with sequential receipt
  ✓ it resolves dynamic wholesale and volume break pricing

   PASS  Tests\Feature\QueuedJobsTest
  ✓ it dispatches GenerateReceiptPdfJob on queue receipts when POS checkout completes
  ✓ it executes GenerateReceiptPdfJob, generates receipt artifacts and audit logs
  ✓ it handles GenerateReceiptPdfJob failure and logs critical audit alert
  ✓ it compiles daily sales summary report and logs audit entry
  ✓ it verifies NodeWorkerBridgeService fallback for barcode and ASCII receipt
  ✓ it verifies Horizon authorization gate handles admin users

   PASS  Tests\Feature\ReverbBroadcastingTest
  ✓ it broadcasts StockUpdatedEvent on stock.channel when inventory is deducted
  ✓ it broadcasts OrderCompletedEvent on pos.orders when POS checkout completes
  ✓ it broadcasts WorkOrderStatusChangedEvent on workshop.bay when work order is converted to invoice

   PASS  Tests\Feature\ApiEndpointsTest
  ✓ it authenticates staff user with valid email and password
  ✓ it rejects authentication with invalid credentials
  ✓ it authenticates cashier terminal with 4-digit PIN code
  ✓ it searches and lists catalog products with category and barcode
  ✓ it processes POS checkout via API with split tender payments and stock decrement
  ✓ it creates and manages parked cart sessions (Held Orders)
  ✓ it creates automotive Job Card and converts it to official POS invoice
  ✓ it settles customer credit debt via API and returns ledger
  ✓ it retrieves daily sales and workshop executive summary report via API

  Tests:    32 passed (140 assertions)
  Duration: 1.79s
```

---

## 🤖 AI Agent MCP Server & Instant CLI Tool

This repository includes a native **Model Context Protocol (MCP)** server under `~/.gemini/antigravity/mcp/laravel-pos/` enabling AI agents and developers to test features instantaneously:

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
* **Viber Reference:** `09778574747` (Targeting Enrich Insights Consulting Services Co., Ltd / Tech Mart MM)

---

## 📄 License
This project is proprietary benchmark software developed under the [MIT License](LICENSE).
