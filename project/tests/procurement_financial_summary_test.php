<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/procurement_financial_summary.php';

function assertQuantity(int $expected, int $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "{$label}: expected {$expected}, got {$actual}\n");
        exit(1);
    }
}

function assertMoney(float $expected, float $actual, string $label): void
{
    if (abs($expected - $actual) > 0.001) {
        fwrite(STDERR, "{$label}: expected {$expected}, got {$actual}\n");
        exit(1);
    }
}

$summary = composeProcurementFinancialSummary(
    [
        'revenue' => 1000.00,
        'cogs' => 300.00,
        'payroll_expense' => 200.00,
        'operating_expense' => 100.00,
        'net_income' => 400.00,
        'unknown_cost_qty' => 3,
    ],
    [
        'cash_from_sales' => 1000.00,
        'cash_paid_direct_restock' => 50.00,
        'cash_paid_supplier_procurement' => 250.00,
        'cash_paid_restock' => 300.00,
        'cash_paid_payroll' => 150.00,
        'cash_paid_operating_expenses' => 60.00,
        'capital_in' => 500.00,
        'capital_out' => 100.00,
        'net_investing' => 0.00,
        'current_cash_position' => 2500.00,
    ]
);

assertMoney(1000.00, $summary['recognized_income']['sales_revenue'], 'recognized revenue');
assertMoney(600.00, $summary['recognized_expenses']['total'], 'accrual expenses');
assertMoney(400.00, $summary['net_income'], 'accrual net income');
assertMoney(300.00, $summary['actual_outflows']['total_restocking'], 'restocking cash');
assertMoney(250.00, $summary['actual_outflows']['supplier_payments_for_procurement'], 'supplier procurement payments');
assertMoney(510.00, $summary['actual_outflows']['total'], 'actual operating cash outflows');
assertMoney(490.00, $summary['net_operating_cash'], 'net operating cash flow');
assertMoney(890.00, $summary['net_change_in_cash'], 'net cash change including financing');
assertQuantity(3, $summary['uncosted_sales_quantity'], 'disclosed units without tracked cost');
assertMoney(3.69, procurementRequestLineEstimateFromUnitCost(1.234, 3), 'request total rounds unit cost before multiplying');
assertMoney(
    3.69,
    round(round(procurementRequestLineEstimateFromUnitCost(1.234, 3) / 3, 2) * 3, 2),
    'PO line subtotal preserves the approved request estimate'
);

if ($summary['recognized_expenses']['total'] === $summary['actual_outflows']['total']) {
    fwrite(STDERR, "Accrued expenses and cash outflows must remain separate measures.\n");
    exit(1);
}

echo "procurement_financial_summary_test: OK (accruals, cash outflows, and financing are separate).\n";
