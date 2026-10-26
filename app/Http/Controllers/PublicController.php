<?php

namespace App\Http\Controllers;

use App\Services\PosService;
use App\Services\ProductAvailability;
use App\Services\PublicProductFacts;
use App\Services\PublicProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublicController extends Controller
{
    public function index()
    {
        $db = DB::connection('pos');

        return view('public.home', [
            'featuredProducts' => $db->table('products')->where('is_active', 1)->where('stock', '>', 0)->inRandomOrder()->limit(4)->get(['id', 'product_name', 'category', 'price', 'image', 'stock'])->map(fn ($row) => (array) $row)->all(),
            'featuredCategories' => $db->table('products')->where('is_active', 1)->where('stock', '>', 0)->whereNotNull('category')->where('category', '<>', '')->distinct()->orderBy('category')->limit(9)->pluck('category')->all(),
        ]);
    }

    public function catalog(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'search' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', 'string', 'max:100'], 'exclude_allergen' => ['nullable', 'string', 'max:30'], 'private' => ['nullable', 'in:0,1']]);
        $search = trim($data['search'] ?? $data['q'] ?? '');
        $category = trim($data['category'] ?? '');
        $allowedAllergens = ['milk', 'peanut', 'tree nuts', 'soy', 'wheat', 'egg', 'fish', 'shellfish'];
        $excludeAllergen = strtolower(trim($data['exclude_allergen'] ?? ''));
        if (! in_array($excludeAllergen, $allowedAllergens, true)) {
            $excludeAllergen = '';
        }
        if (($data['private'] ?? '') === '1') {
            // PHP kiosk/supervisor sessions stay in the preserved application.
            return redirect(rtrim(config('app.legacy_url'), '/').'/catalog.php?'.http_build_query(array_filter(['private' => '1', 'search' => $search, 'category' => $category, 'exclude_allergen' => $excludeAllergen], fn ($value) => $value !== '')));
        }
        $query = ProductAvailability::query();
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('p.product_name', 'like', '%'.$search.'%')->orWhere('p.barcode', $search));
        }
        if ($category !== '') {
            $query->where('p.category', $category);
        }
        if ($excludeAllergen !== '') {
            $query->whereIn('p.id', DB::connection('pos')->table('product_details')->select('product_id')->where('review_status', 'verified')->where(fn ($q) => $q->whereNull('allergens')->orWhereRaw('LOWER(allergens) NOT LIKE ?', ['%'.$excludeAllergen.'%'])));
        }
        $products = $query->orderByRaw('(sellable_stock > 0) DESC')->orderBy('p.product_name')->get()->each(function ($product): void {
            $product->has_catalog_image = PublicProductImage::path($product->image) !== null;
        });
        $db = DB::connection('pos');

        return view('public.catalog', [
            'products' => $products, 'search' => $search, 'category' => $category,
            'excludeAllergen' => $excludeAllergen, 'allowedAllergens' => $allowedAllergens,
            'categories' => $db->table('categories')->orderBy('category_name')->get(['id', 'category_name']),
            'totalProducts' => $db->table('products')->where('is_active', 1)->count(),
            'categoryCounts' => $db->table('products')->where('is_active', 1)->select('category')->selectRaw('COUNT(*) as count')->groupBy('category')->pluck('count', 'category')->all(),
        ]);
    }

    public function product(Request $request, int $id)
    {
        $data = $request->validate(['return' => ['nullable', 'string', 'max:40']]);
        $product = ProductAvailability::query()->where('p.id', $id)->first();
        abort_unless($product, 404);

        $details = PublicProductFacts::get([$id])->get($id);
        $related = $product->category !== null ? ProductAvailability::query()->where('p.category', $product->category)->where('p.id', '<>', $id)->having('sellable_stock', '>', 0)->orderBy('p.product_name')->limit(4)->get() : collect();

        $returnKey = $data['return'] ?? '';
        $targets = ['admin' => route('admin'), 'inventory' => route('inventory'), 'cashier' => route('pos')];
        if (! array_key_exists($returnKey, $targets)) {
            $returnKey = '';
        }
        $display = array_merge((array) $product, (array) $details, ['stock' => $product->sellable_stock]);
        $price = PosService::money(PosService::unitPriceCents($product));
        $detailsAreVerified = $details !== null;
        $relatedProducts = $related->map(fn ($item) => array_merge((array) $item, ['stock' => $item->sellable_stock, 'image_url' => PublicProductImage::path($item->image) ? route('product.image', $item->id) : '']))->all();

        return view('public.product', [
            'product' => $product, 'details' => $details, 'related' => $related,
            'display' => $display, 'price' => $price, 'hasDiscount' => (float) $price < (float) $product->price - 0.001,
            'detailsAreVerified' => $detailsAreVerified, 'relatedProducts' => $relatedProducts,
            'stockLabel' => $product->sellable_stock > 0 ? ((int) $product->sellable_stock).' in stock' : 'Out of stock',
            'productImage' => PublicProductImage::path($product->image) ? route('product.image', $product->id) : '',
            'returnKey' => $returnKey, 'catalogPrivate' => false,
            'detailBackHref' => $targets[$returnKey] ?? route('catalog'), 'detailBackLabel' => $returnKey !== '' ? 'Back to dashboard' : 'Back to catalog',
            'productSearchAction' => in_array($returnKey, ['admin', 'inventory'], true) ? route('manage.index', 'products') : route('catalog'),
            'searchField' => in_array($returnKey, ['admin', 'inventory'], true) ? 'q' : 'search',
        ]);
    }

    public function compare(Request $request)
    {
        $data = $request->validate(['ids' => ['nullable', 'array', 'max:3'], 'ids.*' => ['nullable', 'integer', 'min:1', 'max:2147483647', 'distinct']]);
        $ids = collect($data['ids'] ?? [])->filter(fn ($id) => $id !== null && $id !== '')->map(fn ($id) => (int) $id)->values();
        $found = $ids->isEmpty() ? collect() : PublicProductFacts::get($ids->all());
        if ($found->count() !== $ids->count()) {
            throw ValidationException::withMessages(['ids' => 'Choose active products with verified label information.']);
        }
        $facts = $ids->map(fn ($id) => $found->get($id));
        $choices = PublicProductFacts::query()->select('d.product_id', 'p.product_name')->orderBy('p.product_name')->limit(100)->get();
        $choices = $facts->map(fn ($item) => (object) ['product_id' => $item->product_id, 'product_name' => $item->product_name])->concat($choices)->unique('product_id')->take(100)->sortBy(fn ($item) => mb_strtolower($item->product_name))->values();

        return view('public.compare', ['facts' => $facts, 'choices' => $choices, 'selected' => $ids->all(), 'notice' => PublicProductFacts::comparisonNotice($facts)]);
    }

    public function image(int $id)
    {
        $product = DB::connection('pos')->table('products')->where('id', $id)->where('is_active', 1)->first(['image']);
        abort_unless($product && $product->image, 404);
        $path = PublicProductImage::path($product->image);
        abort_unless($path, 404);

        return response()->file($path, ['Content-Type' => (new \finfo(FILEINFO_MIME_TYPE))->file($path), 'Cache-Control' => 'public, max-age=3600']);
    }
}
