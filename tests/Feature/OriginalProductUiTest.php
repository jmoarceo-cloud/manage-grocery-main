<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

class OriginalProductUiTest extends IsolatedFeatureTestCase
{
    public function test_original_detail_keeps_unknown_nutrients_absent_and_zero_visible_with_safe_multiline_text(): void
    {
        $id = $this->product();
        DB::connection('pos')->table('product_details')->insert(['product_id' => $id, 'review_status' => 'verified', 'description' => "<b>Synthetic first line</b>\nSynthetic second line", 'ingredients' => '<i>Synthetic ingredient</i>', 'brand' => 'Synthetic original brand', 'nutrition_basis' => 'per_100g', 'sugar_g' => '0.00', 'calories' => null, 'nutrition_source_url' => 'https://example.invalid/synthetic-label']);
        $this->get('/catalog/'.$id)->assertOk()->assertSee('class="detail"', false)->assertSee('class="photo"', false)->assertSee('Ingredients, allergens &amp; storage', false)->assertSee('&lt;b&gt;Synthetic first line&lt;/b&gt;<br />', false)->assertDontSee('<b>Synthetic first line</b>', false)->assertSee('<td>Sugar (g)</td><td>0</td>', false)->assertDontSee('<td>Calories</td>', false)->assertSee('Values are per 100g')->assertSee('rel="noopener noreferrer"', false)->assertSee('10 in stock');
    }

    public function test_unverified_information_uses_the_original_notice_without_publishing_fields_or_private_records(): void
    {
        $id = $this->product(0);
        DB::connection('pos')->table('product_details')->insert(['product_id' => $id, 'review_status' => 'needs_review', 'description' => 'Synthetic unpublished description', 'brand' => 'Synthetic unpublished brand', 'nutrition_source_url' => 'https://example.invalid/unpublished', 'rejected_reason' => 'Synthetic private review reason']);
        $db = DB::connection('pos');
        $db->enableQueryLog();
        DB::connection('hrms')->enableQueryLog();
        $this->get('/catalog/'.$id)->assertOk()->assertSee('awaiting human verification')->assertSee('Out of stock')->assertDontSee('Synthetic unpublished')->assertDontSee('Synthetic private review reason')->assertDontSee('example.invalid/unpublished')->assertDontSee('class="nutrition-hero"', false);
        foreach ($db->getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(?:insert|update|delete|alter|create)\b/i', $query['query']);
            $this->assertStringNotContainsString('cost_price', $query['query']);
        }
        $this->assertEmpty(DB::connection('hrms')->getQueryLog());
        $db->disableQueryLog();
        DB::connection('hrms')->disableQueryLog();
    }

    public function test_original_return_contexts_use_known_native_routes_and_never_external_destinations(): void
    {
        $id = $this->product();
        foreach (['admin' => route('admin'), 'inventory' => route('inventory'), 'cashier' => route('pos')] as $context => $target) {
            $response = $this->get('/catalog/'.$id.'?return='.$context)->assertOk()->assertSee('Back to dashboard');
            $this->assertSame($target, $response->viewData('detailBackHref'));
            $this->assertSame($context === 'cashier' ? 'search' : 'q', $response->viewData('searchField'));
        }
        $response = $this->get('/catalog/'.$id.'?return=https://example.invalid')->assertOk()->assertSee('Back to catalog');
        $this->assertSame(route('catalog'), $response->viewData('detailBackHref'));
        $this->getJson('/catalog/'.$id.'?return[]=admin')->assertUnprocessable();
        $this->get(route('admin'))->assertRedirect(route('login'));
    }

    public function test_source_style_has_only_the_original_dynamic_stock_color_mapping(): void
    {
        preg_match('~<style>(.*?)</style>~s', file_get_contents(base_path('project/product_detail.php')), $style);
        $mapped = str_replace("<?= (int) \$product['stock'] > 0 ? '#15803d' : '#b91c1c' ?>", 'var(--original-stock-color)', $style[1]);
        $this->assertSame($mapped, file_get_contents(resource_path('css/legacy/source/product.css')));
    }

    public function test_original_related_products_include_empty_category_matches_and_retain_return_context(): void
    {
        $id = $this->product();
        $related = $this->product();
        $unavailable = $this->product(0);
        DB::connection('pos')->table('products')->whereIn('id', [$id, $related, $unavailable])->update(['category' => '']);
        $response = $this->get('/catalog/'.$id.'?return=inventory')->assertOk()->assertSee('Related products')->assertSee(route('product', ['id' => $related, 'return' => 'inventory']));
        $this->assertContains($related, $response->viewData('related')->pluck('id')->all());
        $this->assertNotContains($id, $response->viewData('related')->pluck('id')->all());
        $this->assertNotContains($unavailable, $response->viewData('related')->pluck('id')->all());
    }
}
