> Historical draft preserved before the 2026-10-26 audit correction.
> Its schema, security, runtime and legal-compliance claims are unverified or
> contradicted by the current audit. Do not use it for implementation,
> operations, retention decisions or release evidence. Read [the audit](../AUDIT.md)
> and the current [document](../ARCHITECTURE.md) instead.

# Manage-Grocery Architecture & Request Lifecycle

This document describes the layered MVC architecture, component boundaries, and request lifecycles of Manage-Grocery.

---

## 1. System Layers Overview

Manage-Grocery employs a modular Model-View-Controller (MVC) architecture specifically engineered for plain PHP 8, segregating public web traffic from core business services and database persistence.

```mermaid
flowchart TD
    subgraph ClientLayer ["Client Layer"]
        Browser["Desktop & Mobile Web Browser"]
        Scanner["Barcode Scanner / Camera Kiosk (ZXing WASM)"]
    end

    subgraph WebServer ["Web Server Layer"]
        Apache["Apache 2.4 (XAMPP)"]
        PublicIndex["public/index.php (Front Controller)"]
    end

    subgraph CoreEngine ["Application Core"]
        Router["Core\Router (Route Dispatcher)"]
        Request["Core\Request & Core\Response"]
        Middleware["Core\Middleware (CSRF, Auth, Role, 2FA)"]
        Controllers["Domain Controllers (Pos, Product, Inventory, Hrms)"]
    end

    subgraph ServiceLayer ["Business & Domain Services"]
        PosService["Services\PosSaleService (FIFO Batch Deduct)"]
        AiService["Services\AiNutritionService (Gemini / Groq)"]
        PayrollService["Services\PayrollCalculationService (Tax/SSS)"]
        GeofenceService["Services\GeofenceService (Haversine Formula)"]
    end

    subgraph PersistenceLayer ["Persistence & Storage Layer"]
        Models["Models (Active Record Entities)"]
        DB["Core\Database Singleton (Dual PDO Pool)"]
        PosDB[("MariaDB: grocery_pos")]
        HrmsDB[("MariaDB: hrms")]
        PrivateDisk["storage/uploads/ (Deny Exec)"]
    end

    Browser & Scanner --> Apache
    Apache --> PublicIndex
    PublicIndex --> Router
    Router --> Middleware
    Middleware --> Controllers
    Controllers --> ServiceLayer
    ServiceLayer --> Models
    Models --> DB
    DB --> PosDB & HrmsDB
    Controllers --> PrivateDisk
    Controllers --> Views["Views (HTML + PHP Layouts)"]
    Views --> Browser
```

---

## 2. Point of Sale (POS) Request Lifecycle

```mermaid
sequenceDiagram
    autonumber
    actor Cashier as Cashier Register Terminal
    participant Front as public/index.php
    participant Middleware as Middleware Pipeline
    participant Controller as PosController
    participant Service as PosSaleService
    participant DB as MariaDB (grocery_pos)

    Cashier->>Front: POST /sales/checkout (Cart line items, Tender amount, Discount)
    Front->>Middleware: 1. Verify CSRF Token
    Middleware->>Middleware: 2. Verify Cashier Session & Active Shift
    Middleware->>Controller: Route to checkout() action
    Controller->>Service: processTransaction(cartItems, paymentDetails)
    
    critical Atomic Database Transaction
        Service->>DB: Check stock quantities in product_batches
        Service->>DB: Apply FIFO: Consume oldest batches first
        Service->>DB: Insert record into sales table
        Service->>DB: Insert batch allocations into sale_batch_consumption
        Service->>DB: Insert items into sales_details
        Service->>DB: Insert stock movement audit into stock_logs
    end

    DB-->>Service: Transaction Committed Successfully
    Service-->>Controller: Return Sale Confirmation & Receipt Payload
    Controller->>Cashier: Render Receipt View / Stream PDF via FPDF
```

---

## 3. Subsystem Breakdown

### 3.1 Application Core (`app/Core/`)
- `Database.php`: Singleton PDO connection factory maintaining isolated connection pools for `grocery_pos` and `hrms` with UTF-8 character encoding and exception error modes.
- `Router.php`: Lightweight, high-performance regex router mapping clean URLs (`GET /sales`, `POST /inventory/adjust`) to controller actions.
- `Request.php` & `Response.php`: Clean HTTP abstractions providing sanitized input access, header management, and response formatting (JSON, HTML, file streaming).

### 3.2 Service Layer (`app/Services/`)
- `PosSaleService`: Encapsulates FIFO batch allocation logic, tax computations (12% VAT), and senior citizen / PWD discounts.
- `AiNutritionService`: Interacts with Gemini and Groq vision models to parse nutritional facts from uploaded package photos.
- `PayrollCalculationService`: Calculates Philippine statutory contributions including Social Security System (SSS), PhilHealth, Home Development Mutual Fund (Pag-IBIG), and withholding tax.
- `GeofenceService`: Computes Haversine great-circle distance between employee GPS coordinates and PCU Dasmariñas store coordinates.

