# Proposed Laravel migration mapping

Date: 2026-10-26. **Partially implemented mapping; remaining names are proposals.**

The owner authorized continuation after Phase 0. Root now runs Laravel 13.38.0.
Native routes and actual class names are in [API](API.md), with evidence in
[PARITY](PARITY.md). Auth, catalog, inventory and the retail transaction core
are implemented; HR/procurement/finance and advanced features remain in legacy.
Manual cashier discount requests and Finance/Admin decisions are now native;
the remaining finance ledger/payables/payroll/procurement approvals stay legacy.
Legacy product_barcode_labels.php and shelf_labels.php now map to native
GET /labels + POST /labels/print; see the bounded decoder/PDF evidence in PARITY.
Both database domains were adopted in place; original IDs were not remapped.
These proposed names organize the remaining port; final
resource routes and FormRequest names must follow each source action's
validation, permission, side effects and response contract. The 135 candidates
include two utilities and one service helper that should not become web pages.
Do not reproduce unsafe exposure merely because a legacy PHP file is reachable.

## Root collisions and preservation

| Existing source | Proposed target | Preservation/port requirement |
|---|---|---|
| `project/` | Read-only legacy reference during implementation; keep its URL runnable | Copy to protected `project.bak.YYYYMMDD/` before any move; keep records private and excluded from Git. Do not delete/deprecate before parity/cutover. |
| Existing root `app/`, `config/`, `public/`, `bin/`, tests and manifests | Preserve snapshot under protected `database/backups/root-mvc-YYYYMMDD/` before reconciling with Laravel | Root app is existing user work. Laravel extraction must not blindly overwrite it. |
| Staged framework skeleton | Root Laravel after preservation/comparison | Use an in-workspace staging directory; Laravel 13 and installed PHP 8.4 proposed. |
| `project/includes/config.php`, `project/HRMS/includes/config.php` | Laravel config, guards/policies, services and explicit migrations | Split connection, auth and business helpers; no runtime schema changes during normal page requests. |
| `config/routes.php` | `routes/web.php`; documented authenticated JSON routes | Replace the custom router with framework routes, CSRF and role/record policies. |
| `app/Views/`, legacy page HTML | `resources/views/` Blade layouts/components/module views | Preserve navigation and business states; escape output, implement accessible responsive forms/tables. |
| CSS/JS/public imagery/scanner libraries | `resources/css`, `resources/js`, Vite output and deliberate public assets | Preserve branding/license notices. Do not copy personnel documents into public assets. |
| Protected runtime uploads | `storage/app/private/` plus authorized download controllers | Preserve existing references through a mapping; classify public product images separately. No public storage link for HR documents. |
| POS tables | `App/Models/Pos/*` with explicit `pos` connection | Preserve primary keys, types, enums, indexes, constraints, triggers, money precision and historical line semantics. |
| HRMS tables | `App/Models/Hrms/*` with explicit `hrms` connection | Keep employee IDs and both user namespaces distinct. Guard identities must not collide. |
| SQL/migration history | `database/migrations/` and documented baseline/adoption strategy | Generate from both live schemas; restore to isolated targets before live additive changes. No blind import into populated live DBs. |
| Legacy transaction helpers | `app/Services/Pos`, Procurement, Finance, Payroll and identity synchronization | Preserve approvals, FEFO/refund restoration, immutable stock logs and cross-domain reconciliation. |
| Legacy exports/mail/QR/PDF integrations | Services, queued jobs where appropriate, private storage and download policies | Preserve formats/token flows; no external mail/API calls during synthetic tests. |
| Legacy CLI contracts and root synthetic checks | PHPUnit/Pest unit/feature suites on verified isolated targets | Port meaningful behavior checks; simulations are not upload/auth/POS integration evidence. |
| Docs and contributor guidance | Existing docs plus live audit/parity/API/maintenance documents | Same-change updates; preserve historical records and bounded evidence. |

Backups in `database/backups/` inherit the existing database directory deny
rule, but root snapshots such as `project.bak.*` also need HTTP protection
before creation. Apply ignore/deny rules before staging the baseline commit.
Repair backup-account access before treating a full dump as a reliable safety
net. A schema-only Phase 0 artifact is not that backup.

## File naming and access contracts

For controller entries below, prefix the handler with
`app/Http/Controllers/` and suffix `.php`. For Blade entries, proposed views
are `resources/views/{lowercase-domain}/{legacy-stem}/index.blade.php`, with
shared components extracted as modules are implemented. Service/CLI/JSON/file
responses do not need placeholder pages. For every mutation, create a focused
FormRequest and authorize the actor and target record before service writes.

Routes below are relative to `/manage-grocery-main/public/`. IDs, token query
contracts and action-specific routes require detailed porting, not sample real
tokens in docs. Preserve old public email/token links during transition.
Public catalog/recruitment/DSR/recipient portals remain public or token-gated;
private operational routes require the correct POS/HR guard and policy. Kiosk
retains its in-store network/device boundary. Logout becomes POST with CSRF.
Do not reduce all existing roles to generic Admin/Manager/Cashier.

## All legacy entry candidates

| Legacy source | Proposed method/path | Proposed handler | Response |
|---|---|---|---|
| `project/HRMS/admin_dashboard.php` | GET + validated POST actions `/admin` | `Administration/AdminDashboardController` | Blade |
| `project/HRMS/admin_profile.php` | GET + validated POST actions `/hrms/admin/profile` | `Auth/AdminProfileController` | Blade |
| `project/HRMS/announcements.php` | GET + validated POST actions `/hrms/announcements` | `Workforce/AnnouncementsController` | Blade |
| `project/HRMS/applicant_document_download.php` | GET `/hrms/applicants/documents/download` | `Recruitment/ApplicantDocumentDownloadController` | private response |
| `project/HRMS/applicant_review.php` | GET + validated POST actions `/hrms/applicants/review` | `Recruitment/ApplicantReviewController` | Blade |
| `project/HRMS/applicant_review_queue.php` | GET + validated POST actions `/hrms/applicants` | `Recruitment/ApplicantReviewQueueController` | Blade |
| `project/HRMS/apply.php` | GET + validated POST actions `/careers/apply` | `Recruitment/ApplyController` | Blade |
| `project/HRMS/attendance.php` | GET + validated POST actions `/hrms/attendance` | `Attendance/AttendanceController` | Blade |
| `project/HRMS/backfill_hired_documents.php` | CLI-only; no HTTP port | `Console command (restricted)` | CLI |
| `project/HRMS/change_password.php` | GET `/account/security`, POST `/account/password` | `AccountSecurityController` / `AccountSecurityService` | Native; bounded identity evidence in PARITY |
| `project/HRMS/check_session_status.php` | GET `/hrms/session/status` | `Auth/CheckSessionStatusController` | JSON |
| `project/HRMS/create_admin.php` | CLI-only; no HTTP port | `Console command (restricted)` | CLI |
| `project/HRMS/employee_dashboard.php` | GET + validated POST actions `/hrms/employee` | `Workforce/EmployeeDashboardController` | Blade |
| `project/HRMS/employee_document_download.php` | GET `/hrms/employees/documents/download` | `Workforce/EmployeeDocumentDownloadController` | private response |
| `project/HRMS/employee_documents.php` | GET + validated POST actions `/hrms/employees/documents` | `Workforce/EmployeeDocumentsController` | Blade |
| `project/HRMS/employee_schedule.php` | GET `/hrms/employee/schedule` | `Attendance/EmployeeScheduleController` | Blade |
| `project/HRMS/employees.php` | GET + validated POST actions `/hrms/employees` | `Workforce/EmployeesController` | Blade |
| `project/HRMS/generate_discount_code.php` | GET + validated POST actions `/hrms/discount-approvals` | `Discounts/GenerateDiscountCodeController` | Blade |
| `project/HRMS/hr_dashboard.php` | GET + validated POST actions `/hrms/hr` | `Workforce/HrDashboardController` | Blade |
| `project/HRMS/hr_letter_download.php` | GET `/hrms/letters/download` | `Letters/HrLetterDownloadController` | private response |
| `project/HRMS/hr_letter_edit.php` | GET + validated POST actions `/hrms/letters/edit` | `Letters/HrLetterEditController` | Blade |
| `project/HRMS/hr_letter_print.php` | GET `/hrms/letters/print` | `Letters/HrLetterPrintController` | print/PDF |
| `project/HRMS/hr_letter_reminders.php` | GET + validated POST actions `/hrms/letters/reminders` | `Letters/HrLetterRemindersController` | Blade |
| `project/HRMS/hr_letter_signed.php` | GET + validated POST actions `/hrms/letters/signed` | `Letters/HrLetterSignedController` | private response |
| `project/HRMS/hr_letter_templates.php` | GET + validated POST actions `/hrms/letters/templates` | `Letters/HrLetterTemplatesController` | Blade |
| `project/HRMS/hr_letters.php` | GET + validated POST actions `/hrms/letters` | `Letters/HrLettersController` | Blade |
| `project/HRMS/hr_manager_dashboard.php` | GET + validated POST actions `/hrms/manager` | `Workforce/HrManagerDashboardController` | Blade |
| `project/HRMS/hr_notifications.php` | GET + validated POST actions `/hrms/notifications` | `Notifications/HrNotificationsController` | Blade |
| `project/HRMS/hr_reports.php` | GET `/hrms/reports` | `Reports/HrReportsController` | Blade |
| `project/HRMS/index.php` | GET `/hrms` | `Auth/IndexController` | redirect |
| `project/HRMS/late_timein_requests.php` | GET + validated POST actions `/hrms/attendance/late-requests` | `Attendance/LateTimeinRequestsController` | Blade |
| `project/HRMS/letter_recipient.php` | GET + validated POST actions `/letters/respond` | `Letters/LetterRecipientController` | Blade |
| `project/HRMS/login.php` | GET + validated POST actions `/hrms/login` | `Auth/LoginController` | Blade |
| `project/HRMS/logout.php` | POST `/hrms/logout` | `Auth/LogoutController` | redirect |
| `project/HRMS/manage_application_form.php` | GET + validated POST actions `/hrms/application-form` | `Recruitment/ManageApplicationFormController` | Blade |
| `project/HRMS/manage_supervisor_teams.php` | GET + validated POST actions `/hrms/supervisor-teams` | `Supervisor/ManageSupervisorTeamsController` | Blade |
| `project/HRMS/my_profile.php` | GET/POST `/account/profile` | `OwnProfileController` / `OwnProfileService` | Native bounded personal/contact form; other profile workflows pending; evidence in PARITY |
| `project/HRMS/password_reset_requests.php` | GET + validated POST actions `/admin/password-reset-requests` | `Administration/PasswordResetRequestsController` | Blade |
| `project/HRMS/payroll/accountant.php` | GET `/hrms/payroll/accountant` | `Payroll/AccountantController` | Blade |
| `project/HRMS/payroll/approve_record.php` | POST `/hrms/payroll/decision` | `Payroll/ApproveRecordController` | JSON/redirect |
| `project/HRMS/payroll/attendance.php` | GET `/hrms/payroll/attendance` | `Payroll/AttendanceController` | Blade |
| `project/HRMS/payroll/capital_transaction.php` | POST `/finance/payroll-funding` | `Finance/CapitalTransactionController` | JSON/redirect |
| `project/HRMS/payroll/cash_ack_download.php` | GET `/hrms/payroll/cash-acknowledgement` | `Payroll/CashAckDownloadController` | private response |
| `project/HRMS/payroll/employees.php` | GET `/hrms/payroll/employees` | `Payroll/EmployeesController` | Blade |
| `project/HRMS/payroll/get_employee_payslips.php` | GET `/api/hrms/payroll/employee-payslips` | `Payroll/GetEmployeePayslipsController` | JSON |
| `project/HRMS/payroll/history.php` | GET `/hrms/payroll/history` | `Payroll/HistoryController` | Blade |
| `project/HRMS/payroll/payroll.php` | GET + validated POST actions `/hrms/payroll` | `Payroll/PayrollController` | Blade |
| `project/HRMS/payroll/payslip_lookup.php` | GET `/hrms/payroll/payslip` | `Payroll/PayslipLookupController` | private response |
| `project/HRMS/payroll/save_record.php` | POST `/hrms/payroll/records` | `Payroll/SaveRecordController` | JSON/redirect |
| `project/HRMS/payroll/submit_for_approval.php` | POST `/hrms/payroll/submit` | `Payroll/SubmitForApprovalController` | JSON/redirect |
| `project/HRMS/payroll/thirteenth.php` | GET `/hrms/payroll/thirteenth-month` | `Payroll/ThirteenthController` | Blade |
| `project/HRMS/payroll/upload_cash_ack.php` | POST `/hrms/payroll/cash-acknowledgement` | `Payroll/UploadCashAckController` | JSON/redirect |
| `project/HRMS/payroll/workflow.php` | service only; no HTTP route | `Services/PayrollWorkflow` | service |
| `project/HRMS/payslip.php` | GET + validated POST actions `/hrms/account/payslips` | `Payroll/PayslipController` | Blade |
| `project/HRMS/ph_location_lookup.php` | GET `/api/locations` | `Recruitment/PhLocationLookupController` | JSON |
| `project/HRMS/privacy_request.php` | GET + validated POST actions `/privacy/request` | `Privacy/PrivacyRequestController` | Blade |
| `project/HRMS/privacy_requests.php` | GET + validated POST actions `/admin/privacy-requests` | `Privacy/PrivacyRequestsController` | Blade |
| `project/HRMS/privacy_response_download.php` | GET `/privacy/response` | `Privacy/PrivacyResponseDownloadController` | private response |
| `project/HRMS/purchase_orders.php` | GET + validated POST actions `/hrms/purchase-orders` | `Procurement/PurchaseOrdersController` | Blade |
| `project/HRMS/requirements_submit.php` | GET + validated POST actions `/careers/requirements` | `Recruitment/RequirementsSubmitController` | Blade |
| `project/HRMS/reset_password.php` | GET `/admin/users/password` | `Administration/ResetPasswordController` | Blade |
| `project/HRMS/resume_download.php` | GET `/hrms/applicants/resume` | `Recruitment/ResumeDownloadController` | private response |
| `project/HRMS/setup_2fa.php` | GET + validated POST actions `/hrms/account/two-factor/setup` | `Auth/Setup2faController` | Blade |
| `project/HRMS/supervisor_attendance.php` | GET + validated POST actions `/supervisor/attendance` | `Supervisor/SupervisorAttendanceController` | Blade |
| `project/HRMS/supervisor_catalog_security.php` | GET + validated POST actions `/supervisor/catalog-access` | `Supervisor/SupervisorCatalogSecurityController` | Blade |
| `project/HRMS/supervisor_dashboard.php` | GET `/supervisor` | `Supervisor/SupervisorDashboardController` | Blade |
| `project/HRMS/supervisor_inventory.php` | GET + validated POST actions `/supervisor/inventory` | `Supervisor/SupervisorInventoryController` | Blade |
| `project/HRMS/supervisor_late_timein.php` | GET + validated POST actions `/supervisor/late-time-in` | `Supervisor/SupervisorLateTimeinController` | Blade |
| `project/HRMS/supervisor_leave.php` | GET + validated POST actions `/supervisor/leave` | `Supervisor/SupervisorLeaveController` | Blade |
| `project/HRMS/supervisor_official_business.php` | GET + validated POST actions `/supervisor/official-business` | `Supervisor/SupervisorOfficialBusinessController` | Blade |
| `project/HRMS/supervisor_overtime.php` | GET + validated POST actions `/supervisor/overtime` | `Supervisor/SupervisorOvertimeController` | Blade |
| `project/HRMS/supervisor_performance.php` | GET + validated POST actions `/supervisor/performance` | `Supervisor/SupervisorPerformanceController` | Blade |
| `project/HRMS/supervisor_pos_monitor.php` | GET `/supervisor/pos` | `Supervisor/SupervisorPosMonitorController` | Blade |
| `project/HRMS/supervisor_purchase_orders.php` | GET `/supervisor/purchase-orders` | `Supervisor/SupervisorPurchaseOrdersController` | Blade |
| `project/HRMS/supervisor_reports.php` | GET `/supervisor/reports` | `Supervisor/SupervisorReportsController` | Blade |
| `project/HRMS/supervisor_schedule.php` | GET `/supervisor/schedule` | `Supervisor/SupervisorScheduleController` | Blade |
| `project/HRMS/verify_2fa.php` | GET + validated POST actions `/hrms/two-factor/verify` | `Auth/Verify2faController` | Blade |
| `project/accounts_payable.php` | GET + validated POST actions `/finance/payables` | `Finance/AccountsPayableController` | Blade |
| `project/ai_product_ingest.php` | GET + validated POST actions `/api/inventory/nutrition-ingest` | `Inventory/AiProductIngestController` | JSON |
| `project/ai_settings.php` | GET + validated POST actions `/inventory/ai-settings` | `Inventory/AiSettingsController` | Blade |
| `project/announcements.php` | GET + validated POST actions `/announcements` | `Workforce/AnnouncementsController` | Blade |
| `project/budgeting.php` | GET + validated POST actions `/finance/budgets` | `Finance/BudgetingController` | Blade |
| `project/catalog.php` | GET `/catalog` | `PublicSite/CatalogController` | Blade |
| `project/catalog_supervisor_login.php` | GET + validated POST actions `/catalog/unlock` | `PublicSite/CatalogSupervisorLoginController` | Blade |
| `project/change_password.php` | GET `/account/security`, POST `/account/password` | `AccountSecurityController` / `AccountSecurityService` | Native; bounded identity evidence in PARITY |
| `project/dashboard.php` | GET `/dashboard` | `Dashboard/DashboardController` | Blade |
| `project/expense_categories.php` | GET + validated POST actions `/finance/expense-categories` | `Finance/ExpenseCategoriesController` | Blade |
| `project/finance.php` | Native decisions under `/discount-requests` and `/product-discounts`; other Finance routes pending | `DiscountApprovalController`, `ProductDiscountController` | Partial; preserved Finance capital/procurement/payment flows remain |
| `project/finance_dashboard.php` | GET `/finance` | `Finance/FinanceDashboardController` | Blade |
| `project/financial_analysis.php` | GET `/finance/analysis` | `Finance/FinancialAnalysisController` | Blade |
| `project/financial_audit_log.php` | GET `/finance/audit` | `Finance/FinancialAuditLogController` | Blade |
| `project/financial_statements.php` | GET `/finance/statements` | `Finance/FinancialStatementsController` | Blade |
| `project/index.php` | GET `/` | `PublicSite/IndexController` | Blade |
| `project/inventory.php` | GET + validated POST actions `/inventory` | `Inventory/InventoryController` | Blade |
| `project/inventory_dashboard.php` | GET `/inventory/dashboard` | `Inventory/InventoryDashboardController` | Blade |
| `project/inventory_reports.php` | GET `/inventory/reports` | `Inventory/InventoryReportsController` | Blade |
| `project/inventory_requests.php` | GET + validated POST actions `/inventory/requests` | `Procurement/InventoryRequestsController` | Blade |
| `project/kiosk.php` | GET `/kiosk` | `PublicSite/KioskController` | Blade |
| `project/label_photo.php` | GET `/inventory/label-photo` | `Inventory/LabelPhotoController` | Blade |
| `project/login.php` | GET + validated POST actions `/login` | `Auth/LoginController` | Blade |
| `project/logout.php` | POST `/logout` | `Auth/LogoutController` | redirect |
| `project/my_profile.php` | GET/POST `/account/profile` | `OwnProfileController` / `OwnProfileService` | Native bounded personal/contact form; other profile workflows pending; evidence in PARITY |
| `project/notifications.php` | GET + validated POST actions `/notifications` | `Notifications/NotificationsController` | Blade |
| `project/nutrition_compare.php` | GET `/catalog/compare` | `PublicSite/NutritionCompareController` | Blade |
| `project/operating_expenses.php` | GET + validated POST actions `/finance/expenses` | `Finance/OperatingExpensesController` | Blade |
| `project/password_reset.php` | GET + validated POST actions `/password/reset` | `Auth/PasswordResetController` | Blade |
| `project/password_reset_request.php` | GET + validated POST actions `/password/request` | `Auth/PasswordResetRequestController` | Blade |
| `project/payslip_view.php` | GET `/account/payslips/view` | `Payroll/PayslipViewController` | private response |
| `project/pos.php` | GET + validated POST actions `/pos` | `Pos/PosController` | Blade |
| `project/procurement_letter.php` | GET `/procurement/letters/preview` | `Procurement/ProcurementLetterController` | print/PDF |
| `project/procurement_signature_enrollment.php` | GET + validated POST actions `/procurement/signature` | `Procurement/ProcurementSignatureEnrollmentController` | Blade |
| `project/product_ai_review.php` | GET + validated POST actions `/products/nutrition/review` | `Inventory/ProductAiReviewController` | Blade |
| `project/product_barcode_labels.php` | GET `/products/barcode-labels` | `Inventory/ProductBarcodeLabelsController` | print |
| `project/product_barcode_lookup.php` | GET `/api/products/barcode` | `Inventory/ProductBarcodeLookupController` | JSON |
| `project/product_batches.php` | GET + validated POST actions `/inventory/batches` | `Inventory/ProductBatchesController` | Blade |
| `project/product_detail.php` | GET `/catalog/product` | `PublicSite/ProductDetailController` | Blade |
| `project/product_details_edit.php` | GET/POST `/manage/products/{id}/information` | `ProductInformationController` / `ProductInformationService` | Native bounded manual lifecycle; auto-AI/photo/full nutrition editing pending |
| `project/products.php` | `/manage/products` plus `/product-discounts` | `CatalogAdminController`, `ProductDiscountController` | Native bounded CRUD/discount flow; uploads/AI remain pending |
| `project/purchase_order_letter.php` | GET `/procurement/purchase-orders/letter` | `Procurement/PurchaseOrderLetterController` | print/PDF |
| `project/purchase_order_receipt.php` | GET `/procurement/purchase-orders/receipts` | `Procurement/PurchaseOrderReceiptController` | Blade |
| `project/purchase_orders.php` | GET + validated POST actions `/procurement/purchase-orders` | `Procurement/PurchaseOrdersController` | Blade |
| `project/reports.php` | GET `/reports` | `Reports/ReportsController` | Blade |
| `project/scan.php` | GET `/catalog/scan` | `PublicProductCodeController` | Native bounded resolver; analytics/camera/private contexts pending |
| `project/scan_insights.php` | GET `/inventory/scan-insights` | `Inventory/ScanInsightsController` | Blade |
| `project/scanner.php` | GET `/scanner` | `Pos/ScannerController` | Blade |
| `project/setup_2fa.php` | GET + validated POST actions `/account/two-factor/setup` | `Auth/Setup2faController` | Blade |
| `project/shelf_labels.php` | GET + validated POST actions `/products/shelf-labels` | `Inventory/ShelfLabelsController` | print |
| `project/shift_report.php` | GET `/reports/shifts` | `Reports/ShiftReportController` | Blade |
| `project/signature_image.php` | GET `/procurement/signatures/image` | `Procurement/SignatureImageController` | private response |
| `project/stock_recommendations.php` | GET `/inventory/recommendations` | `Inventory/StockRecommendationsController` | Blade |
| `project/supplier_inventory_approval.php` | GET + validated POST actions `/supplier/inventory-approval` | `SupplierPortal/SupplierInventoryApprovalController` | Blade |
| `project/supplier_purchase_order_approval.php` | GET + validated POST actions `/supplier/purchase-order-approval` | `SupplierPortal/SupplierPurchaseOrderApprovalController` | Blade |
| `project/suppliers.php` | GET + validated POST actions `/suppliers` | `Procurement/SuppliersController` | Blade |
| `project/vendor_ratings.php` | GET + validated POST actions `/suppliers/ratings` | `Procurement/VendorRatingsController` | Blade |
| `project/verify_2fa.php` | GET + validated POST actions `/two-factor/verify` | `Auth/Verify2faController` | Blade |

Action-level source inventory: [200 candidates](audit/LEGACY_ACTIONS.md). A static
extract cannot establish complete branches; manually reconcile request
parameters, JS requests, exports, downloads, policies and database writes for
each module before checking its parity rows. No mapped entry is verified yet.

The own schedule subset embedded in project/my_profile.php and project/HRMS/my_profile.php now maps to GET /account/schedule (OwnScheduleController/OwnScheduleService). This does not replace company-wide project/HRMS/employee_schedule.php or supervisor_schedule.php, whose roster scope and editing rules remain pending.

Own leave subsets of project/my_profile.php and project/HRMS/my_profile.php now map to GET/POST /account/leave (OwnLeaveController/OwnLeaveService). Native submission/history does not port HR dashboard or supervisor_leave.php decision scopes, credit deduction, attachments or cancellation.

The leave-decision subsets of HR admin/hr/hr_manager dashboards and supervisor_leave.php now share GET /leave-approvals and POST /leave-approvals/{id}/decision through LeaveApprovalController/Service/Policy. Current role/team scope is enforced for both queries and locked writes. Other dashboard monitoring, Admin supervisor-preview selection, cancellation and attachment contracts remain pending. This supersedes the earlier own-leave-only decision-port limit.

The overtime self-service subsets of project/my_profile.php and project/HRMS/my_profile.php now map to GET/POST /account/overtime through OwnOvertimeController/Service; OvertimeEvidence ports the bounded current-schedule recorded-work display. supervisor_overtime.php decision and on-behalf contracts and payroll calculation/release remain pending.

The decision/history subset of supervisor_overtime.php now maps to GET /overtime-approvals and POST /overtime-approvals/{id}/decision through OvertimeApprovalController/Service. Actual HR Admin all-assigned-team/supervisor own-team scope and raw-work review revisions are native. On-behalf entry, specific Admin supervisor-preview selection and broader supervisor dashboard remain pending.

## Original-presentation correction, 2026-10-26

The latest owner instruction preserves `project/` UI/features and changes only
the stack. `index.php` -> `public.home`, `login.php` -> `auth.login` and public
`catalog.php` -> `public.catalog` now use source-derived Blade with local Vite
entries under `resources/{css,js}/legacy`. This is deliberately the original
presentation, not the earlier redesigned layout. CSS/theme sources are retained
under `legacy/source`; fonts have their OFL notices. Private catalog authorization
and password reset remain legacy routes with separate sessions. Other native
screen mappings still need original-style restoration and remaining feature
contracts; no AcademiSync business module, UI or record is imported.

## Product-detail original presentation

`project/product_detail.php` now maps to the source-derived native `public.product`
Blade document with `legacy/product` Vite entries. Controller queries remain
native; no source bootstrap/query/scan logger is included. Known return contexts
map to protected native URLs. CSS maps only the dynamic stock color to a custom
property. Existing safe source URLs, active image delivery and availability
guards remain. Private catalog/session and analytics parity are still pending.

## Nutrition comparison original presentation

`project/nutrition_compare.php` -> `public.compare` now preserves source HTML/CSS,
fallback typography, theme and numeric-minimum cells. Existing native numeric
ids[]/current selections and verified/category/basis/serving guards replace the
legacy encrypted pickN/client-redirect plumbing. Initial/table visual evidence
is measured; full selected-picker/original-SQL parity is not claimed.
