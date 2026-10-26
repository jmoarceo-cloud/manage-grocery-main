<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This contract test must run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
$sources = [];
foreach ([
    'inventory_requests.php',
    'purchase_orders.php',
    'purchase_order_receipt.php',
    'inventory_reports.php',
    'finance.php',
    'purchase_order_letter.php',
    'supplier_purchase_order_approval.php',
    'includes/export.php',
    'supplier_inventory_approval.php',
    'purchase_order_receipt.php',
    'includes/config.php',
    'includes/procurement_financial_summary.php',
    'includes/sidebar.php',
    'HRMS/purchase_orders.php',
    'HRMS/includes/hr_sidebar.php',
    'HRMS/supervisor_purchase_orders.php',
    'HRMS/includes/supervisor_sidebar.php',
    'HRMS/includes/config.php',
    'database/migrations/2026_10_01_procurement_routing_receipts.sql',
    'database/migrations/2026_10_02_procurement_request_po_links.sql',
    'database/migrations/2026_10_03_hrms_purchase_order_requests.sql',
    'database/grocery_pos_schema.sql',
] as $file) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
    $sources[$file] = file_get_contents($path);
    if ($sources[$file] === false) {
        throw new RuntimeException("Unable to read {$file}");
    }
}

$checks = [
    'Restock batches include multiple independently routed lines' =>
        strpos($sources['inventory_requests.php'], 'submit_multi_request') !== false
        && strpos($sources['inventory_requests.php'], 'request_group_id') !== false
        && strpos($sources['inventory_requests.php'], 'item_quantity[]') !== false,
    'POs are department-scoped for Inventory Staff and keep Cashier out' =>
        strpos($sources['purchase_orders.php'], "in_array(\$__role, ['admin', 'inventory_staff', 'finance'], true)") !== false
        && strpos($sources['purchase_orders.php'], "po.department = ?") !== false
        && strpos($sources['purchase_orders.php'], "(po.department = ? OR \$restockOrderExists)") !== false
        && strpos($sources['purchase_orders.php'], "AND (po.department = ? OR \$restockOrderExists)") !== false
        && strpos($sources['purchase_orders.php'], "VALUES (?, ?, 'draft'") !== false,
    'Finance queue shows department and retains approve/reject workflow' =>
        strpos($sources['finance.php'], "finance_approve_po") !== false
        && strpos($sources['finance.php'], "finance_reject_po") !== false
        && strpos($sources['finance.php'], "Department: <strong>") !== false
        && strpos($sources['finance.php'], "status = 'pending_supplier', request_department") !== false
        && strpos($sources['finance.php'], "Only a Finance-role user can approve Purchase Orders") !== false
        && strpos($sources['finance.php'], 'if (($_SESSION[\'role\'] ?? \'\') === \'finance\')') !== false
        && strpos($sources['finance.php'], "status = 'pending_supplier'") !== false,
    'PO receiving creates a system receipt with item-level history and no required upload' =>
        strpos($sources['purchase_orders.php'], 'recordPurchaseOrderReceipt(') !== false
        && strpos($sources['purchase_orders.php'], 'receipt_document') === false
        && strpos($sources['purchase_orders.php'], 'automatically generates a receipt') !== false
        && strpos($sources['purchase_orders.php'], 'Receipt History') !== false
        && strpos($sources['purchase_orders.php'], 'Preview receipt quantities') !== false
        && strpos($sources['purchase_orders.php'], '<iframe') !== false
        && strpos($sources['purchase_orders.php'], 'class="receipt-history-card"') !== false
        && strpos($sources['purchase_orders.php'], "(\$receipt['receipt_file_path'] ?? '') === 'AUTO_GENERATED'") !== false
        && strpos($sources['purchase_orders.php'], 'Not linked to an inventory request') !== false
        && strpos($sources['includes/config.php'], 'purchase_order_receipts') !== false
        && strpos($sources['includes/config.php'], 'purchase_order_receipt_items') !== false
        && strpos($sources['includes/config.php'], "'AUTO_GENERATED'") !== false
        && strpos($sources['purchase_order_receipt.php'], "['admin', 'inventory_staff', 'finance']") !== false
        && strpos($sources['purchase_order_receipt.php'], 'Print / Save as PDF') !== false,
    'PO detail exposes workflow timestamps and print/export controls' =>
        strpos($sources['purchase_orders.php'], "po_detail['ordered_at']") !== false
        && strpos($sources['purchase_orders.php'], "po_detail['received_at']") !== false
        && strpos($sources['purchase_orders.php'], 'window.print()') !== false
        && strpos($sources['purchase_orders.php'], 'export_po=pdf') !== false
        && strpos($sources['purchase_orders.php'], 'DATE(po.created_at) >= ?') !== false,
    'HR and Supervisor purchase orders keep a signed Formality Letter available to Finance and Admin' =>
        strpos($sources['purchase_orders.php'], 'purchase_order_letter.php?po_id=') !== false
        && strpos($sources['finance.php'], 'View Formality Letter</a>') !== false
        && strpos($sources['purchase_order_letter.php'], "in_array(\$role, ['admin', 'finance'], true)") !== false
        && strpos($sources['purchase_order_letter.php'], 'Requester e-signature') !== false
        && strpos($sources['purchase_order_letter.php'], 'Finance approval e-signature') !== false
        && strpos($sources['purchase_order_letter.php'], 'Supplier acceptance e-signature') !== false
        && strpos($sources['purchase_order_letter.php'], 'window.print()') !== false,
    'HR and Supervisor non-catalog items can be supplier-signed, Admin-received, and accrued in Accounts Payable' =>
        preg_match('/\$poId,\s*null,\s*\$item\[\'item_name\'\]/', $sources['HRMS/purchase_orders.php']) === 1
        && strpos($sources['supplier_purchase_order_approval.php'], "status = 'ordered', ordered_at = NOW()") !== false
        && strpos($sources['purchase_orders.php'], 'Only Admin can receive HR and Supervisor Purchase Orders.') !== false
        && strpos($sources['includes/config.php'], "\$lineTotal = round((float) \$item['unit_cost'] * \$qtyNow, 2);") !== false
        && strpos($sources['includes/config.php'], "\$receiptTotalCost += \$lineTotal;") !== false
        && strpos($sources['includes/config.php'], "recordAccountsPayable(") !== false
        && strpos($sources['includes/config.php'], "'supplier_invoice_required'") !== false
        && strpos($sources['includes/config.php'], "'accounts_payable.php?section=invoices'") !== false
        && strpos($sources['includes/config.php'], "'purchase_order'") !== false
        && strpos($sources['includes/config.php'], "WHERE po.status IN ('approved', 'ordered', 'partially_received')") !== false,
    'Finance can export purchase-order lists without gaining PO management actions' =>
        strpos($sources['purchase_orders.php'], "\$isExportRequest = isset(\$_GET['export']) && in_array(\$_GET['export'], ['xlsx', 'pdf'], true);") !== false
        && strpos($sources['purchase_orders.php'], "\$isDetailExportRequest = isset(\$_GET['export_po']) && in_array(\$_GET['export_po'], ['xlsx', 'pdf'], true);") !== false
        && strpos($sources['purchase_orders.php'], "\$isFinanceExportRequest = \$__role === 'finance'") !== false
        && strpos($sources['purchase_orders.php'], "&& !isset(\$_POST['action']);") !== false
        && strpos($sources['purchase_orders.php'], 'if (!$canManage && !$isFinanceExportRequest)') !== false
        && strpos($sources['purchase_orders.php'], "if (\$_GET['export'] === 'xlsx')") !== false
        && strpos($sources['purchase_orders.php'], "exportToPDF('purchase_orders'") !== false
        && strpos($sources['purchase_orders.php'], "exportPostForm('purchase_orders.php?po=' . rawurlencode((string) \$_GET['po']) . '&export_po=pdf'") !== false
        && strpos($sources['purchase_orders.php'], "\$canManage = in_array(\$__role, ['admin', 'inventory_staff'], true);") !== false,
    'PO report totals count only approved and fulfilled commitments' =>
        strpos($sources['purchase_orders.php'], "\$approvedPoStatuses = ['approved', 'ordered', 'partially_received', 'received'];") !== false
        && strpos($sources['purchase_orders.php'], "\$approvedTotal += \$amount;") !== false
        && strpos($sources['purchase_orders.php'], "\$notApprovedTotal += \$amount;") !== false
        && strpos($sources['purchase_orders.php'], 'Overall approved total (approved, ordered, partially received, received): PHP ') !== false
        && strpos($sources['purchase_orders.php'], "'NOT APPROVED (EXCLUDED)'") !== false,
    'PO report scope is initialized after filter variables are normalized' =>
        strpos($sources['purchase_orders.php'], '$poReportScope =') > strpos($sources['purchase_orders.php'], '$filter_status = trim($_GET[\'status\'] ?? \'\');')
        && strpos($sources['purchase_orders.php'], '$poReportScope =') < strpos($sources['purchase_orders.php'], '$where  = [];'),
    'Print action sits with exports and outputs separate approved and non-approved report tables' =>
        strpos($sources['purchase_orders.php'], 'po-print-report" aria-label="Printable purchase order summary"') !== false
        && strpos($sources['purchase_orders.php'], 'onclick="window.print()">Print</button>') !== false
        && strpos($sources['purchase_orders.php'], 'class="po-export-actions"') !== false
        && strpos($sources['purchase_orders.php'], 'Overall approved total: <strong>PHP ') !== false
        && strpos($sources['purchase_orders.php'], 'Separate total: <strong>PHP ') !== false,
    'PDF report places summary metadata before the table headings' =>
        strpos($sources['includes/export.php'], '$pdf->tableHeaders = [];') !== false
        && strpos($sources['includes/export.php'], '$pdf->drawTableHeader();') !== false,
    'PDF tables size columns for their contents and wrap long values without truncation' =>
        strpos($sources['includes/export.php'], '$pdf->GetStringWidth(') !== false
        && strpos($sources['includes/export.php'], '$pdf->MultiCell($colWidths[$i] - 2, $lineHeight, $cellTexts[$i]') !== false
        && strpos($sources['includes/export.php'], '$pdf->Rect($x, $rowY, $colWidths[$i], $rowHeight);') !== false
        && strpos($sources['includes/export.php'], '$maxChars') === false
        && strpos($sources['purchase_orders.php'], "\$__poHeaders = ['PO #', 'Department', 'Supplier', 'Status', 'Items', 'Total (PHP)', 'Created By', 'Created At'];") !== false,
    'Individual PO export buttons accept the export_po format parameter' =>
        strpos($sources['includes/export.php'], "\$query['export_po']") !== false
        && strpos($sources['purchase_orders.php'], '&export_po=xlsx') !== false
        && strpos($sources['purchase_orders.php'], '&export_po=pdf') !== false,
    'HR Staff and HR Managers can request curated HR supplies directly from Finance and track their own submissions' =>
        strpos($sources['HRMS/includes/hr_sidebar.php'], 'href="<?= $__navBase ?>purchase_orders.php"') !== false
        && strpos($sources['HRMS/includes/hr_sidebar.php'], "in_array(\$__role, ['hr', 'hr_manager'], true)") !== false
        && strpos($sources['HRMS/purchase_orders.php'], "requireHR();") !== false
        && strpos($sources['HRMS/purchase_orders.php'], "in_array(\$currentRole, ['hr', 'hr_manager'], true)") !== false
        && strpos($sources['HRMS/purchase_orders.php'], "VALUES (?, ?, 'pending_approval', ?, NULL, ?, ?, NOW())") !== false
        && strpos($sources['HRMS/purchase_orders.php'], "WHERE po.requested_by_hrms_user_id = ?") !== false
        && strpos($sources['HRMS/purchase_orders.php'], "'finance.php#purchase-orders'") !== false
        && strpos($sources['HRMS/purchase_orders.php'], 'Submit to Finance for Approval') !== false
        && strpos($sources['HRMS/purchase_orders.php'], "'Office stationery' => [") !== false
        && strpos($sources['HRMS/purchase_orders.php'], "'Employee records and filing' => [") !== false
        && strpos($sources['HRMS/purchase_orders.php'], "'Recruitment and onboarding' => [") !== false
        && strpos($sources['HRMS/purchase_orders.php'], "'Training and employee engagement' => [") !== false
        && strpos($sources['HRMS/purchase_orders.php'], "'HR office equipment' => [") !== false
        && strpos($sources['HRMS/purchase_orders.php'], "\$requestableItemNames[\$itemType]") !== false
        && strpos($sources['HRMS/purchase_orders.php'], 'name="item_other_name[]" maxlength="150" disabled') === false
        && strpos($sources['HRMS/purchase_orders.php'], 'otherName.disabled') === false
        && strpos($sources['HRMS/purchase_orders.php'], "include __DIR__ . '/includes/sweetalert.php';") !== false
        && strpos($sources['HRMS/purchase_orders.php'], "WHERE po.requested_by_hrms_user_id = ?") !== false,
    'Supervisors can submit position-specific purchase orders directly to Finance and track only their own' =>
        strpos($sources['HRMS/includes/supervisor_sidebar.php'], 'href="supervisor_purchase_orders.php<?= $__qs ?>"') !== false
        && strpos($sources['HRMS/supervisor_purchase_orders.php'], "\$purchaseOrderContext = 'supervisor'") !== false
        && strpos($sources['HRMS/purchase_orders.php'], 'if ($isSupervisorRequest)') !== false
        && strpos($sources['HRMS/purchase_orders.php'], 'requireSupervisorRole();') !== false
        && strpos($sources['HRMS/purchase_orders.php'], "SELECT department, position FROM employees WHERE employee_id = ?") !== false
        && strpos($sources['HRMS/purchase_orders.php'], "'Team oversight and reporting' => [") !== false
        && strpos($sources['HRMS/purchase_orders.php'], "'Team communication and coordination' => [") !== false
        && strpos($sources['HRMS/purchase_orders.php'], "'Floor supervision and safety' => [") !== false
        && strpos($sources['HRMS/purchase_orders.php'], "'POS and office supervision tools' => [") !== false
        && strpos($sources['HRMS/purchase_orders.php'], "VALUES (?, ?, 'pending_approval', ?, NULL, ?, ?, NOW())") !== false
        && strpos($sources['HRMS/purchase_orders.php'], "'finance.php#purchase-orders'") !== false
        && strpos($sources['HRMS/purchase_orders.php'], "WHERE po.requested_by_hrms_user_id = ?") !== false
        && strpos($sources['HRMS/purchase_orders.php'], '$requesterHrmsUserId = $isSupervisorRequest && !$canSubmit ? 0 : $hrmsUserId;') !== false
        && strpos($sources['HRMS/purchase_orders.php'], "include __DIR__ . '/includes/sweetalert.php';") !== false
        && strpos($sources['HRMS/purchase_orders.php'], 'Admin preview is read-only') !== false,
    'Finance-approved restock requests create one linked PO, supplier signature moves it to Orders, and receipt fulfills the request' =>
        strpos($sources['finance.php'], 'createFinanceApprovedRestockPurchaseOrder(') !== false
        && strpos($sources['includes/config.php'], "VALUES (?, ?, 'approved', ?, ?, ?, NOW(), ?, NOW())") !== false
        && strpos($sources['includes/config.php'], 'purchase_order_id = ?') !== false
        && strpos($sources['includes/config.php'], 'inventory_request_id') !== false
        && strpos($sources['supplier_inventory_approval.php'], "SET status = 'ordered', ordered_at = NOW()") !== false
        && strpos($sources['supplier_inventory_approval.php'], 'purchase_orders.php?status=ordered') !== false
        && strpos($sources['includes/config.php'], "SET ir.status = 'fulfilled'") !== false
        && strpos($sources['inventory_requests.php'], 'Create Departmental POs for Selected Supplier-Approved Requests') === false
        && strpos($sources['purchase_orders.php'], 'supplier_signed_count') !== false
        && strpos($sources['purchase_orders.php'], "Only an Approved Purchase Order can be marked as Ordered.") !== false
        && strpos($sources['purchase_orders.php'], 'Restock Purchase Orders move to Orders automatically') !== false
        && strpos($sources['purchase_orders.php'], "Only an Ordered (or Partially Received) Purchase Order can be received.") !== false
        && strpos($sources['purchase_orders.php'], "? <> 'inventory_staff' OR po.department = ? OR \$restockOrderExists") !== false
        && strpos($sources['purchase_orders.php'], 'then Admin or') !== false
        && strpos($sources['purchase_orders.php'], 'Inventory Staff receives it.') !== false,
    'Financial summary uses Finance helpers, date filters, and role checks' =>
        strpos($sources['inventory_reports.php'], 'getProcurementFinancialSummary(') !== false
        && strpos($sources['includes/config.php'], 'getIncomeStatement(') !== false
        && strpos($sources['includes/config.php'], 'getCashFlowStatement(') !== false
        && strpos($sources['inventory_reports.php'], 'export_financial') !== false
        && strpos($sources['inventory_reports.php'], '$showFinancialSummary') !== false,
    'Financial summary includes approved bill accrual and splits AP cash payments by source' =>
        strpos($sources['includes/config.php'], "DATE(COALESCE(approved_at, due_date)) BETWEEN ? AND ?") !== false
        && strpos($sources['includes/config.php'], "ap.source_type IN ('purchase_order', 'inventory_request')") !== false
        && strpos($sources['includes/config.php'], "ap.source_type = 'operating_expense'") !== false
        && strpos($sources['includes/procurement_financial_summary.php'], 'composeProcurementFinancialSummary') !== false,
    'Uncosted legacy sales and exact unit-cost request totals are disclosed' =>
        strpos($sources['includes/procurement_financial_summary.php'], 'procurementRequestLineEstimateFromUnitCost') !== false
        && strpos($sources['inventory_requests.php'], 'procurementRequestLineEstimateFromUnitCost(') !== false
        && strpos($sources['inventory_reports.php'], 'uncosted_sales_quantity') !== false,
    'Migration is rerunnable and defines the required receipt schema' =>
        strpos($sources['database/migrations/2026_10_01_procurement_routing_receipts.sql'], 'information_schema.columns') !== false
        && strpos($sources['database/migrations/2026_10_01_procurement_routing_receipts.sql'], 'CREATE TABLE IF NOT EXISTS purchase_order_receipts') !== false
        && strpos($sources['database/migrations/2026_10_01_procurement_routing_receipts.sql'], 'CREATE TABLE IF NOT EXISTS purchase_order_receipt_items') !== false
        && strpos($sources['database/migrations/2026_10_02_procurement_request_po_links.sql'], 'information_schema.table_constraints') !== false
        && strpos($sources['database/migrations/2026_10_02_procurement_request_po_links.sql'], 'ON DELETE SET NULL') !== false,
    'HR purchase-request tracking column is included in setup and migration' =>
        strpos($sources['includes/config.php'], "'requested_by_hrms_user_id' => 'INT DEFAULT NULL'") !== false
        && strpos($sources['database/migrations/2026_10_03_hrms_purchase_order_requests.sql'], 'information_schema.columns') !== false
        && strpos($sources['database/migrations/2026_10_03_hrms_purchase_order_requests.sql'], 'requested_by_hrms_user_id') !== false
        && strpos($sources['database/grocery_pos_schema.sql'], '`requested_by_hrms_user_id` int(11) DEFAULT NULL') !== false,
    'Base schema stores PO correspondence on PO items rather than request rows' =>
        preg_match('/CREATE TABLE `inventory_requests` \((.*?)\) ENGINE=/s', $sources['database/grocery_pos_schema.sql'], $requestTable) === 1
        && strpos($requestTable[1], '`inventory_request_id`') === false
        && preg_match('/CREATE TABLE `purchase_order_items` \((.*?)\) ENGINE=/s', $sources['database/grocery_pos_schema.sql'], $poItemTable) === 1
        && strpos($poItemTable[1], '`inventory_request_id` int(11) DEFAULT NULL') !== false
        && strpos($poItemTable[1], 'UNIQUE KEY `uq_poi_inventory_request`') !== false,
    'Finance can navigate to finance-approved report surface' =>
        strpos($sources['includes/sidebar.php'], 'Inventory & Financial Reports') !== false,
];

$failures = [];
foreach ($checks as $label => $passed) {
    if (!$passed) {
        $failures[] = $label;
    }
}
if ($failures) {
    fwrite(STDERR, "Procurement workflow contract failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Procurement workflow contract passed.\n";
