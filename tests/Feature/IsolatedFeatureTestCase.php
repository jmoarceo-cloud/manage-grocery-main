<?php

namespace Tests\Feature;

use App\Models\PosUser;
use App\Support\IsolatedDatabase;
use App\Support\StaffSessionVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class IsolatedFeatureTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        IsolatedDatabase::verify();
        foreach (['pos', 'hrms'] as $domain) {
            DB::connection($domain)->beginTransaction();
        }
    }

    protected function tearDown(): void
    {
        foreach (['pos', 'hrms'] as $domain) {
            while (DB::connection($domain)->transactionLevel() > 0) {
                DB::connection($domain)->rollBack();
            }
        }
        parent::tearDown();
    }

    protected function cashier(): PosUser
    {
        $id = DB::connection('pos')->table('users')->insertGetId(['username' => 'synthetic_cashier', 'full_name' => 'Synthetic Cashier', 'password' => Hash::make('Synthetic!Pass123'), 'role' => 'cashier', 'is_active' => 1, 'two_factor_enabled' => 1, 'two_factor_email' => 'cashier@example.invalid']);

        return PosUser::findOrFail($id);
    }

    protected function staffSession(PosUser $user): static
    {
        return $this->actingAs($user, 'pos')->withSession(['staff_domain' => 'pos', 'staff_mfa' => true, 'staff_password_signature' => hash('sha256', $user->password), 'staff_activity' => time(), 'staff_session_version' => StaffSessionVersion::current('pos', (int) $user->id)]);
    }

    protected function product(int $stock = 10, string $price = '25.00'): int
    {
        $db = DB::connection('pos');
        $id = $db->table('products')->insertGetId(['product_name' => 'Synthetic Product', 'barcode' => (string) Str::uuid(), 'price' => $price, 'stock' => $stock, 'is_active' => 1]);
        $db->table('product_batches')->insert(['product_id' => $id, 'quantity_received' => $stock, 'quantity_remaining' => $stock, 'expiry_date' => now()->addMonth()->toDateString()]);

        return $id;
    }

    protected function cart(int $productId): array
    {
        return ['request_key' => (string) Str::uuid(), 'items' => [['id' => $productId, 'quantity' => 2]], 'payment' => '100.00', 'discount_type' => 'none'];
    }

    protected function supervisor(): array
    {
        DB::connection('hrms')->table('users')->insert(['username' => 'synthetic_supervisor', 'full_name' => 'Synthetic Supervisor', 'password' => Hash::make('Synthetic!Pass123'), 'role' => 'supervisor', 'is_active' => 1]);

        return ['supervisor_username' => 'synthetic_supervisor', 'supervisor_password' => 'Synthetic!Pass123'];
    }
}
