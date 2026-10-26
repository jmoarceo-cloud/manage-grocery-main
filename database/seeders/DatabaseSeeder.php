<?php

namespace Database\Seeders;

use App\Support\IsolatedDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        IsolatedDatabase::verify();
        $db = DB::connection('pos');
        foreach (['admin' => 'admin', 'cashier' => 'cashier', 'inventory_staff' => 'inventory_staff', 'finance' => 'finance', 'information_editor' => 'inventory_staff', 'information_admin' => 'admin', 'mfa_pos_ui' => 'cashier'] as $fixture => $role) {
            $db->table('users')->updateOrInsert(['username' => 'demo_'.$fixture], ['full_name' => 'Synthetic '.ucwords(str_replace('_', ' ', $fixture)), 'password' => Hash::make('Synthetic-demo-only!123'), 'role' => $role, 'is_active' => 1, 'two_factor_enabled' => 1, 'two_factor_email' => $fixture.'@example.invalid', 'two_factor_backup_codes' => json_encode([Hash::make('SYNTHETIC-RECOVERY'), Hash::make('SYNTHETIC-SECOND-RECOVERY')])]);
        }
        $hr = DB::connection('hrms');
        $hr->table('users')->updateOrInsert(['username' => 'demo_mfa_hr_ui'], ['employee_id' => null, 'full_name' => 'Synthetic MFA HR UI', 'password' => Hash::make('Synthetic-demo-only!123'), 'role' => 'hr', 'is_active' => 1, 'two_factor_enabled' => 1, 'two_factor_email' => 'mfa_hr_ui@example.invalid', 'two_factor_backup_codes' => json_encode([Hash::make('SYNTHETIC-UI-RECOVERY')])]);
        $hr->table('employees')->updateOrInsert(['employee_id' => 900001], ['employee_code' => 'SYN-PROFILE', 'first_name' => 'Synthetic', 'last_name' => 'Profile', 'department' => 'Synthetic Store', 'position' => 'Cashier', 'date_hired' => '2020-01-01', 'basic_salary' => 0, 'status' => 'active', 'email' => 'profile@example.invalid', 'contact_number' => '+1 2025550100', 'birthdate' => '1995-01-01', 'address' => '1 Synthetic Lane', 'sss_number' => 'SYN-HIDDEN-SSS', 'bank_account_number' => 'SYN-HIDDEN-BANK']);
        $db->table('users')->updateOrInsert(['username' => 'demo_profile_staff'], ['employee_id' => 900001, 'full_name' => 'Synthetic Profile Staff', 'password' => Hash::make('Synthetic-demo-only!123'), 'role' => 'cashier', 'is_active' => 1, 'two_factor_enabled' => 1, 'two_factor_email' => 'profile_staff@example.invalid', 'two_factor_backup_codes' => json_encode([Hash::make('SYNTHETIC-RECOVERY'), Hash::make('SYNTHETIC-SECOND-RECOVERY')])]);
        $hr->table('users')->updateOrInsert(['username' => 'demo_leave_reviewer'], ['employee_id' => null, 'full_name' => 'Synthetic HR Reviewer', 'password' => Hash::make('Synthetic-demo-only!123'), 'role' => 'hr', 'is_active' => 1, 'two_factor_enabled' => 1, 'two_factor_email' => 'leave_reviewer@example.invalid', 'two_factor_backup_codes' => json_encode([Hash::make('SYNTHETIC-RECOVERY')])]);
        $hr->table('shifts')->updateOrInsert(['shift_id' => 900001], ['shift_name' => 'Synthetic opening', 'start_time' => '08:00:00', 'end_time' => '16:00:00', 'is_active' => 1]);
        $hr->table('shifts')->updateOrInsert(['shift_id' => 900002], ['shift_name' => 'Synthetic overnight', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'is_active' => 1]);
        $today = CarbonImmutable::today('Asia/Manila');
        foreach (range(0, 6) as $day) {
            $shift = $day === $today->dayOfWeek ? 900002 : (in_array($day, [0, 6, $today->addDay()->dayOfWeek], true) ? null : 900001);
            $hr->table('employee_weekly_shifts')->updateOrInsert(['employee_id' => 900001, 'day_of_week' => $day], ['shift_id' => $shift]);
        }
        $hr->table('shift_swaps')->updateOrInsert(['covering_employee_id' => 900001, 'swap_date' => $today->addDay()->toDateString()], ['shift_id' => 900001, 'status' => 'approved', 'reason' => 'SYN-PRIVATE-COVER-REASON']);
        foreach ([900002 => ['SYN-OVERTIME', 'Overtime', 'Cashier'], 900003 => ['SYN-OT-SUPERVISOR', 'Supervisor', 'Supervisor']] as $employeeId => [$code, $name, $position]) {
            $hr->table('employees')->updateOrInsert(['employee_id' => $employeeId], ['employee_code' => $code, 'first_name' => 'Synthetic', 'last_name' => $name, 'position' => $position, 'date_hired' => '2020-01-01', 'basic_salary' => 0, 'status' => 'active', 'schedule_type' => 'full_time', 'email' => strtolower($name).'@example.invalid', 'sss_number' => 'SYN-HIDDEN-SSS', 'bank_account_number' => 'SYN-HIDDEN-BANK']);
        }
        $db->table('users')->updateOrInsert(['username' => 'demo_overtime_staff'], ['employee_id' => 900002, 'full_name' => 'Synthetic Overtime Staff', 'password' => Hash::make('Synthetic-demo-only!123'), 'role' => 'cashier', 'is_active' => 1, 'two_factor_enabled' => 1, 'two_factor_email' => 'overtime_staff@example.invalid', 'two_factor_backup_codes' => json_encode([Hash::make('SYNTHETIC-RECOVERY'), Hash::make('SYNTHETIC-SECOND-RECOVERY')])]);
        $hr->table('users')->updateOrInsert(['username' => 'demo_overtime_supervisor'], ['employee_id' => 900003, 'full_name' => 'Synthetic Overtime Supervisor', 'password' => Hash::make('Synthetic-demo-only!123'), 'role' => 'supervisor', 'is_active' => 1, 'two_factor_enabled' => 1, 'two_factor_email' => 'overtime_supervisor@example.invalid', 'two_factor_backup_codes' => json_encode([Hash::make('SYNTHETIC-RECOVERY')])]);
        $hr->table('supervisor_teams')->updateOrInsert(['member_employee_id' => 900002], ['supervisor_employee_id' => 900003]);
        $hr->table('employee_weekly_shifts')->updateOrInsert(['employee_id' => 900002, 'day_of_week' => $today->dayOfWeek], ['shift_id' => 900001]);
        $hr->table('attendance')->updateOrInsert(['employee_id' => 900002, 'date' => $today->toDateString()], ['time_in' => '07:00:00', 'time_out' => '18:00:00', 'status' => 'present', 'time_in_latitude' => '14.1234567', 'time_in_longitude' => '120.1234567', 'time_in_address' => 'SYN-PRIVATE-GPS-ADDRESS']);
        $db->table('categories')->updateOrInsert(['category_name' => 'Synthetic essentials']);
        $db->table('products')->updateOrInsert(['barcode' => '4006381333931'], ['product_name' => 'Synthetic label sample', 'category' => 'Synthetic essentials', 'price' => '12.50', 'cost_price' => '5.00', 'stock' => 0, 'is_active' => 1]);
        if (! $db->table('products')->where('barcode', 'SYNTHETIC-DEMO-001')->exists()) {
            $id = $db->table('products')->insertGetId(['product_name' => 'Synthetic pantry item', 'barcode' => 'SYNTHETIC-DEMO-001', 'category' => 'Synthetic essentials', 'price' => '42.50', 'cost_price' => '20.00', 'stock' => 30, 'is_active' => 1]);
            $db->table('product_batches')->insert(['product_id' => $id, 'quantity_received' => 30, 'quantity_remaining' => 30, 'cost_price' => '20.00', 'expiry_date' => now()->addMonths(3)->toDateString()]);
        }
        foreach (['4006381333931' => ['calories' => '140.00', 'protein_g' => null, 'carbs_g' => '22.00', 'fat_g' => '5.00', 'sugar_g' => '3.00', 'sodium_mg' => '60.00'], 'SYNTHETIC-DEMO-001' => ['calories' => '120.00', 'protein_g' => '3.00', 'carbs_g' => '20.00', 'fat_g' => '4.00', 'sugar_g' => '0.00', 'sodium_mg' => '80.00']] as $barcode => $nutrition) {
            $id = $db->table('products')->where('barcode', $barcode)->value('id');
            $db->table('product_details')->updateOrInsert(['product_id' => $id], $nutrition + ['description' => 'Synthetic verified product description.', 'ingredients' => 'Synthetic demonstration ingredients.', 'allergens' => 'Synthetic allergen information.', 'brand' => 'Synthetic brand', 'nutrition_basis' => 'per_100g', 'serving_size' => '100 g', 'nutrition_source' => 'manual', 'review_status' => 'verified', 'verified_at' => now()]);
        }
    }
}
