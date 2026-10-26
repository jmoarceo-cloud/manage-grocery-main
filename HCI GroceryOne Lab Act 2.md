# Lab Act 2: Design a System to Solve a Problem

## Proposed System: HCI GroceryOne — Unified Grocery Operations

## A. Problem

In a small grocery operation, daily work can be spread across paper forms,
chat messages, spreadsheets, and separate applications. Attendance may be
written on a sheet or sent in a message. Stock counts and restock needs may be
recorded manually. Sales, expenses, and employee information may be stored in
different places. This proposal treats that disconnected-work pattern as the
design problem; it does not claim to have measured its frequency across all
grocery businesses.

When records are scattered, updates can be late, duplicated, forgotten, or inconsistent. Employees may need to repeat the same information or ask coworkers for the latest figures. Managers may have to collect papers or search through chats before they can understand staffing, inventory, sales, or expenses.

Common effects include:

- Attendance records can be incomplete, delayed, or difficult to verify.
- Stock information can become outdated after sales, damage, or delivery.
- Employees may spend time re-entering or reconciling the same information.
- Sales, expenses, and inventory records may not agree across tools.
- Managers may not have a timely view of store operations.
- Restocking and financial decisions can be delayed while people gather information.

For example, a cashier might record attendance on paper, send a stock update in a group chat, and write a sale in a notebook. By the end of the day, the manager has three records that may not agree and must reconcile them manually.

**Target users:** public product browsers and applicants; cashiers, inventory
staff, finance staff, HR staff, HR managers, supervisors, employees, and
administrators. An Accountant role has a limited, read-only payroll review
view. The roles use separate POS and HRMS entry points rather than one
unrestricted shared account.

These users need a dependable way to record work once, keep related information connected, and give each role access to the information needed for its responsibilities.

**Problem statement:** Grocery businesses that depend on paper, chat, and disconnected tools can struggle to keep attendance, stock, sales, expenses, and employee records accurate and up to date. A connected system can reduce repeated manual work and provide a more consistent operational record.

## B. Proposed System

### GroceryOne — Unified Grocery Operations System

HCI GroceryOne is a connected grocery-operations and workforce system. It
brings related daily work into one platform: public catalog and product
discovery, point of sale, inventory, procurement, finance, employee records,
attendance, recruitment, and payroll. Instead of treating each task as an
unconnected paper form or chat conversation, the system records information
in the module responsible for it and connects related business data.

The checked-out system already implements these building blocks: digital
attendance with a configurable geofence, POS sales that update stock,
inventory and procurement workflows, finance approvals, recruitment and
employee workflows, and HRMS payroll. Product discovery features include
catalog details, QR/barcode lookup, printable labels, and reviewed product
information. These capabilities are connected across modules but do not
guarantee that every production environment is configured or migrated.

GroceryOne is one connected platform, not a claim that every role uses one identical screen or one shared login form. The current system has separate POS and HRMS entry points, with role-based access on each side. This keeps the workflows connected while respecting the different duties and permissions of cashiers, inventory staff, finance, HR, supervisors, and employees.

The system's goal is to reduce avoidable paper and chat-based handoffs, not to remove human judgment. Database transactions record operational changes, authorized people approve controlled decisions, and AI-generated information remains a draft until reviewed.

**Scope:** This proposal covers public product discovery, authenticated
checkout, inventory and replenishment, supplier purchasing, financial
controls, recruitment, employee self-service, attendance, and payroll. It
does not propose public customer checkout or replace human approvals. AI
product data is a draft for review, not an autonomous business decision.
Browser location is not perfectly precise, and a geofence is not proof of
identity or presence. The screens below are low-fidelity design illustrations,
not screenshots or pixel-accurate representations of the current interface.

**Current implementation versus proposal:** The source has separate POS and
HRMS experiences with role-specific access. Some employee workflows are
provided through HRMS while POS-linked positions use the POS account; not
every employee sees the same self-service screen. Finance/Admin—not the
Accountant role—approve or reject submitted payroll and record its payment.
The Accountant's payroll view is read-only. Refer to the linked system
documentation for detailed current behavior and deployment-specific
limitations.

## C. System Objectives

### Objective 1: Improve attendance recording

Provide eligible employees with a digital way to record time-in/time-out,
capture the timestamp and permitted location data when configured, and let
authorized staff review attendance information.

### Objective 2: Keep stock information current

Connect completed sales, receiving, and stock movements so that inventory records are updated through the relevant workflows instead of relying only on chat messages or manual recounts.

### Objective 3: Connect operational records

Bring sales, inventory, finance, and employee workflows into one role-based platform so authorized staff can find consistent, timely information and managers can make decisions from recorded data.

### Objective 4: Make controlled decisions traceable

Route restock, purchasing, expense, and payroll decisions to authorized roles;
record outcomes so staff can follow status and management can review the
decision history.

### Objective 5: Support accurate public product information

Let shoppers find product information through the catalog or barcode/QR
lookup while distinguishing reviewed information from draft or unverified
details.

### Proposed evaluation criteria

These are suggested acceptance targets for a future moderated usability test,
not measured results or claims that the existing system has already met them.

| Task | Proposed success criterion |
|---|---|
| Choose the correct staff portal | At least 4 of 5 representative participants select the right POS or HRMS entry point without facilitator help. |
| Complete the relevant attendance action | At least 4 of 5 eligible participants find the action and understand the location/permission result. |
| Find a product using catalog search or a code | At least 4 of 5 participants reach the intended product details page. |
| Explain how low stock proceeds | At least 4 of 5 participants identify the request/approval/receiving sequence and do not assume a cashier can approve stock. |
| Identify who decides payroll | All participants distinguish HR preparation, read-only Accountant review, Finance/Admin decision, and payment/release. |

Record task completion, errors, assistance needed, and participant comments.
Revise the design if participants mistake an illustration for live data,
interpret draft product data as verified, or think location checks guarantee
identity.

## D. Low-Fidelity Wireframes

The examples use fictional names and illustrative amounts. They show the intended workflow, not live business data or exact production screens.

### Screen 1: Staff Access

```text
┌────────────────────────────────────────────────────┐
│ GroceryOne — Staff Portal                          │
├────────────────────────────────────────────────────┤
│ Choose your staff portal                           │
│                                                    │
│ POS STAFF                                          │
│ Cashier, Inventory Staff, Finance                  │
│ [ Sign in to POS ]                                 │
│                                                    │
│ HRMS STAFF                                         │
│ Admin, HR, HR Manager, Supervisor, Employee        │
│ [ Open HRMS Sign In ]                              │
│ Accountant: read-only payroll review               │
│                                                    │
│ POS and HRMS use separate sign-in pages.            │
│ Complete OTP/backup-code verification if required.  │
└────────────────────────────────────────────────────┘
```

**Purpose:** Help staff choose the correct portal while preserving the
separate POS and HRMS authentication paths and role-specific permissions.
This illustration does not combine the two login forms: the POS and HRMS each
collect credentials on their own sign-in page. Eligible accounts complete
2FA when required by the authentication flow.

### Screen 2: Attendance and Employee Self-Service

```text
┌────────────────────────────────────────────────────┐
│ Employee Home                    Juan D.   Sign out│
├────────────────────────────────────────────────────┤
│ Today: October 9, 2026        Shift: 8:00 AM–5 PM  │
│                                                    │
│ Attendance                                         │
│ Status: Not timed in                               │
│ [ TIME IN ]                 [ TIME OUT ]           │
│ Location: configured attendance area               │
│                                                    │
│ My schedule | Leave requests | Overtime | Payslips │
│ Announcements and notifications                    │
│                                                    │
│ Recent attendance                                  │
│ Oct 8   Time in  8:02 AM   Time out 5:04 PM        │
└────────────────────────────────────────────────────┘
```

**Purpose:** Illustrate an eligible employee's attendance and self-service
view. This is not the universal screen for every staff position: POS-linked
employees may use POS self-service, while HRMS employees use HRMS pages.
Schedule and sample attendance values are illustrative. Location permission
and GPS accuracy affect any configured location check; the interface must not
imply that a browser reading is infallible.

### Screen 3: POS and Inventory Operations

```text
┌────────────────────────────────────────────────────┐
│ Store Operations                  Cashier: Juan D. │
├────────────────────────────────────────────────────┤
│ Scan or search product: [________________] [ Find ]│
│                                                    │
│ Cart                                               │
│ Product                 Qty       Price            │
│ Rice 5 kg                1        ₱320.00          │
│ Brown eggs               1         ₱45.00          │
│                                                    │
│ Total: ₱365.00                 [ CHECK OUT ]       │
│                                                    │
│ Stock alert: Rice 5 kg is low                      │
│ Inventory Staff reviews and submits a request       │
│                                                    │
│ Completed sale updates stock and movement history. │
└────────────────────────────────────────────────────┘
```

**Purpose:** Show the business connection between checkout and stock, not a
single screen shared by Cashier and Inventory Staff. A completed sale updates
inventory and records the stock movement. A low-stock alert is handled through
an authorized inventory/request workflow and its approval process; the cashier
does not approve or directly restock inventory. Product names, prices, and
amounts are illustrative.

### Screen 4: Finance Overview

```text
┌────────────────────────────────────────────────────┐
│ Finance Dashboard             Illustrative date    │
├────────────────────────────────────────────────────┤
│ Sales summary       ₱12,450.00                     │
│ Recorded expenses    ₱3,200.00                     │
│ Pending approvals          4                       │
│                                                    │
│ Work queues                                         │
│ Purchase orders awaiting review                    │
│ Operating expenses awaiting approval               │
│ Accounts payable due                               │
│                                                    │
│ Reports: Statements | Budget | Audit log           │
│                                                    │
│ Values shown are illustrative, not live data.      │
└────────────────────────────────────────────────────┘
```

**Purpose:** Illustrate a Finance dashboard for reviewing recorded sales,
expenses, payables, and pending decisions, with links to reports and audit
history. Figures and pending counts are illustrative, not live data or
promised performance. Finance and Admin access is role-restricted; this
wireframe is not an Admin dashboard or an authorization specification.

## Conclusion

Paper records, chat messages, and disconnected apps can make it difficult for
grocery employees and managers to keep attendance, stock, sales, expenses,
and workforce information consistent. HCI GroceryOne provides a connected
approach through its public product catalog, role-protected POS and inventory,
procurement, finance, recruitment, attendance, HRMS, and payroll workflows.
These workflows connect while role permissions and human approvals remain in
place.

The design aims to reduce repeated manual recording and make operational
status easier to review. These are intended benefits, not measured outcomes;
the proposed evaluation criteria above would need to be tested with
representative users before claiming improved efficiency or usability.

Location data still has accuracy limits, records still require correct entry,
and authorized people still make decisions that require human judgment.

**Current-system reference:** [HCI GroceryOne system documentation](project/HCI_SYSTEM.md).
