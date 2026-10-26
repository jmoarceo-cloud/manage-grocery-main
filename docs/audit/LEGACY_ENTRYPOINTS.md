# Legacy entry-point inventory - 2026-10-26

Source inventory only. GET/POST presence does not establish authorization, complete actions, live availability or Laravel parity. URL paths are relative to /manage-grocery-main/project/. GET .php aliases redirect to extensionless paths. Setup/repair/helper entries require triage; they are not automatically normal navigation. POST markers come from request/form source.

| Source file | Extensionless path | POST marker | Triage |
|---|---|---|---|
| `project/HRMS/admin_dashboard.php` | `HRMS/admin_dashboard` | yes | screen/action candidate |
| `project/HRMS/admin_profile.php` | `HRMS/admin_profile` | yes | screen/action candidate |
| `project/HRMS/announcements.php` | `HRMS/announcements` | yes | screen/action candidate |
| `project/HRMS/applicant_document_download.php` | `HRMS/applicant_document_download` | not found | screen/action candidate |
| `project/HRMS/applicant_review.php` | `HRMS/applicant_review` | yes | screen/action candidate |
| `project/HRMS/applicant_review_queue.php` | `HRMS/applicant_review_queue` | yes | screen/action candidate |
| `project/HRMS/apply.php` | `HRMS/apply` | yes | screen/action candidate |
| `project/HRMS/attendance.php` | `HRMS/attendance` | yes | screen/action candidate |
| `project/HRMS/backfill_hired_documents.php` | `HRMS/backfill_hired_documents` | not found | restricted utility/helper review |
| `project/HRMS/change_password.php` | `HRMS/change_password` | yes | screen/action candidate |
| `project/HRMS/check_session_status.php` | `HRMS/check_session_status` | not found | screen/action candidate |
| `project/HRMS/create_admin.php` | `HRMS/create_admin` | not found | restricted utility/helper review |
| `project/HRMS/employee_dashboard.php` | `HRMS/employee_dashboard` | yes | screen/action candidate |
| `project/HRMS/employee_document_download.php` | `HRMS/employee_document_download` | not found | screen/action candidate |
| `project/HRMS/employee_documents.php` | `HRMS/employee_documents` | yes | screen/action candidate |
| `project/HRMS/employee_schedule.php` | `HRMS/employee_schedule` | not found | screen/action candidate |
| `project/HRMS/employees.php` | `HRMS/employees` | yes | screen/action candidate |
| `project/HRMS/generate_discount_code.php` | `HRMS/generate_discount_code` | yes | screen/action candidate |
| `project/HRMS/hr_dashboard.php` | `HRMS/hr_dashboard` | yes | screen/action candidate |
| `project/HRMS/hr_letter_download.php` | `HRMS/hr_letter_download` | not found | screen/action candidate |
| `project/HRMS/hr_letter_edit.php` | `HRMS/hr_letter_edit` | yes | screen/action candidate |
| `project/HRMS/hr_letter_print.php` | `HRMS/hr_letter_print` | not found | screen/action candidate |
| `project/HRMS/hr_letter_reminders.php` | `HRMS/hr_letter_reminders` | yes | screen/action candidate |
| `project/HRMS/hr_letter_signed.php` | `HRMS/hr_letter_signed` | yes | screen/action candidate |
| `project/HRMS/hr_letter_templates.php` | `HRMS/hr_letter_templates` | yes | screen/action candidate |
| `project/HRMS/hr_letters.php` | `HRMS/hr_letters` | yes | screen/action candidate |
| `project/HRMS/hr_manager_dashboard.php` | `HRMS/hr_manager_dashboard` | yes | screen/action candidate |
| `project/HRMS/hr_notifications.php` | `HRMS/hr_notifications` | yes | screen/action candidate |
| `project/HRMS/hr_reports.php` | `HRMS/hr_reports` | not found | screen/action candidate |
| `project/HRMS/index.php` | `HRMS/index` | not found | screen/action candidate |
| `project/HRMS/late_timein_requests.php` | `HRMS/late_timein_requests` | yes | screen/action candidate |
| `project/HRMS/letter_recipient.php` | `HRMS/letter_recipient` | yes | screen/action candidate |
| `project/HRMS/login.php` | `HRMS/login` | yes | screen/action candidate |
| `project/HRMS/logout.php` | `HRMS/logout` | not found | screen/action candidate |
| `project/HRMS/manage_application_form.php` | `HRMS/manage_application_form` | yes | screen/action candidate |
| `project/HRMS/manage_supervisor_teams.php` | `HRMS/manage_supervisor_teams` | yes | screen/action candidate |
| `project/HRMS/my_profile.php` | `HRMS/my_profile` | yes | screen/action candidate |
| `project/HRMS/password_reset_requests.php` | `HRMS/password_reset_requests` | yes | screen/action candidate |
| `project/HRMS/payroll/accountant.php` | `HRMS/payroll/accountant` | not found | screen/action candidate |
| `project/HRMS/payroll/approve_record.php` | `HRMS/payroll/approve_record` | not found | screen/action candidate |
| `project/HRMS/payroll/attendance.php` | `HRMS/payroll/attendance` | not found | screen/action candidate |
| `project/HRMS/payroll/capital_transaction.php` | `HRMS/payroll/capital_transaction` | yes | screen/action candidate |
| `project/HRMS/payroll/cash_ack_download.php` | `HRMS/payroll/cash_ack_download` | not found | screen/action candidate |
| `project/HRMS/payroll/employees.php` | `HRMS/payroll/employees` | not found | screen/action candidate |
| `project/HRMS/payroll/get_employee_payslips.php` | `HRMS/payroll/get_employee_payslips` | not found | screen/action candidate |
| `project/HRMS/payroll/history.php` | `HRMS/payroll/history` | not found | screen/action candidate |
| `project/HRMS/payroll/payroll.php` | `HRMS/payroll/payroll` | yes | screen/action candidate |
| `project/HRMS/payroll/payslip_lookup.php` | `HRMS/payroll/payslip_lookup` | not found | screen/action candidate |
| `project/HRMS/payroll/save_record.php` | `HRMS/payroll/save_record` | not found | screen/action candidate |
| `project/HRMS/payroll/submit_for_approval.php` | `HRMS/payroll/submit_for_approval` | not found | screen/action candidate |
| `project/HRMS/payroll/thirteenth.php` | `HRMS/payroll/thirteenth` | not found | screen/action candidate |
| `project/HRMS/payroll/upload_cash_ack.php` | `HRMS/payroll/upload_cash_ack` | yes | screen/action candidate |
| `project/HRMS/payroll/workflow.php` | `HRMS/payroll/workflow` | not found | restricted utility/helper review |
| `project/HRMS/payslip.php` | `HRMS/payslip` | yes | screen/action candidate |
| `project/HRMS/ph_location_lookup.php` | `HRMS/ph_location_lookup` | not found | screen/action candidate |
| `project/HRMS/privacy_request.php` | `HRMS/privacy_request` | yes | screen/action candidate |
| `project/HRMS/privacy_requests.php` | `HRMS/privacy_requests` | yes | screen/action candidate |
| `project/HRMS/privacy_response_download.php` | `HRMS/privacy_response_download` | not found | screen/action candidate |
| `project/HRMS/purchase_orders.php` | `HRMS/purchase_orders` | yes | screen/action candidate |
| `project/HRMS/requirements_submit.php` | `HRMS/requirements_submit` | yes | screen/action candidate |
| `project/HRMS/reset_password.php` | `HRMS/reset_password` | not found | screen/action candidate |
| `project/HRMS/resume_download.php` | `HRMS/resume_download` | not found | screen/action candidate |
| `project/HRMS/setup_2fa.php` | `HRMS/setup_2fa` | yes | screen/action candidate |
| `project/HRMS/supervisor_attendance.php` | `HRMS/supervisor_attendance` | yes | screen/action candidate |
| `project/HRMS/supervisor_catalog_security.php` | `HRMS/supervisor_catalog_security` | yes | screen/action candidate |
| `project/HRMS/supervisor_dashboard.php` | `HRMS/supervisor_dashboard` | not found | screen/action candidate |
| `project/HRMS/supervisor_inventory.php` | `HRMS/supervisor_inventory` | yes | screen/action candidate |
| `project/HRMS/supervisor_late_timein.php` | `HRMS/supervisor_late_timein` | yes | screen/action candidate |
| `project/HRMS/supervisor_leave.php` | `HRMS/supervisor_leave` | yes | screen/action candidate |
| `project/HRMS/supervisor_official_business.php` | `HRMS/supervisor_official_business` | yes | screen/action candidate |
| `project/HRMS/supervisor_overtime.php` | `HRMS/supervisor_overtime` | yes | screen/action candidate |
| `project/HRMS/supervisor_performance.php` | `HRMS/supervisor_performance` | yes | screen/action candidate |
| `project/HRMS/supervisor_pos_monitor.php` | `HRMS/supervisor_pos_monitor` | not found | screen/action candidate |
| `project/HRMS/supervisor_purchase_orders.php` | `HRMS/supervisor_purchase_orders` | not found | screen/action candidate |
| `project/HRMS/supervisor_reports.php` | `HRMS/supervisor_reports` | not found | screen/action candidate |
| `project/HRMS/supervisor_schedule.php` | `HRMS/supervisor_schedule` | not found | screen/action candidate |
| `project/HRMS/verify_2fa.php` | `HRMS/verify_2fa` | yes | screen/action candidate |
| `project/accounts_payable.php` | `accounts_payable` | yes | screen/action candidate |
| `project/ai_product_ingest.php` | `ai_product_ingest` | yes | screen/action candidate |
| `project/ai_settings.php` | `ai_settings` | yes | screen/action candidate |
| `project/announcements.php` | `announcements` | yes | screen/action candidate |
| `project/budgeting.php` | `budgeting` | yes | screen/action candidate |
| `project/catalog.php` | `catalog` | not found | screen/action candidate |
| `project/catalog_supervisor_login.php` | `catalog_supervisor_login` | yes | screen/action candidate |
| `project/change_password.php` | `change_password` | yes | screen/action candidate |
| `project/dashboard.php` | `dashboard` | not found | screen/action candidate |
| `project/expense_categories.php` | `expense_categories` | yes | screen/action candidate |
| `project/finance.php` | `finance` | yes | screen/action candidate |
| `project/finance_dashboard.php` | `finance_dashboard` | not found | screen/action candidate |
| `project/financial_analysis.php` | `financial_analysis` | not found | screen/action candidate |
| `project/financial_audit_log.php` | `financial_audit_log` | not found | screen/action candidate |
| `project/financial_statements.php` | `financial_statements` | not found | screen/action candidate |
| `project/index.php` | `index` | not found | screen/action candidate |
| `project/inventory.php` | `inventory` | yes | screen/action candidate |
| `project/inventory_dashboard.php` | `inventory_dashboard` | not found | screen/action candidate |
| `project/inventory_reports.php` | `inventory_reports` | not found | screen/action candidate |
| `project/inventory_requests.php` | `inventory_requests` | yes | screen/action candidate |
| `project/kiosk.php` | `kiosk` | not found | screen/action candidate |
| `project/label_photo.php` | `label_photo` | not found | screen/action candidate |
| `project/login.php` | `login` | yes | screen/action candidate |
| `project/logout.php` | `logout` | yes | screen/action candidate |
| `project/my_profile.php` | `my_profile` | yes | screen/action candidate |
| `project/notifications.php` | `notifications` | yes | screen/action candidate |
| `project/nutrition_compare.php` | `nutrition_compare` | not found | screen/action candidate |
| `project/operating_expenses.php` | `operating_expenses` | yes | screen/action candidate |
| `project/password_reset.php` | `password_reset` | yes | screen/action candidate |
| `project/password_reset_request.php` | `password_reset_request` | yes | screen/action candidate |
| `project/payslip_view.php` | `payslip_view` | not found | screen/action candidate |
| `project/pos.php` | `pos` | yes | screen/action candidate |
| `project/procurement_letter.php` | `procurement_letter` | not found | screen/action candidate |
| `project/procurement_signature_enrollment.php` | `procurement_signature_enrollment` | yes | screen/action candidate |
| `project/product_ai_review.php` | `product_ai_review` | yes | screen/action candidate |
| `project/product_barcode_labels.php` | `product_barcode_labels` | not found | screen/action candidate |
| `project/product_barcode_lookup.php` | `product_barcode_lookup` | not found | screen/action candidate |
| `project/product_batches.php` | `product_batches` | yes | screen/action candidate |
| `project/product_detail.php` | `product_detail` | not found | screen/action candidate |
| `project/product_details_edit.php` | `product_details_edit` | yes | screen/action candidate |
| `project/products.php` | `products` | yes | screen/action candidate |
| `project/purchase_order_letter.php` | `purchase_order_letter` | not found | screen/action candidate |
| `project/purchase_order_receipt.php` | `purchase_order_receipt` | not found | screen/action candidate |
| `project/purchase_orders.php` | `purchase_orders` | yes | screen/action candidate |
| `project/reports.php` | `reports` | not found | screen/action candidate |
| `project/scan.php` | `scan` | not found | screen/action candidate |
| `project/scan_insights.php` | `scan_insights` | not found | screen/action candidate |
| `project/scanner.php` | `scanner` | not found | screen/action candidate |
| `project/setup_2fa.php` | `setup_2fa` | yes | screen/action candidate |
| `project/shelf_labels.php` | `shelf_labels` | yes | screen/action candidate |
| `project/shift_report.php` | `shift_report` | not found | screen/action candidate |
| `project/signature_image.php` | `signature_image` | not found | screen/action candidate |
| `project/stock_recommendations.php` | `stock_recommendations` | not found | screen/action candidate |
| `project/supplier_inventory_approval.php` | `supplier_inventory_approval` | yes | screen/action candidate |
| `project/supplier_purchase_order_approval.php` | `supplier_purchase_order_approval` | yes | screen/action candidate |
| `project/suppliers.php` | `suppliers` | yes | screen/action candidate |
| `project/vendor_ratings.php` | `vendor_ratings` | yes | screen/action candidate |
| `project/verify_2fa.php` | `verify_2fa` | yes | screen/action candidate |
