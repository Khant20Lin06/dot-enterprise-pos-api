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

### 2.2. Barcode Lookup (Ultra-Fast for POS Scanner)
* **Endpoint:** `GET /products/barcode/{barcode}`
* **Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "id": 101,
    "name": "Total Quartz 9000 5W-30 (4L)",
    "barcode": "8851234567890",
    "sell_price": 58000.00,
    "stock": 24
  }
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
