# AGENTS.md — AI Agent Operating System & Project Rules

Welcome to **DOT Enterprise POS & Workshop API** (Backend Laravel 11 System).
This file provides mandatory guidelines, system constraints, architectural rules, and operational workflows for any AI Agent (Antigravity, Gemini, Claude, etc.) or human software engineer working on this repository.

---

## 1. Project Identity & Target Stack

* **Project Name:** DOT Enterprise ERP, Workshop & Real-Time POS Backend API
* **Target Company / Context:** Enrich Insights Consulting Services Co., Ltd / Tech Mart MM (Backend Laravel Developer Benchmark)
* **Core Framework:** **Laravel 11.x** (PHP 8.3+)
* **Database:** **MySQL 8.0+** (Strict Foreign Keys, ACID Transactions, Composite Indexing)
* **Caching & Queues:** **Redis 7.x** + **Laravel Horizon**
* **Real-Time WebSockets:** **Laravel Reverb** (First-party WebSocket server on port 8080)
* **Asynchronous Microservices:** **Node.js** (Express/Worker daemon for Thermal Receipt byte rendering & bulk barcode generation)
* **Testing:** **Pest PHP v3** (Strict Feature & Unit testing)
* **API Documentation:** **Dedoc Scramble** (Auto-generated OpenAPI v3 spec)
* **DevOps:** **Docker Compose** + **GitHub Actions CI/CD** + **GCP Cloud Storage**

---

## 2. Fundamental Architectural Rules (Non-Negotiable)

### Rule 2.1: No Fat Controllers (Thin Controller, Rich Domain)
* Controllers in `app/Http/Controllers/Api/V1/` MUST NOT contain raw business calculations, direct database queries, or external API calls.
* Controller responsibility is strictly:
  1. Receive validated `FormRequest`.
  2. Pass payload to the designated **Domain Action** (e.g. `ProcessCheckoutAction::execute()`).
  3. Return standard JSON via `JsonResource` (e.g. `OrderResource`).

### Rule 2.2: Concurrency & Database Transactions
* ANY operation that deducts inventory (`products.stock`), updates credit balances (`customers.due`), or processes payments MUST be wrapped inside `DB::transaction()`.
* Stock deductions MUST use **Pessimistic Row Locking (`lockForUpdate()`)** to eliminate race conditions and double-selling across multiple POS cashiers.

### Rule 2.3: Relational Integrity (Foreign Keys over Strings)
* Every entity MUST be strictly linked via integer/UUID Foreign Keys (e.g. `work_order_items.product_id` -> `products.id`, `work_orders.vehicle_id` -> `vehicles.id`).
* Never store plain text references for relational data.

### Rule 2.4: Real-Time Event Broadcasting
* Multi-counter updates (e.g. `StockUpdatedEvent`, `OrderCompletedEvent`, `JobCardStatusChangedEvent`) MUST implement `ShouldBroadcast` using **Laravel Reverb**.

---

## 3. The "Living Documentation" Protocol (Definition of Done)

Whenever you add, modify, or refactor any feature in this repository, you **MUST** follow this 4-step sequence before declaring the task complete:

1. **Write Code:** Implement Domain Models, Migrations, Actions, Requests, Resources, and Controllers.
2. **Write & Pass Tests:** Create Pest feature tests under `tests/Feature/` ensuring all assertions pass.
3. **Synchronize Documentation (MANDATORY):**
   * Update `PROGRESS.md`: Mark completed tasks with `[x]`, record timestamp and test results.
   * Update `CHANGELOG.md`: Record additions or modifications under `[Unreleased]` with clear summaries.
   * Update `docs/API_SPEC.md`: Ensure request/response schemas reflect the latest reality.
4. **Clean Code & Lint:** Run Laravel Pint (`vendor/bin/pint`) to maintain PSR-12 formatting.

---

## 4. Key Directories Map

```text
app/
├── Domains/               # Core business domains (Workshop, Inventory, SalesAndPos, etc.)
│   └── {Domain}/
│       ├── Actions/       # Single-responsibility business use-cases
│       ├── Models/        # Eloquent models with strict relations & casts
│       └── Events/        # Broadcastable Reverb events
├── Http/
│   ├── Controllers/Api/V1/ # RESTful API endpoints
│   ├── Requests/V1/       # Form validations & authorization rules
│   └── Resources/V1/      # Consistent JSON response schemas
tests/
├── Feature/               # API endpoint & transaction integration tests
└── Unit/                  # Pure domain logic tests
docs/                      # Comprehensive technical specifications & ADRs
```

---

## 5. Standard Commands Cheat Sheet

```bash
# Start Docker Environment
docker-compose up -d

# Run Migrations & Seeders
docker-compose exec app php artisan migrate:fresh --seed

# Run Automated Test Suite (Pest)
docker-compose exec app ./vendor/bin/pest

# Start Real-Time Reverb WebSocket Server
docker-compose exec app php artisan reverb:start

# Start Queue Workers via Laravel Horizon
docker-compose exec app php artisan horizon

# Run Code Style Fixer (Laravel Pint)
docker-compose exec app ./vendor/bin/pint
```
