# Existing custom MVC route inventory - 2026-10-26

These are custom PHP routes, not Laravel routes. Middleware is captured as declared. Router dispatch currently recognizes auth and csrf; it does not dispatch RoleMiddleware.

| Method | Path under /public | Controller action | Declared middleware |
|---|---|---|---|
| GET | `/` | `LandingController::index` | `none` |
| GET | `/index` | `LandingController::index` | `none` |
| GET | `/landing` | `LandingController::index` | `none` |
| GET | `/login` | `AuthController::showLogin` | `none` |
| POST | `/login` | `AuthController::login` | `'csrf'` |
| GET | `/logout` | `AuthController::logout` | `none` |
| GET | `/dashboard` | `DashboardController::index` | `'auth'` |
| GET | `/pos` | `PosController::index` | `'auth'` |
| GET | `/api/pos/lookup` | `PosController::lookup` | `'auth'` |
| POST | `/api/pos/checkout` | `PosController::checkout` | `'auth'` |
| GET | `/kiosk` | `KioskController::index` | `none` |
| GET | `/kiosk/compare` | `KioskController::compare` | `none` |
| GET | `/api/kiosk/lookup` | `KioskController::lookup` | `none` |
| GET | `/products` | `ProductController::index` | `'auth'` |
| GET | `/products/labels` | `BarcodeController::labels` | `'auth'` |
| GET | `/inventory` | `InventoryController::index` | `'auth'` |
| GET | `/reports` | `ReportController::index` | `'auth'` |
| GET | `/reports/export/sales-csv` | `ReportController::exportSalesCsv` | `'auth'` |
| GET | `/procurement` | `ProcurementController::index` | `'auth'` |
| GET | `/finance` | `FinanceController::index` | `'auth'` |
| GET | `/hrms/employees` | `EmployeeController::index` | `'auth'` |
| GET | `/hrms/attendance` | `AttendanceController::index` | `'auth'` |
| POST | `/api/hrms/attendance/check-in` | `AttendanceController::checkIn` | `'auth'` |
| GET | `/hrms/payroll` | `PayrollController::index` | `'auth'` |
| GET | `/hrms/privacy` | `PrivacyDsrController::index` | `'auth'` |
| POST | `/api/hrms/privacy/purge-applicants` | `PrivacyDsrController::purgeOldApplicants` | `'auth'` |
| GET | `/storage/file` | `StorageController::download` | `'auth'` |
