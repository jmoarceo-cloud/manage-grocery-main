<?php

namespace Tests\Unit;

use App\Support\OvertimeEvidence;
use PHPUnit\Framework\TestCase;

class OvertimeEvidenceTest extends TestCase
{
    public function test_recorded_excess_excludes_early_arrival_and_keeps_requested_hours_separate(): void
    {
        $result = OvertimeEvidence::describe((object) ['time_in' => '07:00:00', 'time_out' => '18:00:00'], (object) ['start_time' => '08:00:00', 'end_time' => '16:00:00'], 'full_time');
        $this->assertSame('10.00', $result['worked']);
        $this->assertSame('8.00', $result['expected']);
        $this->assertSame('2.00', $result['excess']);
        $this->assertArrayNotHasKey('hours_requested', $result);
    }

    public function test_overnight_shift_and_clock_out_use_the_existing_next_day_rules(): void
    {
        $result = OvertimeEvidence::describe((object) ['time_in' => '21:30:00', 'time_out' => '08:00:00'], (object) ['start_time' => '22:00:00', 'end_time' => '06:00:00'], 'full_time');
        $this->assertSame('10.00', $result['worked']);
        $this->assertSame('8.00', $result['expected']);
        $this->assertSame('2.00', $result['excess']);
        $equal = OvertimeEvidence::describe((object) ['time_in' => '08:00:00', 'time_out' => '08:00:00'], (object) ['start_time' => '08:00:00', 'end_time' => '08:00:00'], 'full_time');
        $this->assertSame('24.00', $equal['expected']);
        $this->assertSame('0.00', $equal['worked']);
    }

    public function test_missing_shift_uses_existing_eight_or_six_hour_policy_with_eight_am_start(): void
    {
        $punch = (object) ['time_in' => '07:00:00', 'time_out' => '16:00:00'];
        $full = OvertimeEvidence::describe($punch, null, 'full_time');
        $part = OvertimeEvidence::describe($punch, null, 'part_time');
        $this->assertSame('8.00', $full['worked']);
        $this->assertSame('0.00', $full['excess']);
        $this->assertSame('6.00', $part['expected']);
        $this->assertSame('2.00', $part['excess']);
    }

    public function test_missing_and_invalid_punches_do_not_become_a_known_zero_or_payable_hours(): void
    {
        foreach ([null, (object) ['time_in' => '08:00:00', 'time_out' => null]] as $punch) {
            $result = OvertimeEvidence::describe($punch, null, 'full_time');
            $this->assertSame('missing', $result['state']);
            $this->assertNull($result['excess']);
        }
        foreach (['25:00:00', '-01:00:00', '08:61:00', 'invalid'] as $time) {
            $result = OvertimeEvidence::describe((object) ['time_in' => $time, 'time_out' => '18:00:00'], null, 'full_time');
            $this->assertSame('invalid', $result['state']);
            $this->assertNull($result['excess']);
        }
    }

    public function test_second_precision_preserves_legacy_expected_shift_rounding_before_excess(): void
    {
        $result = OvertimeEvidence::describe((object) ['time_in' => '08:00:00', 'time_out' => '16:00:30'], (object) ['start_time' => '08:00:00', 'end_time' => '16:00:15'], 'full_time');
        $this->assertSame('8.00', $result['expected']);
        $this->assertSame('8.01', $result['worked']);
        $this->assertSame('0.01', $result['excess']);
    }
}
