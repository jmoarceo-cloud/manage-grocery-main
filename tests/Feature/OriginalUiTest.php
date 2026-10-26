<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OriginalUiTest extends IsolatedFeatureTestCase
{
    public function test_home_uses_original_featured_and_category_contract_without_executing_legacy_bootstraps(): void
    {
        $db = DB::connection('pos');
        $db->table('products')->update(['is_active' => 0]);
        $expected = [];
        for ($i = 0; $i < 12; $i++) {
            $id = $this->product();
            $expected[] = 'Synthetic category '.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $db->table('products')->where('id', $id)->update(['category' => end($expected)]);
        }
        $id = $this->product(0);
        $db->table('products')->where('id', $id)->update(['category' => 'Not a featured category']);
        $response = $this->get('/')->assertOk()->assertViewIs('public.home')->assertSee('One store.')->assertSee('Connected work.');
        $this->assertCount(4, $response->viewData('featuredProducts'));
        $this->assertSame(array_slice($expected, 0, 9), $response->viewData('featuredCategories'));
        $this->assertSame(12, preg_match_all('/class="workflow-step(?:\s|")/', $response->getContent()));
        $included = array_map('realpath', get_included_files());
        foreach (['project/includes/config.php', 'project/HRMS/includes/config.php', 'project/includes/public_bootstrap.php'] as $file) {
            $this->assertNotContains(realpath(base_path($file)), $included);
        }
    }

    public function test_original_home_escapes_public_text_and_uses_id_image_routes(): void
    {
        $db = DB::connection('pos');
        $db->table('products')->update(['is_active' => 0]);
        $id = $this->product();
        $db->table('products')->where('id', $id)->update(['product_name' => '<script>Synthetic title</script>', 'category' => '<b>Synthetic category</b>', 'image' => 'synthetic-private-filename.png', 'cost_price' => '9876.54']);
        $this->get('/')->assertOk()->assertSee('&lt;script&gt;Synthetic title&lt;/script&gt;', false)->assertDontSee('<script>Synthetic title</script>', false)->assertSee(route('product.image', $id), false)->assertDontSee('synthetic-private-filename')->assertDontSee('9876.54');
    }

    public function test_original_login_keeps_form_controls_and_native_csrf_without_external_assets(): void
    {
        $html = $this->get('/login')->assertOk()->assertSee('Welcome back')->assertSee('One login for')->assertSee('Forgot your password?')->assertSee('Show password')->assertSee('name="_token"', false)->assertSee(route('login.submit'), false)->getContent();
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $html);
        $this->assertStringNotContainsString('resources/css/app.css', $html);
    }

    public function test_catalog_keeps_sidebar_all_results_stock_order_search_alias_and_base_prices(): void
    {
        $db = DB::connection('pos');
        $db->table('products')->update(['is_active' => 0]);
        $ids = [];
        for ($i = 0; $i < 21; $i++) {
            $ids[] = $this->product($i === 0 ? 0 : 10);
            $db->table('products')->where('id', end($ids))->update(['product_name' => 'Synthetic catalog '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'category' => 'Synthetic original', 'discount_type' => 'percent', 'discount_value' => '20.00']);
        }
        $db->table('categories')->insert(['category_name' => 'Synthetic original']);
        $response = $this->get('/catalog?search=Synthetic+catalog')->assertOk()->assertSee('Shop at')->assertSee('Allergen safety')->assertSee('21 products found')->assertSee('25.00');
        $this->assertCount(21, $response->viewData('products'));
        $this->assertSame($ids[1], $response->viewData('products')->first()->id);
        $this->assertSame($ids[0], $response->viewData('products')->last()->id);
        $this->assertSame(21, (int) $response->viewData('categoryCounts')['Synthetic original']);
        $this->get('/catalog?q=catalog+00')->assertOk()->assertSee('1 product found');
        $this->get('/catalog?search=Synthetic+catalog&category=missing')->assertOk()->assertSee('No products found');
    }

    public function test_allergen_exclusion_requires_verified_label_data_and_preserves_null_behavior(): void
    {
        $db = DB::connection('pos');
        $db->table('products')->update(['is_active' => 0]);
        $expected = [];
        foreach ([['verified', 'Milk, egg'], ['verified', 'Wheat'], ['verified', null], ['draft', 'Soy'], ['needs_review', null]] as [$status, $allergens]) {
            $id = $this->product();
            $db->table('product_details')->insert(['product_id' => $id, 'review_status' => $status, 'allergens' => $allergens]);
            if ($status === 'verified' && ($allergens === null || $allergens === 'Wheat')) {
                $expected[] = $id;
            }
        }
        $response = $this->get('/catalog?exclude_allergen=MiLk')->assertOk()->assertSee('Filtered on verified label information only');
        $this->assertEqualsCanonicalizing($expected, $response->viewData('products')->pluck('id')->all());
        $this->assertSame('', $this->get('/catalog?exclude_allergen=unknown')->assertOk()->viewData('excludeAllergen'));
    }

    public function test_private_catalog_requests_return_to_existing_guard_without_public_product_queries(): void
    {
        DB::connection('pos')->enableQueryLog();
        $this->get('/catalog?private=1&search=Synthetic')->assertRedirect(rtrim(config('app.legacy_url'), '/').'/catalog.php?private=1&search=Synthetic');
        $this->assertEmpty(DB::connection('pos')->getQueryLog());
        DB::connection('pos')->disableQueryLog();
        $this->getJson('/catalog?search[]=x')->assertUnprocessable();
        $this->getJson('/catalog?exclude_allergen[]=milk')->assertUnprocessable();
    }

    public function test_legacy_basename_product_images_use_the_protected_route_and_real_image_validation(): void
    {
        $name = 'synthetic-original-ui-'.Str::uuid().'.png';
        $directory = public_path('uploads/products');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.'/'.$name;
        try {
            file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1UAAAAASUVORK5CYII='));
            $id = $this->product();
            DB::connection('pos')->table('products')->where('id', $id)->update(['image' => $name]);
            $this->get('/product-image/'.$id)->assertOk()->assertHeader('Content-Type', 'image/png');
            $this->get('/catalog')->assertOk()->assertSee(route('product.image', $id), false)->assertDontSee($name);
            file_put_contents($path, 'Synthetic invalid image content');
            $this->get('/product-image/'.$id)->assertNotFound();
            $this->get('/catalog')->assertOk()->assertDontSee(route('product.image', $id), false);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_original_css_and_theme_script_copies_are_identical_to_preserved_sources(): void
    {
        foreach (['project/assets/css/theme.css' => 'theme.css', 'project/assets/css/landing.css' => 'landing.css', 'project/theme-toggle.css' => 'theme-toggle.css', 'project/assets/css/page-controls.css' => 'page-controls.css'] as $source => $copy) {
            $this->assertSame(hash_file('sha256', base_path($source)), hash_file('sha256', resource_path('css/legacy/source/'.$copy)));
        }
        foreach (['login' => 'login', 'catalog' => 'catalog', 'nutrition_compare' => 'compare'] as $page => $copy) {
            preg_match('~<style>(.*?)</style>~s', file_get_contents(base_path('project/'.$page.'.php')), $css);
            $this->assertSame(hash('sha256', $css[1]), hash_file('sha256', resource_path('css/legacy/source/'.$copy.'.css')));
        }
        $this->assertSame(hash_file('sha256', base_path('project/theme-sync.js')), hash_file('sha256', resource_path('js/legacy/source/theme-sync.js')));
    }
}
