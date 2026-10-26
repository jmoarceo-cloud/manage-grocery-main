<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

class OriginalComparisonUiTest extends IsolatedFeatureTestCase
{
    private function sample(array $values): int
    {
        $id = $this->product();
        DB::connection('pos')->table('products')->where('id', $id)->update(['category' => 'Synthetic comparison']);
        DB::connection('pos')->table('product_details')->insert($values + ['product_id' => $id, 'review_status' => 'verified', 'nutrition_basis' => 'per_100g']);

        return $id;
    }

    public function test_original_minimum_cell_style_preserves_zero_and_excludes_unknown_values(): void
    {
        $first = $this->sample(['calories' => '200.00', 'sugar_g' => '0.00', 'protein_g' => null]);
        $second = $this->sample(['calories' => '100.00', 'sugar_g' => '5.00', 'protein_g' => '3.00']);
        $response = $this->get('/compare?'.http_build_query(['ids' => [$first, $second]]))->assertOk()->assertSee('Nutrition comparison')->assertSee('class="picker"', false);
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $sugar = $xpath->query('//table[contains(@class,"comparison-table")]//tr[th="Sugar (g)"]/td');
        $this->assertSame('0.00', trim($sugar->item(0)->textContent));
        $this->assertSame('best', $sugar->item(0)->getAttribute('class'));
        $this->assertSame('5.00', trim($sugar->item(1)->textContent));
        $this->assertSame('', $sugar->item(1)->getAttribute('class'));
        $protein = $xpath->query('//table[contains(@class,"comparison-table")]//tr[th="Protein (g)"]/td');
        $this->assertSame('Not listed', trim($protein->item(0)->textContent));
        $this->assertSame('', $protein->item(0)->getAttribute('class'));
        $this->assertSame('', $protein->item(1)->getAttribute('class'));
    }

    public function test_single_verified_product_uses_original_table_without_comparison_writes_or_personnel_queries(): void
    {
        $id = $this->sample(['sugar_g' => '0.00']);
        $pos = DB::connection('pos');
        $hr = DB::connection('hrms');
        $pos->enableQueryLog();
        $hr->enableQueryLog();
        $this->get('/compare?'.http_build_query(['ids' => [$id]]))->assertOk()->assertSee('class="compare comparison-table"', false)->assertSee('Not listed')->assertSee('value="'.$id.'" selected', false)->assertDontSee('class="best"', false);
        foreach ($pos->getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(?:insert|update|delete|alter|create)\b/i', $query['query']);
            $this->assertStringNotContainsString('cost_price', $query['query']);
        }
        $this->assertEmpty($hr->getQueryLog());
        $pos->disableQueryLog();
        $hr->disableQueryLog();
    }
}
