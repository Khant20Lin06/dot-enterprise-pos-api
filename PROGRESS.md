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
| **Phase 4** | Core Domain Actions (Checkout, Stock Locks, Work Orders) | 🟡 Next Up | 0% |
| **Phase 5** | Laravel Reverb WebSockets & Real-Time Sync | ⚪ Planned | 0% |
| **Phase 6** | Redis Queues & Laravel Horizon Integration | ⚪ Planned | 0% |
| **Phase 7** | Node.js Asynchronous Worker Microservice Bridge | ⚪ Planned | 0% |
| **Phase 8** | Pest PHP Automated Test Suite (Feature & Concurrency) | ⚪ Planned | 0% |
| **Phase 9** | Laravel MCP Server Integration for Instant Agent Testing | ⚪ Planned | 0% |

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
- [ ] `ProcessCheckoutAction` (ACID transaction, Pessimistic row locking via `lockForUpdate()`, payment splits)
- [ ] `DeductStockAction` & `ReplenishStockAction`
- [ ] `CreateWorkOrderAction` & `ConvertWorkOrderToInvoiceAction` (Parts auto-deducted from stock catalog)
- [ ] `CalculateMechanicCommissionAction` (Auto compute labor percentage & log commission)
- [ ] `SettleCustomerCreditAction` (Partial & Full settlement with receipt ledger)
- [ ] `ResolveDynamicPriceAction` (Customer tier + Volume break price evaluation)

---

### Phase 5: Real-Time WebSockets (Laravel Reverb)
- [ ] Setup Laravel Reverb on port 8080
- [ ] Create `StockUpdatedEvent` (Broadcast on `stock.channel`)
- [ ] Create `OrderCompletedEvent` (Broadcast to live kitchen/cashier displays)
- [ ] Create `WorkOrderStatusChangedEvent` (Broadcast to workshop bay displays)
- [ ] Configure presence channel for active cashier sessions

---

### Phase 6: Redis Queues & Laravel Horizon
- [ ] Setup Laravel Horizon dashboard (`/horizon`)
- [ ] Create `GenerateReceiptPdfJob` (Queued background PDF generation)
- [ ] Create `SendDailySalesSummaryJob`
- [ ] Implement failed job retry mechanisms and backoff strategies

---

### Phase 7: Node.js Microservice Async Worker Bridge
- [ ] Create lightweight Node.js worker service under `services/node-worker/`
- [ ] Implement high-speed Barcode/QR generation (`bwip-js`)
- [ ] Implement ESC/POS binary slip formatting
- [ ] Connect Laravel to Node.js via Redis Pub/Sub

---

### Phase 8: Pest PHP Automated Test Suite & CI/CD
- [ ] Feature Test: `PosCheckoutTest` (Normal checkout, Multi-tender split pay, Stock deduction verification)
- [ ] Feature Test: `StockConcurrencyTest` (Race condition verification: 2 cashiers, 1 item remaining)
- [ ] Feature Test: `WorkOrderToInvoiceTest` (Job card converted to invoice, stock decreased, commission recorded)
- [ ] Feature Test: `CreditSettlementTest` (Receivables updated accurately)
- [ ] Setup GitHub Actions workflow `.github/workflows/ci.yml`

---

### Phase 9: Laravel MCP Server Integration
- [ ] Create MCP tools for AI Agent direct invocation (`test_checkout`, `query_stock`, `verify_work_order`)
- [ ] Enable feature-by-feature instant verification loop via MCP calls
