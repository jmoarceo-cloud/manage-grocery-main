<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

class PublicProductFactsTest extends IsolatedFeatureTestCase
{
    private function facts(string $status = 'verified', array $overrides = []): int
    {
        $id = $this->product();
        $db = DB::connection('pos');
        $db->table('products')->where('id', $id)->update(['category' => 'Synthetic food']);
        $db->table('product_details')->insert(array_replace(['product_id' => $id, 'review_status' => $status, 'description' => 'Synthetic reviewed description', 'ingredients' => 'Synthetic ingredients', 'allergens' => 'Synthetic allergen', 'nutrition_basis' => 'per_100g', 'sugar_g' => '0.00', 'calories' => null, 'nutrition_source' => 'manual', 'rejected_reason' => 'Synthetic internal review reason', 'updated_by' => 999999], $overrides));

        return $id;
    }

    private function comparison(array $ids): string
    {
        return '/compare?'.http_build_query(['ids' => $ids]);
    }

    public function test_all_unverified_states_hide_facts_and_internal_review_metadata(): void
    {
        foreach (['draft', 'ai_filled', 'needs_review', 'rejected'] as $status) {
            $id = $this->facts($status);
            $this->get('/catalog/'.$id)->assertOk()->assertDontSee('Synthetic reviewed description')->assertDontSee('Synthetic ingredients')->assertDontSee('Synthetic internal review reason')->assertDontSee('999999');
        }
    }

    public function test_verified_facts_escape_text_preserve_zero_and_suppress_unsafe_source_links(): void
    {
        $id = $this->facts('verified', ['description' => '<script>synthetic description</script>', 'nutrition_source_url' => 'javascript:synthetic_bad_source()']);
        $this->get('/catalog/'.$id)->assertOk()->assertSee('&lt;script&gt;synthetic description&lt;/script&gt;', false)->assertDontSee('<script>synthetic description</script>', false)->assertSee('<td>Sugar (g)</td><td>0</td>', false)->assertDontSee('<td>Calories</td>', false)->assertSee('Values are per 100g')->assertDontSee('javascript:synthetic_bad_source')->assertDontSee('Synthetic internal review reason');
    }

    public function test_matching_verified_products_compare_without_missing_values_becoming_zero(): void
    {
        $first = $this->facts();
        $second = $this->facts('verified', ['calories' => '120.00', 'sugar_g' => null]);
        $this->get($this->comparison([$first, $second]))->assertOk()->assertSee('comparison-table', false)->assertSee('120.00')->assertSee('0.00')->assertSee('Not listed')->assertDontSee('Synthetic internal review reason');
    }

    public function test_verified_source_links_allow_web_urls_without_publishing_embedded_credentials(): void
    {
        $id = $this->facts('verified', ['nutrition_source_url' => 'https://example.org/synthetic-label']);
        $this->get('/catalog/'.$id)->assertOk()->assertSee('https://example.org/synthetic-label', false)->assertSee('rel="noopener noreferrer"', false);
        DB::connection('pos')->table('product_details')->where('product_id', $id)->update(['nutrition_source_url' => 'https://synthetic-user:synthetic-secret@example.org/label']);
        $this->get('/catalog/'.$id)->assertOk()->assertDontSee('synthetic-user')->assertDontSee('synthetic-secret');
    }

    public function test_mixed_categories_and_measurement_bases_do_not_render_a_comparison_table(): void
    {
        $first = $this->facts();
        $second = $this->facts('verified', ['nutrition_basis' => 'per_serving', 'serving_size' => '25 g']);
        $this->get($this->comparison([$first, $second]))->assertOk()->assertSee('different measurement bases')->assertDontSee('comparison-table', false);
        DB::connection('pos')->table('product_details')->where('product_id', $second)->update(['nutrition_basis' => 'per_100g']);
        DB::connection('pos')->table('products')->where('id', $second)->update(['category' => 'Synthetic drinks']);
        $this->get($this->comparison([$first, $second]))->assertOk()->assertSee('same recorded category')->assertDontSee('comparison-table', false);
        DB::connection('pos')->table('products')->where('id', $second)->update(['category' => 'Synthetic food']);
        DB::connection('pos')->table('product_details')->where('product_id', $second)->update(['nutrition_basis' => null]);
        $this->get($this->comparison([$first, $second]))->assertOk()->assertSee('measurement basis is missing')->assertDontSee('comparison-table', false);
    }

    public function test_serving_sizes_must_match_without_guessing_conversions(): void
    {
        $first = $this->facts('verified', ['nutrition_basis' => 'per_serving', 'serving_size' => '25 g']);
        $second = $this->facts('verified', ['nutrition_basis' => 'per_serving', 'serving_size' => '30 g']);
        $this->get($this->comparison([$first, $second]))->assertOk()->assertSee('Serving sizes differ')->assertDontSee('comparison-table', false);
        DB::connection('pos')->table('product_details')->where('product_id', $second)->update(['serving_size' => '25g']);
        $this->get($this->comparison([$first, $second]))->assertOk()->assertSee('comparison-table', false);
    }

    public function test_unverified_inactive_duplicate_or_excessive_selections_are_rejected(): void
    {
        $verified = $this->facts();
        $draft = $this->facts('draft');
        $inactive = $this->facts();
        DB::connection('pos')->table('products')->where('id', $inactive)->update(['is_active' => 0]);
        foreach ([[$verified, $draft], [$verified, $inactive], [$verified, $verified], [$verified, $draft, $inactive, 2147483647]] as $ids) {
            $this->getJson($this->comparison($ids))->assertUnprocessable();
        }
        $this->get('/catalog/99999999999999999999999999')->assertNotFound();
    }

    public function test_comparison_preserves_a_selected_product_outside_the_first_hundred_choices(): void
    {
        $db = DB::connection('pos');
        $products = [];
        for ($index = 0; $index < 101; $index++) {
            $products[] = ['product_name' => 'Synthetic choice '.str_pad((string) $index, 3, '0', STR_PAD_LEFT), 'price' => '1.00', 'stock' => 0, 'is_active' => 1];
        }
        $db->table('products')->insert($products);
        $ids = $db->table('products')->where('product_name', 'like', 'Synthetic choice %')->pluck('id');
        $db->table('product_details')->insert($ids->map(fn ($id) => ['product_id' => $id, 'review_status' => 'verified'])->all());
        $selected = $this->facts();
        $db->table('products')->where('id', $selected)->update(['product_name' => 'ZZZ Synthetic Selected']);
        $this->get($this->comparison([$selected]))->assertOk()->assertSee('ZZZ Synthetic Selected')->assertSee('value="'.$selected.'" selected', false);
    }
}
