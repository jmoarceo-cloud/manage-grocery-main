<?php

declare(strict_types=1);

namespace App\Services;

class PayrollCalculationService
{
    /**
     * Compute statutory Philippine deductions for a monthly basic salary.
     *
     * @return array{
     *     basic_salary: float,
     *     sss_employee: float,
     *     philhealth_employee: float,
     *     pagibig_employee: float,
     *     total_deductions: float,
     *     taxable_income: float,
     *     withholding_tax: float,
     *     net_pay: float
     * }
     */
    public static function computeMonthlyDeductions(float $basicSalary): array
    {
        // SSS Contribution (2024/2026 table approximation: 4.5% employee share capped at MSC of 30,000)
        $msc = min(max($basicSalary, 4000.0), 30000.0);
        $sssEmployee = round($msc * 0.045, 2);

        // PhilHealth Contribution (5% total, 2.5% employee share capped between 10k and 100k)
        $philhealthBase = min(max($basicSalary, 10000.0), 100000.0);
        $philhealthEmployee = round($philhealthBase * 0.025, 2);

        // Pag-IBIG Contribution (standard statutory cap of 200.00 PHP monthly employee share)
        $pagibigEmployee = 200.00;

        $mandatoryDeductions = $sssEmployee + $philhealthEmployee + $pagibigEmployee;
        $taxableIncome = max(0.0, $basicSalary - $mandatoryDeductions);

        // Philippine TRAIN Law Withholding Tax Monthly Bracket
        $tax = 0.0;
        if ($taxableIncome > 666667.0) {
            $tax = 183541.80 + (($taxableIncome - 666667.0) * 0.35);
        } elseif ($taxableIncome > 166667.0) {
            $tax = 33541.80 + (($taxableIncome - 166667.0) * 0.30);
        } elseif ($taxableIncome > 66667.0) {
            $tax = 8541.80 + (($taxableIncome - 66667.0) * 0.25);
        } elseif ($taxableIncome > 33333.0) {
            $tax = 1875.00 + (($taxableIncome - 33333.0) * 0.20);
        } elseif ($taxableIncome > 20833.0) {
            $tax = ($taxableIncome - 20833.0) * 0.15;
        }

        $totalDeductions = $mandatoryDeductions + $tax;
        $netPay = max(0.0, $basicSalary - $totalDeductions);

        return [
            'basic_salary' => $basicSalary,
            'sss_employee' => $sssEmployee,
            'philhealth_employee' => $philhealthEmployee,
            'pagibig_employee' => $pagibigEmployee,
            'total_deductions' => round($totalDeductions, 2),
            'taxable_income' => round($taxableIncome, 2),
            'withholding_tax' => round($tax, 2),
            'net_pay' => round($netPay, 2),
        ];
    }
}
