<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This contract test must run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
$failures = [];

function checkFinanceFlow(bool $condition, string $message): void
{
    global $failures;
    if (!$condition)
        $failures[] = $message;
}

function financeSource(string $file): string
{
    global $root;
    $contents = file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file));
    if ($contents === false)
        throw new RuntimeException("Unable to read {$file}");
    return $contents;
}

function financeFunction(string $source, string $name): string
{
    $quotedName = preg_quote($name, '/');
    if (!preg_match('/function\s+' . $quotedName . '\s*\([^)]*\)\s*\{.*?(?=\nfunction\s|\z)/s', $source, $match)) {
        throw new RuntimeException("Unable to find function {$name}");
    }
    return $match[0];
}

$config = financeSource('includes/config.php');
$operating = financeSource('operating_expenses.php');
$payables = financeSource('accounts_payable.php');
$finance = financeSource('finance.php');
$export = financeSource('includes/export.php');
$financeDashboard = financeSource('finance_dashboard.php');
$financialAnalysis = financeSource('financial_analysis.php');
$categories = financeSource('expense_categories.php');
$sidebar = financeSource('includes/sidebar.php');

eval(financeFunction($config, 'getComparableFinancialPeriod'));
$comparisonCases = [
    ['2026-10-01', '2026-10-01', ['from' => '2026-09-01', 'to' => '2026-09-01']],
    ['2026-10-01', '2026-10-15', ['from' => '2026-09-01', 'to' => '2026-09-15']],
    ['2026-10-01', '2026-10-31', ['from' => '2026-09-01', 'to' => '2026-09-30']],
    ['2026-10-10', '2026-10-16', ['from' => '2026-10-03', 'to' => '2026-10-09']],
];
foreach ($comparisonCases as [$from, $to, $expected]) {
    checkFinanceFlow(
        getComparableFinancialPeriod($from, $to) === $expected,
        "Comparable financial range for {$from} to {$to} must resolve correctly."
    );
}
try {
    getComparableFinancialPeriod('2026-10-10', '2026-10-09');
    checkFinanceFlow(false, 'Comparable financial ranges must reject reversed dates.');
} catch (InvalidArgumentException $e) {
    checkFinanceFlow(true, 'Comparable financial ranges reject reversed dates.');
}

checkFinanceFlow(strpos(financeFunction($config, 'requireOperatingExpenseManager'), "['admin', 'finance']") !== false, 'Operating expenses must remain Finance/Admin-only.');
checkFinanceFlow(strpos(financeFunction($config, 'requireAccountsPayableManager'), "['finance', 'admin']") !== false, 'Accounts Payable must remain Finance/Admin-only.');
checkFinanceFlow(strpos(financeFunction($config, 'requireExpenseCategoryManager'), "['admin', 'finance']") !== false, 'Expense categories must remain Finance/Admin-only.');
checkFinanceFlow(strpos(financeFunction($config, 'requireOperatingExpenseLevel2Approver'), "!== 'admin'") !== false, 'Level 2 approval must remain Admin-only.');

$verify = financeFunction($config, 'verifyOperatingExpense');
checkFinanceFlow(strpos($verify, 'SELECT * FROM operating_expenses WHERE id = ? FOR UPDATE') !== false, 'Bill verification must lock its bill during the transaction.');
checkFinanceFlow(strpos($verify, 'finalizeApprovedOperatingExpense') !== false, 'Level 1 verification must use the shared approval/posting workflow.');
checkFinanceFlow(substr_count($verify, '$pdo->commit();') === 1, 'Bill verification must commit exactly once.');
checkFinanceFlow(strpos($verify, 'getOperatingExpenseLevel2Threshold($pdo)') < strpos($verify, 'beginTransaction()'), 'Verification settings/schema checks must run before the transaction.');
$finalize = financeFunction($config, 'finalizeApprovedOperatingExpense');
$finalizeNormalized = preg_replace('/\s+/', ' ', $finalize);
$verifyNormalized = preg_replace('/\s+/', ' ', $verify);
$level2ApproveNormalized = preg_replace('/\s+/', ' ', financeFunction($config, 'level2ApproveOperatingExpense'));
$recordAccountsPayableNormalized = preg_replace('/\s+/', ' ', financeFunction($config, 'recordAccountsPayable'));
checkFinanceFlow(
    preg_match('/if\s*\(\s*!recordAccountsPayable\s*\(/', $finalize) === 1
    && preg_match('/if\s*\(\s*!\$result\[\'ok\'\]\s*\)\s*\{\s*\$pdo->rollBack\(\);\s*return \$result;\s*\}/', $verifyNormalized) === 1
    && preg_match('/if\s*\(\s*!\$result\[\'ok\'\]\s*\)\s*\{\s*\$pdo->rollBack\(\);\s*return \$result;\s*\}/', $level2ApproveNormalized) === 1,
    'Approval must fail and roll back if Accounts Payable cannot be posted.'
);
checkFinanceFlow(strpos($operating, 'ensureOperatingExpenseTables($pdo);') < strpos($operating, '$_SERVER[\'REQUEST_METHOD\'] === \'POST\''), 'Operating-expense schema checks must run before approval transactions.');
checkFinanceFlow(
    preg_match('/if\s*\(\s*!\$pdo->inTransaction\(\)\s*\|\|\s*!\$schemaReadyInTransaction\)\s*ensureAccountsPayableTables\(\$pdo\);/', $recordAccountsPayableNormalized) === 1,
    'Accounts Payable DDL must not run inside prepared approval transactions.'
);
checkFinanceFlow(
    strpos($finalizeNormalized, '\'operating_expense\', $oe[\'id\']') !== false
    && strpos($finalizeNormalized, '$oe[\'due_date\'], true') !== false,
    'Only operating-expense approval may skip redundant Accounts Payable DDL.'
);

$payment = financeFunction($config, 'recordSupplierPayment');
$supplierInvoice = financeFunction($config, 'recordSupplierInvoice');
$purchaseOrderReceipt = financeFunction($config, 'recordPurchaseOrderReceipt');
$poCommitments = financeFunction($config, 'getUnreceivedPurchaseOrderCommitments');
checkFinanceFlow(
    strpos($config, 'supplier_invoice_number VARCHAR(100)') !== false
    && strpos($supplierInvoice, 'SELECT ap.*, s.payment_terms_days') !== false
    && strpos($supplierInvoice, 'supplier_invoice_amount = ?') !== false
    && strpos($supplierInvoice, 'LOWER(TRIM(supplier_invoice_number))') !== false
    && strpos($supplierInvoice, 'payment_terms_days') !== false,
    'Finance must be able to record unique supplier invoice details and set the payable amount and due date from the invoice.'
);
checkFinanceFlow(
    strpos($payment, "\$ap['source_type'] === 'purchase_order' && empty(\$ap['supplier_invoice_number'])") !== false
    && strpos($payment, 'Record and verify the supplier invoice') !== false,
    'Purchase-order payments must be blocked until Finance records the supplier invoice.'
);
checkFinanceFlow(
    strpos($payables, 'Received Items Awaiting Supplier Invoice') !== false
    && strpos($payables, 'name="action" value="record_supplier_invoice"') !== false
    && strpos($payables, 'name="supplier_invoice_number"') !== false
    && strpos($payables, 'Record invoice first') !== false,
    'Accounts Payable must show received purchase orders awaiting invoice entry and withhold their payment action.'
);
checkFinanceFlow(
    strpos($purchaseOrderReceipt, '$pdo->commit();') < strpos($purchaseOrderReceipt, 'pushRoleNotification(')
    && strpos($purchaseOrderReceipt, "'supplier_invoice_required'") !== false
    && strpos($purchaseOrderReceipt, 'recordPurchaseOrderReceipt notification error:') !== false,
    'A successful receipt must notify Finance after commit without turning notification failure into a false receiving failure.'
);
checkFinanceFlow(strpos($payment, 'beginTransaction()') < strpos($payment, 'SELECT * FROM accounts_payable WHERE id = ? FOR UPDATE'), 'Payment recording must lock the payable inside a transaction.');
checkFinanceFlow(strpos($payment, 'UPDATE accounts_payable SET amount_paid = ?, status = ? WHERE id = ?') !== false, 'Payment recording must update the payable balance and status.');
checkFinanceFlow(
    strpos($payment, 'getBusinessCashPosition($pdo, $hrmsPdo)') !== false
    && strpos($payment, "empty(\$cashPosition['payroll_data_available'])") !== false
    && strpos($payment, '$amount > max(0, $availableCash)') !== false
    && strpos($payment, 'No payment was recorded; the unpaid balance remains a liability.') !== false
    && strpos($payment, 'getBusinessCashPosition($pdo, $hrmsPdo)') < strpos($payment, 'beginTransaction()'),
    'Accounts Payable disbursements must require verified cash, reject overspending, and preserve the unpaid liability.'
);
checkFinanceFlow(strpos($payment, 'notification error') !== false, 'Notification failures must not report a committed payment as failed.');
$cashPositionFunction = financeFunction($config, 'getBusinessCashPosition');
checkFinanceFlow(
    strpos($cashPositionFunction, '$profit[\'revenue\'] - $totalRestockCost - $operatingExpensePaid + $capitalIn - $capitalOut - $payrollReleased') !== false
    && strpos($cashPositionFunction, "'operating_expenses_paid' => \$operatingExpensePaid") !== false
    && strpos($cashPositionFunction, "'sales_revenue' => \$profit['revenue']") !== false
    && strpos($cashPositionFunction, "'payroll_data_available' => \$payrollDataAvailable") !== false
    && strpos($cashPositionFunction, "'cash_shortfall' => round(max(0, -\$availableCash), 2)") !== false
    && strpos(financeFunction($config, 'getTotalOperatingExpensePaid'), "WHERE source_type = 'operating_expense'") !== false
    && strpos(financeFunction($config, 'getTotalRestockCost'), 'SUM(amount_paid)') !== false
    && strpos($cashPositionFunction, "payment_status = 'paid'") !== false,
    'The cash position must include paid operating expenses and disclose payroll-data availability and negative cash shortfalls.'
);
checkFinanceFlow(
    strpos($payables, 'the remaining payable stays recorded as a liability') !== false
    && strpos($payables, 'Available Business Cash') !== false
    && strpos($payables, 'Math.min(balance, Number(availableCash))') !== false
    && strpos($poCommitments, "WHERE po.status IN ('approved', 'ordered', 'partially_received')") !== false
    && strpos($poCommitments, 'GREATEST(poi.quantity_ordered - poi.quantity_received, 0) * poi.unit_cost') !== false
    && strpos($payables, 'not yet Accounts Payable liabilities') !== false
    && strpos($payables, 'purchase_orders.php?po=') !== false,
    'Accounts Payable must show approved PO commitments separately from payable liabilities until receipt.'
);
checkFinanceFlow(
    strpos($finance, 'Operating Expenses Paid (all-time)') !== false
    && strpos($finance, 'id="business-capital"') !== false
    && strpos($financeDashboard, "number_format((float) \$cash_position['available_cash'], 2)") !== false
    && strpos($financeDashboard, "\$cash_position['cash_position'] ?? 0") === false
    && strpos($financeDashboard, 'accounts_payable.php#po-commitments') !== false,
    'Finance pages and exports must expose paid operating expenses, anchor Add Capital, report actual cash, and link PO commitments to Accounts Payable.'
);
checkFinanceFlow(
    strpos(financeSource('includes/payroll_release.php'), "empty(\$cash['payroll_data_available'])") !== false,
    'Payroll release must stop if Finance cannot verify cash against HRMS payroll history.'
);
checkFinanceFlow(strpos($payables, "name=\"action\" value=\"record_payment\"") !== false, 'Accounts Payable form must submit the Record Payment action.');
checkFinanceFlow(
    strpos($payables, 'type="button" class="action-btn action-btn--small ap-record-payment"') !== false
    && strpos($payables, "event.target.closest('.ap-record-payment')") !== false
    && strpos($payables, "modal.classList.add('open')") !== false,
    'Record Payment buttons must open the payment modal through the delegated click handler.'
);
checkFinanceFlow(
    strpos($payables, 'data-close-payment') !== false
    && strpos($payables, 'z-index: 10000') !== false,
    'The payment modal must have a working close action and remain visible above page content.'
);

$upsert = financeFunction($config, 'upsertExpenseCategory');
$delete = financeFunction($config, 'deactivateExpenseCategory');
checkFinanceFlow(strpos($upsert, 'beginTransaction()') !== false && strpos($upsert, 'commit()') !== false, 'Category create/edit must be transactional.');
checkFinanceFlow(strpos($delete, "status = 'inactive'") !== false && strpos($delete, 'beginTransaction()') !== false, 'Category delete must safely soft-delete transactionally.');
checkFinanceFlow(strpos($categories, 'action=edit&id=') !== false, 'Category rows must retain an Edit action.');
checkFinanceFlow(strpos($categories, 'name="action" value="delete_category"') !== false, 'Category rows must provide a Delete action.');
checkFinanceFlow(strpos($categories, 'Existing bills and reports keep their history.') !== false, 'Category delete must explain that bill history is retained.');
checkFinanceFlow(strpos($sidebar, "finance.php#payroll-approval") !== false, 'Finance navigation must expose the Payroll module.');

$incomeStatement = financeFunction($config, 'getIncomeStatement');
$expenseBreakdown = financeFunction($config, 'getExpenseBreakdown');
$payrollBreakdown = financeFunction($config, 'getPayrollBreakdown');
checkFinanceFlow(
    strpos($incomeStatement, "pay_period_end BETWEEN") !== false
    && strpos($incomeStatement, "WHERE status = 'approved'") !== false
    && strpos($incomeStatement, "'operating_expense_available' => \$operatingExpenseAvailable") !== false,
    'Accrual expense totals must use period-end payroll and approved operating bills and expose unavailable data sources.'
);
checkFinanceFlow(
    strpos($expenseBreakdown, "'cash_paid_restock'") !== false
    && strpos($expenseBreakdown, "'cash_paid_payroll'") !== false
    && strpos($expenseBreakdown, "'cash_paid_operating_expenses'") !== false
    && strpos($expenseBreakdown, "'cash_outflows_available'") !== false
    && strpos($expenseBreakdown, 'Owner Withdrawal (Capital Out)') === false,
    'Cash expense breakdown must include inventory, payroll, and bill payments while excluding owner withdrawals.'
);
checkFinanceFlow(
    strpos($payrollBreakdown, "DATE(pr.paid_at) BETWEEN") !== false
    && strpos($payrollBreakdown, '$cashPaidTotal += $netPaid') !== false
    && strpos($payrollBreakdown, 'cash_paid_by_department') !== false
    && strpos($payrollBreakdown, 'cash_paid_rows') !== false
    && strpos($payrollBreakdown, 'pr.net_pay > 0') !== false
    && strpos($payrollBreakdown, 'LEFT JOIN employees') !== false,
    'Cash payroll must list positive actual payments by payment date and department without dropping unmatched employee records.'
);
checkFinanceFlow(
    strpos($financialAnalysis, 'Cash Paid Out (Inventory + Payroll + Bills)') !== false
    && strpos($financialAnalysis, 'Accrual Expenses (Inventory Cost + Payroll + Bills)') !== false
    && strpos($financialAnalysis, 'Payroll Payments Made') !== false
    && strpos($financialAnalysis, 'Payroll Earned in Selected Month') !== false
    && strpos($financialAnalysis, 'view=payroll_paid&export=xlsx') !== false
    && strpos($financialAnalysis, 'Payment data is incomplete') !== false,
    'Monthly finance report must distinguish payroll earned from actual payroll payments, provide paid details/export, and disclose unavailable data.'
);
checkFinanceFlow(
    strpos($financialAnalysis, "'Total Accrual Expenses'") !== false
    && strpos($financialAnalysis, "'Partial data (known: '") !== false,
    'Excel and PDF financial summaries must include total accrual expenses and clearly mark partial totals.'
);
checkFinanceFlow(
    strpos($finance, 'class="panel finance-po-panel"') !== false
    && strpos($finance, 'finance-po-approval-card') !== false
    && strpos($finance, 'finance-po-has-evidence') !== false
    && strpos($finance, 'finance-po-signature') !== false
    && strpos($finance, 'finance-po-approve-form') !== false
    && strpos($finance, '.finance-po-actions') !== false
    && strpos($finance, '@media (max-width: 560px)') !== false,
    'Finance PO approval cards must present evidence and actions in a polished desktop and mobile layout.'
);
checkFinanceFlow(
    strpos($financialAnalysis, 'Known-cost Net Income') !== false
    && strpos($financialAnalysis, 'Monthly Income (Sales Revenue)') !== false
    && strpos($financialAnalysis, "'Month', 'Income', 'Supplier Purchases', 'COGS'") !== false
    && strpos($financialAnalysis, 'Supplier Purchases Received') !== false
    && strpos($financialAnalysis, "'Supplier Purchases Received (inventory; not yet an expense)'") !== false
    && strpos($financialAnalysis, "<th>Monthly Income</th>") !== false
    && strpos($financialAnalysis, "label: 'Monthly Income'") !== false
    && strpos($financialAnalysis, "label: 'Supplier Purchases Received (Inventory)'") !== false
    && strpos($financialAnalysis, '$__meta  = [];') !== false
    && strpos($financialAnalysis, 'Profit Remaining (Net Income)') !== false
    && strpos($financialAnalysis, '$netIncomeAvailable = $accrualTotalsComplete') !== false
    && strpos($financialAnalysis, "'N/A'") !== false
    && strpos($financialAnalysis, 'unknown_cost_qty') !== false
    && strpos($financialAnalysis, 'Expense data incomplete') !== false,
    'Financial KPIs, trends, and exports must disclose incomplete inventory costing rather than report misleading margins or expense totals.'
);
checkFinanceFlow(
    strpos($config, 'function getSupplierPurchasesReceived') !== false
    && strpos($config, "source_type IN ('purchase_order', 'inventory_request')") !== false
    && strpos($config, 'DATE(created_at) BETWEEN ? AND ?') !== false
    && strpos($config, "'supplier_purchases_received' => \$supplierPurchases['amount']") !== false
    && strpos($financialAnalysis, 'not deducted from net income a second time') !== false,
    'Monthly analysis must include supplier purchases by receipt date without double-counting unsold inventory as profit expense.'
);
checkFinanceFlow(
    strpos($export, '$headerHeight = max(8, $maxLines * $lineHeight + 3)') !== false
    && strpos($export, '$this->MultiCell($width - 2, $lineHeight, $text, 0, \'C\')') !== false,
    'PDF export headers must wrap within their columns instead of clipping or overlapping.'
);

if ($failures) {
    foreach ($failures as $failure)
        fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "finance_admin_workflows_contract: OK\n";
