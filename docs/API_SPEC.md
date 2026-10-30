# RESTful API Specification (V1)

**Base URL:** `http://localhost/api/v1`  
**Authentication:** HTTP Bearer Token (`Authorization: Bearer <sanctum_token>`)  
**Accept Header:** `application/json`  
**Content-Type Header:** `application/json`  

---

## 1. Authentication & Session Management

### 1.1. Login (Email / Password or Operator ID)
* **Endpoint:** `POST /auth/login`
* **Request Body:**
```json
{
  "identifier": "admin@dotpos.com",
  "password": "password123"
}
```
* **Success Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "token": "1|qW89KxL...",
    "user": {
      "id": 1,
      "name": "Super Admin",
      "email": "admin@dotpos.com",
      "role": {
        "code": "ADMIN",
        "name": "Super Administrator",
        "permissions": { ... }
      }
    }
  }
}
```

### 1.2. Terminal Quick Unlock via PIN
* **Endpoint:** `POST /auth/verify-pin`
* **Request Body:**
```json
{
  "pin": "1234",
  "terminal_id": "COUNTER-01"
}
```
* **Success Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "token": "2|mK42P...",
    "user": {
      "id": 3,
      "name": "Kyaw Kyaw (Cashier)",
      "role_code": "CASHIER"
    }
  }
}
```

---

## 2. Products & Inventory API

### 2.1. List Products (With Filter & Pagination)
* **Endpoint:** `GET /products`
* **Query Params:** `cat_id`, `search`, `page`, `per_page`, `low_stock=true`
* **Response (200 OK):**
```json
{
  "success": true,
  "data": [
    {
      "id": 101,
      "name": "Total Quartz 9000 5W-30 (4L)",
      "category": { "id": 1, "name": "Engine Oils" },
      "uom": { "id": 2, "name": "Can" },
      "barcode": "8851234567890",
      "oem_part_no": "TQ-9000-5W30",
      "car_fitment": "Toyota, Honda, Mazda (Gasoline)",
      "cost_price": 45000.00,
      "sell_price": 58000.00,
      "wholesale_price": 52000.00,
      "stock": 24,
      "min_stock": 5,
      "is_labor": false
    }
  ]
}
```

### 2.2. Barcode Lookup (Ultra-Fast Dual Resolution for Scanner)
* **Endpoint:** `GET /products/barcode/{barcode}`
* **Response (Packaging UOM Match - 200 OK):**
```json
{
  "success": true,
  "type": "packaging_uom",
  "data": {
    "conversion_id": 5,
    "product_id": 101,
    "uom_id": 3,
    "uom_name": "Box",
    "conversion_factor": 12.0,
    "barcode": "BOX-OIL-101",
    "sell_price": 540000.00,
    "cost_price": 400000.00
  },
  "product": {
    "id": 101,
    "name": "Total Quartz 9000 5W-30 (4L)",
    "barcode": "8851234567890",
    "sell_price": 58000.00,
    "stock": 240
  }
}
```
* **Response (Variant Match - 200 OK):**
```json
{
  "success": true,
  "type": "variant",
  "data": {
    "id": 12,
    "productId": 101,
    "sku": "OVERALL-S-RED",
    "barcode": "OVERALL-V001",
    "price": 38000.00,
    "costPrice": 18000.00,
    "stock": 10,
    "isActive": true,
    "attributes": [
      { "attributeId": 1, "attributeCode": "SIZE", "attributeName": "Size", "valueId": 1, "value": "S" },
      { "attributeId": 2, "attributeCode": "COLOR", "attributeName": "Color", "valueId": 4, "value": "Red", "colorHex": "#EF4444" }
    ]
  },
  "product": {
    "id": 101,
    "name": "Enterprise Mechanic Overall",
    "barcode": "OVERALL-101",
    "sell_price": 35000.00,
    "stock": 40
  }
}
```
* **Response (Master Product Match - 200 OK):**
```json
{
  "success": true,
  "type": "product",
  "data": {
    "id": 101,
    "name": "Total Quartz 9000 5W-30 (4L)",
    "barcode": "8851234567890",
    "sell_price": 58000.00,
    "stock": 24
  }
}
```

### 2.3. Get Product Variants
* **Endpoint:** `GET /products/{id}/variants`
* **Response (200 OK):**
```json
{
  "success": true,
  "productId": 101,
  "hasVariants": true,
  "data": [
    {
      "id": 12,
      "productId": 101,
      "sku": "OVERALL-S-RED",
      "barcode": "OVERALL-V001",
      "price": 38000.00,
      "costPrice": 18000.00,
      "stock": 10,
      "isActive": true,
      "attributes": [
        { "attributeId": 1, "attributeCode": "SIZE", "valueId": 1, "value": "S" },
        { "attributeId": 2, "attributeCode": "COLOR", "valueId": 4, "value": "Red", "colorHex": "#EF4444" }
      ]
    }
  ]
}
```

### 2.4. Generate Cartesian Variant Matrix
* **Endpoint:** `POST /products/{id}/variants/matrix`
* **Permissions:** `inventory,create`
* **Request Body:**
```json
{
  "attribute_value_ids": [1, 2, 4, 5],
  "price": 38000.00,
  "cost_price": 18000.00,
  "initial_stock": 10
}
```
* **Response (201 Created):**
```json
{
  "success": true,
  "message": "4 variants generated successfully for product 'Enterprise Mechanic Overall'.",
  "productId": 101,
  "data": [ ... ]
}
```

### 2.5. Variant Attributes Master (Size, Color, etc.)
* **Endpoints:**
  * `GET /attributes` — List all attributes and discrete values
  * `POST /attributes` — Create attribute with values (`permission:inventory,create`)
  * `GET /attributes/{id}` — Retrieve specific attribute details
* **Request Body (`POST /attributes`):**
```json
{
  "code": "COLOR",
  "name": "Color",
  "description": "Visual color swatch attribute",
  "values": [
    { "value": "Navy Blue", "color_hex": "#000080", "sort_order": 1 },
    { "value": "Crimson Red", "color_hex": "#DC2626", "sort_order": 2 }
  ]
}
```

### 2.6. List Product Packaging UOM Conversions
* **Endpoint:** `GET /products/{id}/uom-conversions`
* **Response (200 OK):**
```json
{
  "success": true,
  "data": [
    {
      "id": 5,
      "productId": 101,
      "uomId": 3,
      "uomName": "Box",
      "conversionFactor": 10.0,
      "barcode": "BOX-OIL-101",
      "sellPrice": 480000.00,
      "costPrice": 380000.00,
      "isActive": true
    }
  ]
}
```

### 2.7. Create Packaging UOM Conversion
* **Endpoint:** `POST /products/{id}/uom-conversions`
* **Permissions:** `inventory,manage`
* **Request Body:**
```json
{
  "uom_id": 3,
  "conversion_factor": 10.0,
  "barcode": "BOX-OIL-101",
  "sell_price": 480000.00,
  "cost_price": 380000.00
}
```

### 2.8. List Product Vehicle Fitments
* **Endpoint:** `GET /products/{id}/fitments`
* **Response (200 OK):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "productId": 101,
      "make": "TOYOTA",
      "model": "HILUX REVO",
      "yearStart": 2016,
      "yearEnd": 2024,
      "engineCode": "1GD-FTV",
      "chassisCode": "GUN126",
      "notes": "Direct fit for 2.8L Diesel engine"
    }
  ]
}
```

### 2.9. Attach Vehicle Fitment
* **Endpoint:** `POST /products/{id}/fitments`
* **Permissions:** `inventory,manage`
* **Request Body:**
```json
{
  "make": "Toyota",
  "model": "Hilux Revo",
  "year_start": 2016,
  "year_end": 2024,
  "engine_code": "1GD-FTV",
  "chassis_code": "GUN126",
  "notes": "Direct fit for 2.8L Diesel engine"
}
```

### 2.10. Search Vehicle Compatible Parts
* **Endpoint 1 (By Vehicle ID):** `GET /vehicles/{id}/compatible-parts`
* **Endpoint 2 (By Query Catalog):** `GET /catalog/compatibility-search?make=Toyota&model=Hilux&year=2020`
* **Response (200 OK):**
```json
{
  "success": true,
  "data": [
    {
      "id": 101,
      "name": "Heavy Duty Oil Filter",
      "barcode": "FLT-TOY-001",
      "sell_price": 28000.00,
      "stock": 45,
      "vehicle_fitments": [
        {
          "make": "TOYOTA",
          "model": "HILUX REVO",
          "yearStart": 2016,
          "yearEnd": 2024,
          "engineCode": "1GD-FTV"
        }
      ]
    }
  ]
}
```

---

## 3. POS Terminal & Checkout API

### 3.1. Complete Sale / Checkout (Pessimistic Locked & ACID)
* **Endpoint:** `POST /pos/checkout`
* **Request Body:**
```json
{
  "customer_id": 1,
  "vehicle_id": 4,
  "items": [
    {
      "product_id": 101,
      "qty": 1,
      "unit_price": 58000.00,
      "discount_amount": 0.00
    },
    {
      "product_id": 204,
      "qty": 1,
      "unit_price": 15000.00,
      "discount_amount": 0.00
    }
  ],
  "subtotal": 73000.00,
  "discount_amount": 3000.00,
  "discount_note": "VIP Member Promotion",
  "total_amount": 70000.00,
  "payment_splits": [
    { "method": "Cash", "amount": 20000.00 },
    { "method": "KPay", "amount": 50000.00, "reference_no": "KP-9988112" }
  ],
  "points_redeemed": 100,
  "idempotency_key": "c4b3f81e-11a2-4d89-9a7f-7128e404b931"
}
```
* **Success Response (201 Created):**
```json
{
  "success": true,
  "message": "Sale completed successfully. Stock deducted and broadcasted.",
  "data": {
    "order_id": 452,
    "order_no": "INV-20261030-0042",
    "total_amount": 70000.00,
    "paid_amount": 70000.00,
    "due_amount": 0.00,
    "points_earned": 70,
    "created_at": "2026-10-30T02:00:00Z"
  }
}
```
* **Error Response (422 Unprocessable Entity - Out of Stock):**
```json
{
  "success": false,
  "error": "INSUFFICIENT_STOCK",
  "message": "Cannot complete sale. Product 'Total Quartz 9000' has only 0 units remaining."
}
```

### 3.2. Process Sales Return & Refund (ACID, Stock Restock, Points Reversal)
* **Endpoint:** `POST /sales-returns`
* **Authorization:** `Bearer <token>` (Requires `pos.create` permission)
* **Request Body:**
```json
{
  "sales_order_id": 452,
  "reason": "Customer bought wrong viscosity oil",
  "refund_method": "Cash",
  "refund_reference": "REF-VOUCHER-001",
  "notes": "Original bottle seal intact, verified sellable",
  "items": [
    {
      "sales_order_item_id": 1024,
      "qty": 1,
      "item_condition": "Sellable"
    }
  ]
}
```
* **Success Response (201 Created):**
```json
{
  "success": true,
  "message": "Sales Return #RET-20261030-0001 processed successfully.",
  "data": {
    "id": 1,
    "returnNo": "RET-20261030-0001",
    "salesOrder": {
      "id": 452,
      "orderNo": "INV-20261030-0042",
      "totalAmount": 70000.00,
      "status": "Completed"
    },
    "customer": {
      "id": 1,
      "name": "U Thant Zin",
      "phone": "09123456789",
      "currentDue": 0.00,
      "loyaltyPoints": 60
    },
    "cashier": {
      "id": 2,
      "name": "Daw Hla Hla"
    },
    "totalRefundAmount": 58000.00,
    "refundMethod": "Cash",
    "refundReference": "REF-VOUCHER-001",
    "reason": "Customer bought wrong viscosity oil",
    "status": "Restocked",
    "pointsDeducted": 58,
    "notes": "Original bottle seal intact, verified sellable",
    "items": [
      {
        "id": 1,
        "salesOrderItemId": 1024,
        "productId": 101,
        "productName": "Total Quartz 9000 5W-30 (4L)",
        "productBarcode": "8851234567890",
        "qty": 1,
        "unitPrice": 58000.00,
        "refundSubtotal": 58000.00,
        "itemCondition": "Sellable",
        "restocked": true
      }
    ],
    "createdAt": "2026-10-30T09:15:00.000000Z"
  }
}
```

### 3.3. List & Filter Sales Returns
* **Endpoint:** `GET /sales-returns?sales_order_id=452&status=Restocked`
* **Response (200 OK):**
```json
{
  "data": [
    {
      "id": 1,
      "returnNo": "RET-20261030-0001",
      "totalRefundAmount": 58000.00,
      "refundMethod": "Cash",
      "status": "Restocked",
      "items": [ ... ]
    }
  ],
  "links": { ... },
  "meta": { ... }
}
```

---

## 4. Workshop & Job Cards API

### 4.1. Create Job Card (Intake)
* **Endpoint:** `POST /workshop/jobs`
* **Request Body:**
```json
{
  "customer_id": 1,
  "vehicle_id": 4,
  "complaint": "Brake squeaking noise and 10,000km routine service",
  "mileage_in": 45200,
  "assigned_mechanic_id": 2,
  "bay": "Bay 2",
  "priority": "Normal"
}
```
* **Response (201 Created):**
```json
{
  "success": true,
  "data": {
    "id": 12,
    "job_card_no": "JC-2026-0012",
    "status": "Intake / Estimate"
  }
}
```

### 4.2. Convert Job Card to Invoice (Deduct Stock & Credit Commission)
* **Endpoint:** `POST /workshop/jobs/{id}/convert-to-invoice`
* **Request Body:**
```json
{
  "payment_method": "Cash",
  "labor_fee": 30000.00
}
```
* **Response (200 OK):**
```json
{
  "success": true,
  "message": "Work order converted to invoice. Stock deducted and mechanic commission credited.",
  "data": {
    "work_order_id": 12,
    "status": "Completed / Invoiced",
    "sales_order_id": 453,
    "invoice_no": "INV-20261030-0043",
    "mechanic_commission": {
      "mechanic_name": "Ko Zaw (Senior Mechanic)",
      "earned_amount": 4500.00
    }
  }
}
```

---

## 5. Customer Credit Ledger API

### 5.1. Settle Customer Credit
* **Endpoint:** `POST /customers/{id}/settle-credit`
* **Request Body:**
```json
{
  "amount": 50000.00,
  "payment_method": "KPay",
  "note": "Partial settlement for Invoice #INV-0038"
}
```
* **Response (200 OK):**
```json
{
  "success": true,
  "message": "Credit settled successfully.",
  "data": {
    "receipt_no": "REC-20261030-0019",
    "settled_amount": 50000.00,
    "remaining_due": 25000.00
  }
}
```

---

## 6. Roles & Permissions Management API

### 6.1. Get Permissions Canonical Catalog
* **Endpoint:** `GET /roles/permissions-catalog`
* **Response (200 OK):** Exposes modules (`pos`, `inventory`, `workshop`, `finances`, `purchases`, `settings`), human labels (EN & MM), icons, and danger flags.

### 6.2. List All Roles
* **Endpoint:** `GET /roles`
* **Response (200 OK):** List of all roles with granular permission dictionaries and `staffCount`.

### 6.3. Create Custom Role
* **Endpoint:** `POST /roles`
* **Authorization:** `Bearer <token>` (`settings.manageRoles`)
* **Request Body:**
```json
{
  "code": "WAREHOUSE_SUPERVISOR",
  "name": "Warehouse Supervisor",
  "badge_color": "#10b981",
  "description": "Oversees inventory transfers and stock adjustments",
  "permissions": {
    "inventory": { "view": true, "create": true, "edit": true, "adjustStock": true },
    "purchases": { "view": true, "create": true, "approvePO": true }
  }
}
```

### 6.4. Update Role & Invalidate Cache
* **Endpoint:** `PUT /roles/{id}`
* **Authorization:** `Bearer <token>` (`settings.manageRoles`)

### 6.5. Delete Custom Role
* **Endpoint:** `DELETE /roles/{id}`
* **Authorization:** `Bearer <token>` (`settings.manageRoles`)
* **Note:** Rejects deletion of system roles or roles with actively assigned staff.

---

## 7. Staff Users Management API

### 7.1. List Staff Users
* **Endpoint:** `GET /staff?role_id=2&status=Active`
* **Response (200 OK):** Paginated staff user records with assigned role, status, and workstation.

### 7.2. Register New Staff User
* **Endpoint:** `POST /staff`
* **Authorization:** `Bearer <token>` (`settings.manageRoles`)
* **Request Body:**
```json
{
  "name": "Ko Zaw Zaw",
  "email": "zawzaw@dotpos.com",
  "phone": "09987654321",
  "role_id": 2,
  "pin": "7788",
  "password": "SecurePassword123!",
  "workstation": "POS Counter 02",
  "status": "Active"
}
```

### 7.3. Update Staff Details / Change PIN
* **Endpoint:** `PUT /staff/{id}`
* **Authorization:** `Bearer <token>` (`settings.manageRoles`)

### 7.4. Change Staff Status & Instant Token Revocation
* **Endpoint:** `PATCH /staff/{id}/status`
* **Authorization:** `Bearer <token>` (`settings.manageRoles`)
* **Request Body:**
```json
{
  "status": "Suspended"
}
```
* **Security:** If status is set to `Suspended`, all active Sanctum tokens are immediately purged.

### 7.5. Delete Staff Account
* **Endpoint:** `DELETE /staff/{id}`
* **Authorization:** `Bearer <token>` (`settings.manageRoles`)
* **Note:** Self-deletion by the authenticated user is strictly blocked.

---

## 8. Multi-Warehouse & Branches API

### 8.1. List Warehouses
* **Endpoint:** `GET /warehouses?active=1`
* **Response (200 OK):** List of physical warehouses and branch counters with stock SKU totals.

### 8.2. Create Warehouse
* **Endpoint:** `POST /warehouses`
* **Authorization:** `Bearer <token>` (`inventory.create`)
* **Request Body:**
```json
{
  "code": "WH-MDY-01",
  "name": "Mandalay Central Distribution Hub",
  "type": "Central Warehouse",
  "address": "78th Street, Between 30th & 31st, Mandalay",
  "phone": "0945001122",
  "is_central": false,
  "is_active": true
}
```

### 8.3. Query Warehouse Physical Stocks & Low Stock Alerts
* **Endpoint:** `GET /warehouses/{id}/stocks?low_stock=1`
* **Response (200 OK):** Itemized stocks within this specific warehouse including aisle/bin location and alert threshold indicators.

---

## 9. Inter-Branch Stock Transfers API

### 9.1. Create Draft Transfer
* **Endpoint:** `POST /stock-transfers`
* **Authorization:** `Bearer <token>` (`inventory.adjustStock`)
* **Request Body:**
```json
{
  "source_warehouse_id": 1,
  "destination_warehouse_id": 2,
  "notes": "Weekly replenishment for Hledan counter",
  "items": [
    {
      "product_id": 101,
      "qty": 20
    }
  ]
}
```

### 9.2. Dispatch Transfer (Deduct Source Stock, Mark In-Transit)
* **Endpoint:** `POST /stock-transfers/{id}/dispatch`
* **Authorization:** `Bearer <token>` (`inventory.adjustStock`)
* **Effect:** Verifies physical source warehouse stock with pessimistic locking, decrements stock, transitions status to `In Transit`, and broadcasts `StockTransferDispatchedEvent`.

### 9.3. Receive Transfer (Increment Destination Stock)
* **Endpoint:** `POST /stock-transfers/{id}/receive`
* **Authorization:** `Bearer <token>` (`inventory.adjustStock`)
* **Effect:** Increments destination warehouse stock with pessimistic locking, marks status `Received`, and broadcasts `StockTransferReceivedEvent`.

### 9.4. Cancel Transfer
* **Endpoint:** `POST /stock-transfers/{id}/cancel`
* **Authorization:** `Bearer <token>` (`inventory.adjustStock`)
* **Effect:** Cancels draft, or if in-transit, automatically reverts dispatched goods back to the source warehouse.

---

## 10. Cash Register Shifts & Cash Reconciliation API

### 10.1. Open Shift Session
* **Endpoint:** `POST /pos/sessions/open`
* **Request Body:**
```json
{
  "terminal_id": "COUNTER-01",
  "opening_float": 100000.00,
  "warehouse_id": 1,
  "opening_notes": "Morning cashier shift"
}
```
* **Response (201 Created):**
```json
{
  "success": true,
  "message": "Shift #SHIFT-20261030-0001 opened successfully.",
  "data": {
    "id": 1,
    "sessionNo": "SHIFT-20261030-0001",
    "terminalId": "COUNTER-01",
    "openingFloat": 100000.00,
    "status": "open",
    "openedAt": "2026-10-30T08:00:00+07:00"
  }
}
```

### 10.2. Get Current Active Shift
* **Endpoint:** `GET /pos/sessions/current`
* **Response (200 OK):** Details of the currently open shift for the authenticated cashier/terminal.

### 10.3. Interim X-Report (Mid-Shift Audit Reading)
* **Endpoint:** `GET /pos/sessions/{id}/x-report`
* **Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "reportType": "X-Report",
    "isFinal": false,
    "sessionNo": "SHIFT-20261030-0001",
    "financialSummary": {
      "openingFloat": 100000.00,
      "ordersCount": 14,
      "grossSales": 450000.00,
      "returnsCount": 1,
      "grossRefunds": 15000.00,
      "netSales": 435000.00
    },
    "tenderBreakdown": {
      "cash": { "sales": 250000.00, "refunds": 15000.00, "net": 235000.00 },
      "kpay": 120000.00,
      "wavepay": 50000.00,
      "card": 30000.00,
      "credit": 0.00
    },
    "cashReconciliation": {
      "openingFloat": 100000.00,
      "expectedDrawerCash": 335000.00,
      "varianceStatus": "Active"
    }
  }
}
```

### 10.4. Close Shift Session (Blind Cash Count)
* **Endpoint:** `POST /pos/sessions/{id}/close`
* **Request Body:**
```json
{
  "counted_cash": 335000.00,
  "closing_notes": "Balanced shift end"
}
```
* **Response (200 OK):**
```json
{
  "success": true,
  "message": "Shift #SHIFT-20261030-0001 closed successfully. Variance: 0.00 Ks.",
  "data": {
    "id": 1,
    "status": "closed",
    "expectedCash": 335000.00,
    "countedCash": 335000.00,
    "cashVariance": 0.00
  }
}
```

### 10.5. Final Z-Report (Shift Closing Audit)
* **Endpoint:** `GET /pos/sessions/{id}/z-report`
* **Response (200 OK):** Complete shift audit report with `isFinal: true` and `varianceStatus: "Balanced" / "Short" / "Over"`.

---

## 11. Payment Gateways & Dynamic QR API

### 11.1. Generate Dynamic MMQR / Deeplink Payment Intent
* **Endpoint:** `POST /payments/generate-qr`
* **Request Body:**
```json
{
  "provider": "kbzpay",
  "amount": 58000.00,
  "order_no": "INV-20261030-0001"
}
```
* **Response (201 Created):**
```json
{
  "success": true,
  "data": {
    "transactionNo": "TXN-20261030-0001",
    "provider": "kbzpay",
    "amount": 58000.00,
    "qrPayload": "00020101021226500011com.kbzpay0115...",
    "deeplink": "kbzpay://qr?prepay_id=KBZ-123&order_id=INV-20261030-0001",
    "status": "pending",
    "expiresAt": "2026-10-30T08:15:00+07:00"
  }
}
```

### 11.2. Query Payment Status
* **Endpoint:** `GET /payments/transactions/{transactionNo}`
* **Response (200 OK):** Transaction status (`pending`, `successful`, `failed`).

### 11.3. Receive Payment Webhook Callback (Public Boundary)
* **Endpoint:** `POST /payments/webhook` (or alias `POST /payments/simulate-webhook`)
* **Authentication:** Public (No Sanctum Token required). Protected by HMAC-SHA256 signature verification.
* **Headers:**
  - `X-Signature`: Hex-encoded HMAC-SHA256 of the raw JSON request payload using `PAYMENT_GATEWAY_WEBHOOK_SECRET`.
* **Request Body:**
```json
{
  "provider": "kbzpay",
  "gateway_ref": "KBZ-123",
  "status": "successful",
  "amount": 58000.00,
  "transaction_id": "KBZ-TXN-0099"
}
```
* **Response (200 OK):** Transitions transaction to `successful` and confirms associated order payment split.
* **Error Response (403 Forbidden):** Returns `403` if `X-Signature` header is missing or does not match calculated HMAC.
