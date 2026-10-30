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

### 2.11. Create Product
* **Endpoint:** `POST /products`
* **Permissions:** `inventory,create`
* **Request Body:**
```json
{
  "category_id": 1,
  "uom_id": 2,
  "supplier_id": 1,
  "name": "Mobil 1 Super Synthetic 0W-20",
  "barcode": "M1-0W20-AUTO",
  "cost_price": 45000.00,
  "sell_price": 60000.00,
  "wholesale_price": 55000.00,
  "initial_stock": 50,
  "warehouse_id": 1,
  "min_stock": 10
}
```

### 2.12. Update Product
* **Endpoint:** `PUT /products/{id}`
* **Permissions:** `inventory,edit`
* **Request Body:**
```json
{
  "sell_price": 62000.00,
  "min_stock": 12
}
```

### 2.13. Delete Product
* **Endpoint:** `DELETE /products/{id}`
* **Permissions:** `inventory,edit`
* **Guards:** Blocked if product has historical sales order items or workshop job cards.

### 2.14. Categories Master CRUD
* **Endpoints:**
  * `GET /categories`
  * `POST /categories` (Payload: `code`, `name`, `description`)
  * `GET /categories/{id}`
  * `PUT /categories/{id}`
  * `DELETE /categories/{id}` (Guarded against in-use products)

### 2.15. Units of Measure Master CRUD
* **Endpoints:**
  * `GET /units-of-measure`
  * `POST /units-of-measure` (Payload: `name`, `description`)
  * `GET /units-of-measure/{id}`
  * `PUT /units-of-measure/{id}`
  * `DELETE /units-of-measure/{id}` (Guarded against in-use products)

### 2.16. Suppliers Master CRUD
* **Endpoints:**
  * `GET /suppliers`
  * `POST /suppliers` (Payload: `name`, `phone`, `contact_person`, `address`)
  * `GET /suppliers/{id}`
  * `PUT /suppliers/{id}`
  * `DELETE /suppliers/{id}` (Guarded against purchase history)

### 2.17. Purchase Orders & Goods Receiving (GRN)
* **Create PO:** `POST /purchases`
```json
{
  "supplier_id": 1,
  "warehouse_id": 1,
  "notes": "Bulk restocking",
  "receive_immediately": false,
  "items": [
    { "product_id": 101, "qty": 20, "unit_price": 50000.00 }
  ]
}
```
* **Receive PO (GRN):** `POST /purchases/{id}/receive`
* Recalculates Weighted Moving Average Cost, updates physical warehouse stock and master stock, and credits supplier payable debt.
* **Cancel PO:** `POST /purchases/{id}/cancel` (Guarded: only Draft POs can be cancelled).

### 2.18. Physical Stock Adjustments & Audits
* **Endpoint:** `POST /stock-adjustments`
* **Permissions:** `inventory,adjustStock`
* **Request Body:**
```json
{
  "product_id": 101,
  "warehouse_id": 1,
  "type": "Damage / Scrap",
  "adjusted_qty": -3,
  "reason": "Container leaked during transit"
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

### 2.11. Create Product
* **Endpoint:** `POST /products`
* **Permissions:** `inventory,create`
* **Request Body:**
```json
{
  "category_id": 1,
  "uom_id": 2,
  "supplier_id": 1,
  "name": "Mobil 1 Super Synthetic 0W-20",
  "barcode": "M1-0W20-AUTO",
  "cost_price": 45000.00,
  "sell_price": 60000.00,
  "wholesale_price": 55000.00,
  "initial_stock": 50,
  "warehouse_id": 1,
  "min_stock": 10
}
```

### 2.12. Update Product
* **Endpoint:** `PUT /products/{id}`
* **Permissions:** `inventory,edit`
* **Request Body:**
```json
{
  "sell_price": 62000.00,
  "min_stock": 12
}
```

### 2.13. Delete Product
* **Endpoint:** `DELETE /products/{id}`
* **Permissions:** `inventory,edit`
* **Guards:** Blocked if product has historical sales order items or workshop job cards.

### 2.14. Categories Master CRUD
* **Endpoints:**
  * `GET /categories`
  * `POST /categories` (Payload: `code`, `name`, `description`)
  * `GET /categories/{id}`
  * `PUT /categories/{id}`
  * `DELETE /categories/{id}` (Guarded against in-use products)

### 2.15. Units of Measure Master CRUD
* **Endpoints:**
  * `GET /units-of-measure`
  * `POST /units-of-measure` (Payload: `name`, `description`)
  * `GET /units-of-measure/{id}`
  * `PUT /units-of-measure/{id}`
  * `DELETE /units-of-measure/{id}` (Guarded against in-use products)

### 2.16. Suppliers Master CRUD
* **Endpoints:**
  * `GET /suppliers`
  * `POST /suppliers` (Payload: `name`, `phone`, `contact_person`, `address`)
  * `GET /suppliers/{id}`
  * `PUT /suppliers/{id}`
  * `DELETE /suppliers/{id}` (Guarded against purchase history)

### 2.17. Purchase Orders & Goods Receiving (GRN)
* **Create PO:** `POST /purchases`
```json
{
  "supplier_id": 1,
  "warehouse_id": 1,
  "notes": "Bulk restocking",
  "receive_immediately": false,
  "items": [
    { "product_id": 101, "qty": 20, "unit_price": 50000.00 }
  ]
}
```
* **Receive PO (GRN):** `POST /purchases/{id}/receive`
* Recalculates Weighted Moving Average Cost, updates physical warehouse stock and master stock, and credits supplier payable debt.
* **Cancel PO:** `POST /purchases/{id}/cancel` (Guarded: only Draft POs can be cancelled).

### 2.18. Physical Stock Adjustments & Audits
* **Endpoint:** `POST /stock-adjustments`
* **Permissions:** `inventory,adjustStock`
* **Request Body:**
```json
{
  "product_id": 101,
  "warehouse_id": 1,
  "type": "Damage / Scrap",
  "adjusted_qty": -3,
  "reason": "Container leaked during transit"
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
  "idempotency_key": "c4b3f81e-11a2-4d89-9a7f-7128e404b931",
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
* **Success Response (201 Created on new return, 200 OK on duplicate idempotent replay):**
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

### 2.11. Create Product
* **Endpoint:** `POST /products`
* **Permissions:** `inventory,create`
* **Request Body:**
```json
{
  "category_id": 1,
  "uom_id": 2,
  "supplier_id": 1,
  "name": "Mobil 1 Super Synthetic 0W-20",
  "barcode": "M1-0W20-AUTO",
  "cost_price": 45000.00,
  "sell_price": 60000.00,
  "wholesale_price": 55000.00,
  "initial_stock": 50,
  "warehouse_id": 1,
  "min_stock": 10
}
```

### 2.12. Update Product
* **Endpoint:** `PUT /products/{id}`
* **Permissions:** `inventory,edit`
* **Request Body:**
```json
{
  "sell_price": 62000.00,
  "min_stock": 12
}
```

### 2.13. Delete Product
* **Endpoint:** `DELETE /products/{id}`
* **Permissions:** `inventory,edit`
* **Guards:** Blocked if product has historical sales order items or workshop job cards.

### 2.14. Categories Master CRUD
* **Endpoints:**
  * `GET /categories`
  * `POST /categories` (Payload: `code`, `name`, `description`)
  * `GET /categories/{id}`
  * `PUT /categories/{id}`
  * `DELETE /categories/{id}` (Guarded against in-use products)

### 2.15. Units of Measure Master CRUD
* **Endpoints:**
  * `GET /units-of-measure`
  * `POST /units-of-measure` (Payload: `name`, `description`)
  * `GET /units-of-measure/{id}`
  * `PUT /units-of-measure/{id}`
  * `DELETE /units-of-measure/{id}` (Guarded against in-use products)

### 2.16. Suppliers Master CRUD
* **Endpoints:**
  * `GET /suppliers`
  * `POST /suppliers` (Payload: `name`, `phone`, `contact_person`, `address`)
  * `GET /suppliers/{id}`
  * `PUT /suppliers/{id}`
  * `DELETE /suppliers/{id}` (Guarded against purchase history)

### 2.17. Purchase Orders & Goods Receiving (GRN)
* **Create PO:** `POST /purchases`
```json
{
  "supplier_id": 1,
  "warehouse_id": 1,
  "notes": "Bulk restocking",
  "receive_immediately": false,
  "items": [
    { "product_id": 101, "qty": 20, "unit_price": 50000.00 }
  ]
}
```
* **Receive PO (GRN):** `POST /purchases/{id}/receive`
* Recalculates Weighted Moving Average Cost, updates physical warehouse stock and master stock, and credits supplier payable debt.
* **Cancel PO:** `POST /purchases/{id}/cancel` (Guarded: only Draft POs can be cancelled).

### 2.18. Physical Stock Adjustments & Audits
* **Endpoint:** `POST /stock-adjustments`
* **Permissions:** `inventory,adjustStock`
* **Request Body:**
```json
{
  "product_id": 101,
  "warehouse_id": 1,
  "type": "Damage / Scrap",
  "adjusted_qty": -3,
  "reason": "Container leaked during transit"
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

### 2.11. Create Product
* **Endpoint:** `POST /products`
* **Permissions:** `inventory,create`
* **Request Body:**
```json
{
  "category_id": 1,
  "uom_id": 2,
  "supplier_id": 1,
  "name": "Mobil 1 Super Synthetic 0W-20",
  "barcode": "M1-0W20-AUTO",
  "cost_price": 45000.00,
  "sell_price": 60000.00,
  "wholesale_price": 55000.00,
  "initial_stock": 50,
  "warehouse_id": 1,
  "min_stock": 10
}
```

### 2.12. Update Product
* **Endpoint:** `PUT /products/{id}`
* **Permissions:** `inventory,edit`
* **Request Body:**
```json
{
  "sell_price": 62000.00,
  "min_stock": 12
}
```

### 2.13. Delete Product
* **Endpoint:** `DELETE /products/{id}`
* **Permissions:** `inventory,edit`
* **Guards:** Blocked if product has historical sales order items or workshop job cards.

### 2.14. Categories Master CRUD
* **Endpoints:**
  * `GET /categories`
  * `POST /categories` (Payload: `code`, `name`, `description`)
  * `GET /categories/{id}`
  * `PUT /categories/{id}`
  * `DELETE /categories/{id}` (Guarded against in-use products)

### 2.15. Units of Measure Master CRUD
* **Endpoints:**
  * `GET /units-of-measure`
  * `POST /units-of-measure` (Payload: `name`, `description`)
  * `GET /units-of-measure/{id}`
  * `PUT /units-of-measure/{id}`
  * `DELETE /units-of-measure/{id}` (Guarded against in-use products)

### 2.16. Suppliers Master CRUD
* **Endpoints:**
  * `GET /suppliers`
  * `POST /suppliers` (Payload: `name`, `phone`, `contact_person`, `address`)
  * `GET /suppliers/{id}`
  * `PUT /suppliers/{id}`
  * `DELETE /suppliers/{id}` (Guarded against purchase history)

### 2.17. Purchase Orders & Goods Receiving (GRN)
* **Create PO:** `POST /purchases`
```json
{
  "supplier_id": 1,
  "warehouse_id": 1,
  "notes": "Bulk restocking",
  "receive_immediately": false,
  "items": [
    { "product_id": 101, "qty": 20, "unit_price": 50000.00 }
  ]
}
```
* **Receive PO (GRN):** `POST /purchases/{id}/receive`
* Recalculates Weighted Moving Average Cost, updates physical warehouse stock and master stock, and credits supplier payable debt.
* **Cancel PO:** `POST /purchases/{id}/cancel` (Guarded: only Draft POs can be cancelled).

### 2.18. Physical Stock Adjustments & Audits
* **Endpoint:** `POST /stock-adjustments`
* **Permissions:** `inventory,adjustStock`
* **Request Body:**
```json
{
  "product_id": 101,
  "warehouse_id": 1,
  "type": "Damage / Scrap",
  "adjusted_qty": -3,
  "reason": "Container leaked during transit"
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

### 2.11. Create Product
* **Endpoint:** `POST /products`
* **Permissions:** `inventory,create`
* **Request Body:**
```json
{
  "category_id": 1,
  "uom_id": 2,
  "supplier_id": 1,
  "name": "Mobil 1 Super Synthetic 0W-20",
  "barcode": "M1-0W20-AUTO",
  "cost_price": 45000.00,
  "sell_price": 60000.00,
  "wholesale_price": 55000.00,
  "initial_stock": 50,
  "warehouse_id": 1,
  "min_stock": 10
}
```

### 2.12. Update Product
* **Endpoint:** `PUT /products/{id}`
* **Permissions:** `inventory,edit`
* **Request Body:**
```json
{
  "sell_price": 62000.00,
  "min_stock": 12
}
```

### 2.13. Delete Product
* **Endpoint:** `DELETE /products/{id}`
* **Permissions:** `inventory,edit`
* **Guards:** Blocked if product has historical sales order items or workshop job cards.

### 2.14. Categories Master CRUD
* **Endpoints:**
  * `GET /categories`
  * `POST /categories` (Payload: `code`, `name`, `description`)
  * `GET /categories/{id}`
  * `PUT /categories/{id}`
  * `DELETE /categories/{id}` (Guarded against in-use products)

### 2.15. Units of Measure Master CRUD
* **Endpoints:**
  * `GET /units-of-measure`
  * `POST /units-of-measure` (Payload: `name`, `description`)
  * `GET /units-of-measure/{id}`
  * `PUT /units-of-measure/{id}`
  * `DELETE /units-of-measure/{id}` (Guarded against in-use products)

### 2.16. Suppliers Master CRUD
* **Endpoints:**
  * `GET /suppliers`
  * `POST /suppliers` (Payload: `name`, `phone`, `contact_person`, `address`)
  * `GET /suppliers/{id}`
  * `PUT /suppliers/{id}`
  * `DELETE /suppliers/{id}` (Guarded against purchase history)

### 2.17. Purchase Orders & Goods Receiving (GRN)
* **Create PO:** `POST /purchases`
```json
{
  "supplier_id": 1,
  "warehouse_id": 1,
  "notes": "Bulk restocking",
  "receive_immediately": false,
  "items": [
    { "product_id": 101, "qty": 20, "unit_price": 50000.00 }
  ]
}
```
* **Receive PO (GRN):** `POST /purchases/{id}/receive`
* Recalculates Weighted Moving Average Cost, updates physical warehouse stock and master stock, and credits supplier payable debt.
* **Cancel PO:** `POST /purchases/{id}/cancel` (Guarded: only Draft POs can be cancelled).

### 2.18. Physical Stock Adjustments & Audits
* **Endpoint:** `POST /stock-adjustments`
* **Permissions:** `inventory,adjustStock`
* **Request Body:**
```json
{
  "product_id": 101,
  "warehouse_id": 1,
  "type": "Damage / Scrap",
  "adjusted_qty": -3,
  "reason": "Container leaked during transit"
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

---

## 12. Enterprise Extensions & Activated Modules

### 12.1. Fleet Accounts Management
* **List Fleets:** `GET /fleets`
* **Create Fleet:** `POST /fleets`
* **Get Fleet:** `GET /fleets/{id}`
* **Update Fleet:** `PUT /fleets/{id}`
* **Delete Fleet:** `DELETE /fleets/{id}` (Protected by relational checks; rejects deletion if vehicles or customers are linked).

### 12.2. Workshop Mechanics & Commission Payouts
* **List Mechanics:** `GET /workshop/mechanics`
* **Create Mechanic:** `POST /workshop/mechanics`
* **Get Mechanic:** `GET /workshop/mechanics/{id}`
* **Update Mechanic:** `PUT /workshop/mechanics/{id}`
* **Delete Mechanic:** `DELETE /workshop/mechanics/{id}`
* **List Commissions:** `GET /workshop/mechanics/{id}/commissions` (Supports `status=Pending` filter).
* **Disburse Commission Payout:** `POST /workshop/mechanics/{id}/commissions/payout`
  * Request Body: `{"commission_ids": [1, 2, 3], "notes": "End of month disbursement"}`
  * Atomically marks pending commission records as `Paid`.

### 12.3. Workshop Subcontracted Outsourced Parts
* **List Outsourced Parts:** `GET /workshop/outsourced-parts`
* **Create Outsourced Part:** `POST /workshop/outsourced-parts`
* **Get Outsourced Part:** `GET /workshop/outsourced-parts/{id}`
* **Update Outsourced Part:** `PUT /workshop/outsourced-parts/{id}`
* **Delete Outsourced Part:** `DELETE /workshop/outsourced-parts/{id}`

### 12.4. Inbound Procurement Returns (RTV - Return To Vendor)
* **List Returns:** `GET /purchases/returns`
* **Create Return:** `POST /purchases/returns`
  * Request Body: `{"purchase_id": 1, "product_id": 5, "qty": 3, "warehouse_id": 1, "reason": "Defective parts"}`
  * Atomically decrements catalog and warehouse stock, credits supplier outstanding debt balance, and generates sequential `PRTN` return voucher.
* **Get Return:** `GET /purchases/returns/{id}`

### 12.5. Wholesale Price Lists & Promotional Campaigns
* **Wholesale Price Lists:** `GET|POST /pricing/price-lists`, `GET|PUT|DELETE /pricing/price-lists/{id}`
  * Supports fixed and percentage rules by product with minimum volume breaks.
* **Promotions & Campaigns:** `GET|POST /pricing/promotions`, `GET|PUT|DELETE /pricing/promotions/{id}`
  * Supports date-bounded discount campaigns (fixed/percent) with customer tier filtering and usage limits.

---

## 13. Operational Audits & Missing Enterprise Features (Stage 3)

### 13.1. Security Audit Logs API
* **Permissions:** `settings,manageRoles`
* **List Audit Logs:** `GET /audit-logs`
  * Query Filters: `module`, `action`, `severity`, `staff_id`, `resource_type`, `date_from`, `date_to`, `search`, `per_page` (default 20, max 100).
* **Get Audit Log Detail:** `GET /audit-logs/{id}`
  * Response contains: `id`, `staff`, `module`, `action`, `severity`, `resourceType`, `resourceId`, `description`, `diffs`, `ipAddress`, `createdAt`.

### 13.2. Global Accounts Receivable & Credit Settlements Journal
* **Permissions:** `finances,view`
* **List Credit Settlements:** `GET /credit-settlements`
  * Query Filters: `customer_id`, `cashier_id`, `pos_session_id`, `payment_method`, `date_from`, `date_to`, `search` (receipt_no or customer name/phone), `per_page`.
* **Get Settlement Receipt Detail:** `GET /credit-settlements/{id}`
  * Response contains: `id`, `receiptNo`, `customer` (`id`, `name`, `phone`, `dueAmount`), `amount`, `tenderedAmount`, `changeAmount`, `paymentMethod`, `note`, `cashier`, `posSessionId`, `createdAt`.

### 13.3. Draft Purchase Order Updating
* **Endpoint:** `PUT /purchases/{id}`
* **Permissions:** `purchases,edit`
* **Lifecycle Guard:** Modifiable **ONLY** if PO status is `Draft` or `Pending`. Rejects with `422 Unprocessable Entity` if `Received`.
* **Request Body:**
```json
{
  "supplier_id": 2,
  "notes": "Updated procurement specifications",
  "items": [
    {
      "product_id": 1,
      "qty": 10,
      "unit_price": 25000.00
    }
  ]
}
```
* **Behavior:** Locks PO row with `lockForUpdate()`, removes old line items, inserts updated items, and recalculates total amount atomically within `DB::transaction()`.

### 13.4. Product Variant Individual Update & Deletion
* **Permissions:** `inventory,edit`
* **Update Variant:** `PUT /products/{id}/variants/{variantId}`
  * Request Body: `{"sku": "VAR-001", "barcode": "BAR-001", "price": 45000, "cost_price": 30000, "stock": 10, "is_active": true}`
* **Delete Variant:** `DELETE /products/{id}/variants/{variantId}`
  * **Physical Stock Safeguard:** Rejects deletion with `422 Unprocessable Entity` if variant has remaining physical stock (`stock > 0`). Automatically marks parent `product.has_variants = false` if the last remaining variant is deleted.

---

## 14. OWASP API Security Architecture & Standards

### 14.1. Security Response Headers (EnforceSecurityHeaders Middleware)
Every response emitted by the API includes hardened OWASP ASVS v4.0 response headers:
* `X-Content-Type-Options: nosniff` — Prevents MIME confusion/sniffing attacks.
* `X-Frame-Options: DENY` — Eliminates Clickjacking risk in iframes and embedded views.
* `X-XSS-Protection: 0` — Modern standard disabling buggy legacy browser XSS filters.
* `Referrer-Policy: strict-origin-when-cross-origin` — Restricts cross-origin referrer leakage.
* `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox` — Disallows execution of remote scripts or frame embedding.
* `Permissions-Policy: accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), usb=()` — Revokes unneeded browser hardware access.
* `Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private` — Prevents sensitive PII and financial transaction data from caching in intermediate proxies.
* `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload` — Enforces HTTPS in production.

### 14.2. Stored XSS & Script Injection Neutralization (SanitizeInput Middleware)
* All incoming string parameters (except encrypted credentials like `password` and `pin`) are recursively sanitized before reaching controllers or database transactions.
* Dangerous tags (`<script>`, `<iframe>`, `javascript:`, inline event handlers like `onload=`, `onerror=`) are stripped while preserving genuine multi-language strings and Myanmar Unicode text.

### 14.3. Progressive Brute-Force Authentication Lockout (OWASP API2)
* **Login Endpoints:** `POST /auth/login` and `POST /auth/verify-pin`.
* **Lockout Rules:**
  * 1 to 4 failed attempts: Return `401 Unauthorized` with failure audit logging.
  * 5th consecutive failure: Returns `429 Too Many Requests` and locks the account / terminal IP for 15 minutes (`retry_after: 900`).
  * Triggers a `critical` severity `AuditLog` entry for immediate red-team security monitoring.
* **Error Response (429):**
```json
{
  "success": false,
  "message": "Account temporarily locked due to 5 consecutive failed login attempts. Please try again after 15 minutes.",
  "error_code": "ACCOUNT_LOCKED",
  "retry_after": 900
}
```

### 14.4. Standardized Sanitized Error Responses (CWE-209 Defense)
All unhandled exceptions and HTTP error conditions return standardized JSON payloads:
* **401 Unauthorized:** `{"success": false, "message": "Unauthenticated or session expired.", "error_code": "AUTH_REQUIRED"}`
* **403 Forbidden:** `{"success": false, "message": "This action is unauthorized. Insufficient permissions.", "error_code": "FORBIDDEN"}`
* **404 Not Found:** `{"success": false, "message": "Requested API route or resource not found.", "error_code": "NOT_FOUND"}`
* **405 Method Not Allowed:** `{"success": false, "message": "HTTP method not allowed for this endpoint.", "error_code": "METHOD_NOT_ALLOWED"}`
* **429 Rate Limit Exceeded:** `{"success": false, "message": "Too many requests. Rate limit exceeded.", "error_code": "RATE_LIMIT_EXCEEDED"}`
* **500 Internal Error:** Suppresses database connection credentials, SQL query strings, and file paths.

---

## 15. Stage 4: Anti-Destruction & Accounting Safeguards

### 15.1. Master Record Relational Delete Guards (422 Unprocessable Entity)
To eliminate catastrophic unhandled 500 SQL Integrity Constraint errors and preserve irreversible audit trails, all master record deletions undergo strict pre-flight validation:

| Resource | Protected Endpoints | Blocking Criteria |
| :--- | :--- | :--- |
| **Mechanics** | `DELETE /workshop/mechanics/{id}` | Historical commission records exist (`mechanic_commissions`). Rejects with 422 to preserve permanent payroll auditability. |
| **Products** | `DELETE /products/{id}` | Referenced in `sales_order_items`, `work_order_items`, `purchase_items`, `purchase_returns`, `stock_adjustments`, `stock_transfer_items`, OR has positive physical stock (`stock > 0`). |
| **Suppliers** | `DELETE /suppliers/{id}` | Referenced in `purchases`, `outsourced_parts`, `purchase_returns`, OR has outstanding AP debt (`outstanding_balance > 0`). |
| **Customers** | `DELETE /customers/{id}` | Referenced in `sales_orders`, `vehicles`, `credit_settlements`, OR `sales_returns`. |
| **Outsourced Parts** | `DELETE /workshop/outsourced-parts/{id}` | Parent Work Order status is `Completed / Invoiced` or `QC & Ready`, OR part status is `Received & Fitted`. |

### 15.2. Warehouse-Level Negative Stock Invariant
* **Endpoint:** `POST /stock-adjustments`
* **Invariant:** In multi-warehouse operations, a product's stock within the specified warehouse cannot become negative (`WarehouseStock.stock_qty + adjusted_qty >= 0`).
* **Behavior:** Row lock (`lockForUpdate()`) is acquired on the target `warehouse_stocks` record. If the resulting balance would drop below 0, a `422 Unprocessable Entity` is returned with an explicit error message, preventing branch-level phantom inventory.

### 15.3. Sales Return Idempotency & Duplicate Replay Protection
* **Endpoint:** `POST /sales-returns`
* **Mechanism:** Accepts client-generated `idempotency_key` (UUID/string).
* **Behavior:** If a request with an existing `idempotency_key` is submitted:
  * Restocking, customer points deduction, and credit adjustment are **BYPASSED**.
  * The existing `SalesReturn` record is returned immediately with HTTP `200 OK` (compared to `201 Created` for fresh returns).
  * Eliminates duplicate cash refund payouts and duplicate inventory restocking caused by network retransmissions or cashier double-clicks.
