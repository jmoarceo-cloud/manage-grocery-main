<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PayrollCalculationService;
use PHPUnit\Framework\TestCase;

class PayrollCalculationServiceTest extends TestCase
{
    public function test_minimum_wage_salary_computations(): void
    {
        $basic = 15000.0; // 15,000 PHP monthly
        $result = PayrollCalculationService::computeMonthlyDeductions($basic);

        $this->assertEquals(15000.0, $result['basic_salary']);
        $this->assertGreaterThan(0.0, $result['sss_employee']);
        $this->assertGreaterThan(0.0, $result['philhealth_employee']);
        $this->assertEquals(200.0, $result['pagibig_employee']);
        $this->assertLessThan($basic, $result['total_deductions']);
        $this->assertEquals(0.0, $result['withholding_tax']); // Tax-exempt under TRAIN 250k annual threshold
        $this->assertGreaterThan(13000.0, $result['net_pay']);
    }

    public function test_manager_salary_taxable_bracket(): void
    {
        $basic = 50000.0; // 50,000 PHP monthly
        $result = PayrollCalculationService::computeMonthlyDeductions($basic);

        $this->assertGreaterThan(0.0, $result['withholding_tax']);
        $this->assertLessThan($basic, $result['net_pay']);
        $this->assertEquals(round($basic - $result['total_deductions'], 2), $result['net_pay']);
    }
}
