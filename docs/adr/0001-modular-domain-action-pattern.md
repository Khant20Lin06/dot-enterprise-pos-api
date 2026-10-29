# ADR 0001: Modular Domain-Driven Architecture & Action Pattern

## Status
**ACCEPTED** (2026-10-30)

## Context
Standard Laravel projects often place business logic directly into Controllers or Eloquent Models. In a complex enterprise system combining Automotive Workshop Management, Retail ERP, Multi-tier Pricing, and High-Concurrency POS, this quickly leads to 2000-line "God Controllers" that are fragile, difficult to test, and prone to breaking during team collaboration.

## Decision
We adopt the **Domain-Driven Modular (DDD-Lite) + Action Pattern**:
1. All core business operations are modeled as single-responsibility **Action classes** under `app/Domains/{Domain}/Actions/`.
2. Controllers are strictly transport layer handlers: validate request -> call Action -> return API Resource.
3. Eloquent models define relationships, casts, and scopes, but DO NOT handle multi-step business transactions.

## Consequences
* **Positive:** Every action (e.g. `ProcessCheckoutAction`, `DeductStockAction`) can be independently tested in Pest PHP without mocking HTTP requests.
* **Positive:** High reusability: `DeductStockAction` can be invoked by both POS Checkout and Workshop Job Card completion.
* **Negative:** Slightly more initial boilerplate classes, which is fully justified by enterprise maintainability.
