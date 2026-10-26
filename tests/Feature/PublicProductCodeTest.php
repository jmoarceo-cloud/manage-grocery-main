<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class PublicProductCodeTest extends IsolatedFeatureTestCase
{
    private function coded(string $code, bool $active = true): int
    {
        return DB::connection('pos')->table('products')->insertGetId(['product_name' => 'Synthetic code lookup', 'barcode' => $code, 'price' => '10.00', 'stock' => 0, 'is_active' => $active]);
    }

    public function test_public_lookup_opens_without_reading_catalog_or_personnel_records(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->get('/catalog/scan')->assertOk()->assertSee('Find it by code.')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame([], $queries);
    }

    public function test_active_store_code_redirects_only_to_the_native_product_even_when_out_of_stock(): void
    {
        $id = $this->coded('SYNTHETIC.Code_01-A');
        Http::preventStrayRequests();
        $this->get('/catalog/scan?'.http_build_query(['b' => 'SYNTHETIC.Code_01-A', 'return' => 'https://example.invalid', 'url' => '//example.invalid']))->assertRedirect(route('product', $id))->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('product', $id))->assertOk()->assertSee('Out of stock')->assertSee('awaiting human verification');
        Http::assertNothingSent();
    }

    public function test_leading_zero_gtin14_falls_back_to_gtin13_only_when_no_active_exact_match_exists(): void
    {
        $short = $this->coded('1234567890123');
        $this->get('/catalog/scan?b=01234567890123')->assertRedirect(route('product', $short));
        $exact = $this->coded('01234567890123');
        $this->get('/catalog/scan?b=01234567890123')->assertRedirect(route('product', $exact));
        DB::connection('pos')->table('products')->where('id', $exact)->update(['is_active' => 0]);
        $this->get('/catalog/scan?b=01234567890123')->assertRedirect(route('product', $short));
        $this->get('/catalog/scan?b=11234567890123')->assertOk()->assertSee('No active product matches');
    }

    public function test_unknown_and_inactive_codes_use_the_same_state_without_writes_or_external_requests(): void
    {
        $this->coded('SYNTHETIC-INACTIVE', false);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        Http::preventStrayRequests();
        foreach (['SYNTHETIC-UNKNOWN', 'SYNTHETIC-INACTIVE'] as $code) {
            $this->get('/catalog/scan?b='.$code)->assertOk()->assertSee('No active product matches')->assertSee($code)->assertDontSee('Synthetic code lookup');
        }
        $this->assertCount(2, $queries);
        foreach ($queries as $sql) {
            $this->assertStringStartsWith('select', strtolower($sql));
            $this->assertStringContainsString('`products`', $sql);
            $this->assertStringNotContainsString('hrms', $sql);
        }
        Http::assertNothingSent();
    }

    public function test_invalid_codes_arrays_links_controls_and_oversized_values_are_rejected_before_queries(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        foreach (['xy', ['SYNTHETIC'], str_repeat('A', 65), '<script>alert(1)</script>', 'https://example.invalid', 'SYNTHETIC'."\0".'-CODE'] as $code) {
            $this->get('/catalog/scan?'.http_build_query(['b' => $code]))->assertUnprocessable()->assertSee('Enter 3 to 64')->assertDontSee('<script>alert(1)</script>', false);
        }
        $this->assertSame([], $queries);
    }

    public function test_lookup_is_throttled_after_sixty_requests_from_one_client(): void
    {
        for ($index = 0; $index < 60; $index++) {
            $this->get('/catalog/scan')->assertOk();
        }
        $this->get('/catalog/scan')->assertTooManyRequests()->assertHeader('Retry-After');
    }
}
