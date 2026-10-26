<?php

declare(strict_types=1);

function procurementRequestLineEstimateFromUnitCost(float $unitCost, int $quantity): float
{
    if ($quantity <= 0 || !is_finite($unitCost) || $unitCost < 0) {
        throw new InvalidArgumentException('A request line requires a non-negative unit cost and positive quantity.');
    }
    return round(round($unitCost, 2) * $quantity, 2);
}

function composeProcurementFinancialSummary(array $income, array $cashFlow): array
{
    $revenue = (float) ($income['revenue'] ?? 0);
    $cogs = (float) ($income['cogs'] ?? 0);
    $payrollAccrued = (float) ($income['payroll_expense'] ?? 0);
    $billExpenseAccrued = (float) ($income['operating_expense'] ?? 0);
    $directRestock = (float) ($cashFlow['cash_paid_direct_restock'] ?? 0);
    $supplierProcurement = (float) ($cashFlow['cash_paid_supplier_procurement'] ?? 0);
    $payrollPaid = (float) ($cashFlow['cash_paid_payroll'] ?? 0);
    $billsPaid = (float) ($cashFlow['cash_paid_operating_expenses'] ?? 0);
    $restockPaid = (float) ($cashFlow['cash_paid_restock'] ?? ($directRestock + $supplierProcurement));
    $cashSales = (float) ($cashFlow['cash_from_sales'] ?? $revenue);
    $totalCashOutflows = $restockPaid + $payrollPaid + $billsPaid;
    $netOperatingCash = $cashSales - $totalCashOutflows;
    $capitalIn = (float) ($cashFlow['capital_in'] ?? 0);
    $capitalOut = (float) ($cashFlow['capital_out'] ?? 0);
    $netInvesting = (float) ($cashFlow['net_investing'] ?? 0);

    return [
        'recognized_income' => [
            'sales_revenue' => $revenue,
        ],
        'recognized_expenses' => [
            'cogs' => $cogs,
            'payroll_accrued' => $payrollAccrued,
            'approved_operating_bills' => $billExpenseAccrued,
            'total' => $cogs + $payrollAccrued + $billExpenseAccrued,
        ],
        'uncosted_sales_quantity' => (int) ($income['unknown_cost_qty'] ?? 0),
        'net_income' => (float) ($income['net_income'] ?? ($revenue - $cogs - $payrollAccrued - $billExpenseAccrued)),
        'actual_outflows' => [
            'direct_cash_restock' => $directRestock,
            'supplier_payments_for_procurement' => $supplierProcurement,
            'total_restocking' => $restockPaid,
            'payroll_disbursed' => $payrollPaid,
            'operating_bills_paid' => $billsPaid,
            'total' => $totalCashOutflows,
        ],
        'cash_from_sales' => $cashSales,
        'net_operating_cash' => $netOperatingCash,
        'capital_in' => $capitalIn,
        'capital_out' => $capitalOut,
        'net_investing' => $netInvesting,
        'net_change_in_cash' => $netOperatingCash + $netInvesting + $capitalIn - $capitalOut,
        'current_cash_position' => (float) ($cashFlow['current_cash_position'] ?? 0),
    ];
}
