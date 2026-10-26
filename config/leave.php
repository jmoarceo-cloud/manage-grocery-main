<?php

return [
    // Existing application policy, not statutory entitlement calculations.
    'types' => ['sick' => 'Sick leave', 'vacation' => 'Vacation leave', 'emergency' => 'Emergency leave', 'others' => 'Other leave'],
    'allocations' => ['sick' => 15, 'vacation' => 15, 'emergency' => 5],
    'vacation_period_gap_days' => 1,
    'vacation_filing_cooldown_days' => 30,
];
