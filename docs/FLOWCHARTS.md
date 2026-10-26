# Native Laravel workflows

Updated 2026-10-26. These diagrams describe implemented native behavior;
remaining legacy module flows are in project/HCI_SYSTEM.md.

## Staff authentication

```mermaid
flowchart TD
    A[Username and password] --> B[Select canonical POS or HRMS account]
    B --> C{Password, role and status eligible?}
    C -->|No| D[Generic failure and throttle]
    C -->|Yes| E[Regenerate pending session]
    E --> F[Email OTP or hashed recovery code]
    F --> G{Valid and current?}
    G -->|No| H[Persist attempt counter and temporary lock]
    G -->|Yes| I[Consume recovery code or enroll verified email]
    I --> J[Commit account changes and minimal audit]
    J --> K[Authenticate explicit guard and regenerate session]
    K --> L[Role, status, password, idle and revocation checks per staff request]
```

## Cashier checkout

```mermaid
flowchart TD
    A[Positive item IDs and quantities, payment and UUID] --> B[Validate request and cashier]
    B --> C[Begin POS transaction and lock cashier]
    C --> D{Durable request already exists?}
    D -->|Same payload| E[Return original receipt]
    D -->|Changed payload| F[409 conflict]
    D -->|No| G[Lock products in ID order and non-expired FEFO batches]
    G --> H[Recompute prices and approved discounts on server]
    H --> I{Stock and payment valid?}
    I -->|No| J[Rollback all writes]
    I -->|Yes| K[Sale, items, batch allocation, stock and immutable logs]
    K --> L[Link UUID, minimal audit and commit]
    L --> M[Server receipt and printable view]
```

## Returns and voids

```mermaid
flowchart TD
    A[Authenticated cashier] --> B{Action}
    B -->|Refund| C[Active HR supervisor approval and remaining quantity check]
    B -->|Void| D[Own completed transaction from today, no previous refund]
    C --> E[Lock sale, products and original allocation]
    D --> E
    E --> F[Restore original product and batch quantities]
    F --> G[Append immutable stock movement and audit]
    G --> H[Refund rows and status, or void status]
    H --> I[Commit atomically; reject repeated or excessive return]
```

POS and HRMS connections are separate transaction domains. Supervisor lookup
and identity mapping do not make writes across both databases atomic. No
native payroll/procurement/DSR flow is asserted by these diagrams.
## Manual basket discount approval

```mermaid
flowchart TD
    A[Cashier sends current item IDs and quantities] --> B[Lock cashier and products; compute server quote]
    B --> C[Create one pending native request; no stock reservation]
    C --> D{Pending action}
    D -->|Owning cashier| E[Cancel request]
    D -->|Finance or Admin| F[Lock request and approve or reject]
    F --> G{Approved?}
    G -->|Yes| H[Cashier selects owned unused approval]
    H --> I[Checkout rechecks subtotal, stock and unique native use]
    I --> J[Atomic sale and durable approval linkage]
```

The existing table has no historical cart fingerprint or legacy consumption
link. The port preserves subtotal-based approval matching; complete historical
reconciliation remains pending.

## Own-account password change

```mermaid
flowchart TD
    A[Verified staff and current password or recovery proof] --> B[Validate new password and topology]
    B --> C[One PDO transaction: lock POS then HR identities]
    C --> D{Canonical actor, employee status and link valid?}
    D -->|No| E[Reject without password writes]
    D -->|Yes| F{Proof valid and new password different?}
    F -->|No| E
    F -->|Yes| G[Update linked password hashes, forced logout, recovery and audit]
    G --> H{All writes succeed?}
    H -->|No| I[Rollback both domains and consumed recovery]
    H -->|Yes| J[Commit, revoke native sessions and sign out]
    J --> K[Fresh password plus MFA sign-in]
```

This uses qualified schemas on one verified same-server InnoDB connection. It
does not make arbitrary operations on separate PDO connections atomic.

## Product discount proposal

```mermaid
flowchart TD
    A[Inventory Staff or Admin proposal] --> B[Lock actor and product; capture server snapshots]
    B --> C[One pending native request; current price and stock unchanged]
    C --> D{Pending action}
    D -->|Owner or Admin| E[Cancel and retain history]
    D -->|Finance or Admin| F{Decision}
    F -->|Reject| G[Retain product discount]
    F -->|Approve| H[Lock actor, product, request and remaining batches]
    H --> I{Snapshot unchanged and product/amount/expiry valid?}
    I -->|No| J[Reject action; keep request pending]
    I -->|Yes| K[Update product discount, decision and minimal audit]
    K --> L[Commit together or roll back all]
    L --> M[Catalog, labels and checkout use approved product price]
```

Separate native locks do not serialize older preserved PHP handlers. Admin's
existing ability to submit and decide is retained; no new two-person rule.

## Manual product information review

```mermaid
flowchart TD
    A[Inventory Staff or Admin opens saved facts and revision] --> B[Edit description, brand, unit and storage]
    B --> C[Validate actor, active product, revision and transition under locks]
    C --> D[Save draft or submit/resubmit for review; clear old verifier]
    D --> E[Atomic product review history and minimal domain audit]
    E --> F[Updated verified facts stay hidden publicly]
    F --> G[Admin reviews saved description and recorded label information]
    G --> H{Unchanged revision and supported source?}
    H -->|No| I[Keep unpublished; refresh or complete label review]
    H -->|Yes| J{Admin decision}
    J -->|Reject with reason| K[Rejected; Inventory can resubmit]
    J -->|Verify| L[Record verifier/time and publish verified facts]
```

Both audit writes and the state change commit or roll back together. Web-derived
fields cannot be published through this manual description path. Local dirty
forms disable decision controls; server revisions protect the saved state.
