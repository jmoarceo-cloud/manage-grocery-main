<?php

namespace Tests\Feature;

use App\Models\PosUser;
use App\Services\ProductLabelService;
use Illuminate\Support\Facades\DB;

class ProductLabelsTest extends IsolatedFeatureTestCase
{
    private function inventoryUser(): PosUser
    {
        return PosUser::where('username', 'demo_inventory_staff')->firstOrFail();
    }

    public function test_cashiers_cannot_select_or_print_staff_product_labels(): void
    {
        $user = $this->cashier();
        $id = $this->product();
        $this->staffSession($user)->get('/labels')->assertForbidden();
        $this->post('/labels/print', ['kind' => 'shelf', 'product_ids' => [$id]])->assertForbidden();
    }

    public function test_inventory_labels_escape_names_and_use_current_discounted_prices(): void
    {
        $id = $this->product();
        $db = DB::connection('pos');
        $name = '<script>synthetic label</script>';
        $db->table('products')->where('id', $id)->update(['product_name' => $name, 'barcode' => '5901234123457', 'discount_type' => 'fixed', 'discount_value' => '5.00']);
        $this->staffSession($this->inventoryUser())->post('/labels/print', ['kind' => 'shelf', 'product_ids' => [$id]])->assertOk()->assertSee('&lt;script&gt;synthetic label&lt;/script&gt;', false)->assertDontSee($name, false)->assertSee('PHP 20.00')->assertSee('<svg', false);
        $this->post('/labels/print', ['kind' => 'barcode', 'product_ids' => [$id]])->assertOk()->assertSee('EAN-13 5901234123457')->assertSee('PHP 20.00');
        $this->get('/catalog/'.$id)->assertOk()->assertSee('20.00')->assertSee('25.00');
        $this->assertDatabaseHas('products', ['id' => $id, 'stock' => 10], 'pos');
        $this->assertDatabaseCount('stock_logs', 0, 'pos');
    }

    public function test_inactive_missing_duplicate_and_excessive_label_selections_are_rejected(): void
    {
        $id = $this->product();
        DB::connection('pos')->table('products')->where('id', $id)->update(['is_active' => 0]);
        $this->staffSession($this->inventoryUser())->postJson('/labels/print', ['kind' => 'shelf', 'product_ids' => [$id]])->assertUnprocessable();
        $this->postJson('/labels/print', ['kind' => 'shelf', 'product_ids' => [2147483647]])->assertUnprocessable();
        $this->postJson('/labels/print', ['kind' => 'barcode', 'product_ids' => [$id, $id]])->assertUnprocessable();
        $this->postJson('/labels/print', ['kind' => 'shelf', 'product_ids' => range(1, 97)])->assertUnprocessable();
        $this->from('/labels')->post('/labels/print', ['kind' => 'shelf', 'product_ids' => 'malformed'])->assertRedirect('/labels')->assertSessionHasErrors('product_ids');
        $this->get('/labels')->assertOk();
    }

    public function test_bad_ean_checksum_is_explicit_without_rewriting_the_product_barcode(): void
    {
        $this->assertNull(ProductLabelService::ean13('4006381333932'));
        $this->assertNull(ProductLabelService::ean13('<script>bad</script>'));
        $this->assertNotNull(ProductLabelService::ean13('4006381333931'));
        $id = $this->product();
        DB::connection('pos')->table('products')->where('id', $id)->update(['barcode' => '4006381333932']);
        $this->staffSession($this->inventoryUser())->post('/labels/print', ['kind' => 'barcode', 'product_ids' => [$id]])->assertOk()->assertSee('Barcode needs a valid EAN-13 value.')->assertSee('4006381333932');
        $this->assertDatabaseHas('products', ['id' => $id, 'barcode' => '4006381333932'], 'pos');
    }
}
