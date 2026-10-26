<?php

namespace App\Http\Controllers;

use App\Services\PosService;
use App\Services\ProductLabelService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductLabelController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $query = DB::connection('pos')->table('products')->where('is_active', 1);
        if (! empty($data['q'])) {
            $query->where(fn ($query) => $query->where('product_name', 'like', '%'.$data['q'].'%')->orWhere('barcode', $data['q']));
        }

        return view('labels.index', ['products' => $query->orderBy('product_name')->paginate(96, ['id', 'product_name', 'category', 'barcode', 'price', 'discount_type', 'discount_value'])->withQueryString()]);
    }

    public function print(Request $request)
    {
        $data = $request->validate(['kind' => ['required', 'in:barcode,shelf'], 'product_ids' => ['required', 'array', 'min:1', 'max:96'], 'product_ids.*' => ['required', 'integer', 'min:1', 'max:2147483647', 'distinct']]);
        $products = DB::connection('pos')->table('products')->where('is_active', 1)->whereIn('id', $data['product_ids'])->orderBy('product_name')->get(['id', 'product_name', 'category', 'barcode', 'price', 'discount_type', 'discount_value']);
        if ($products->count() !== count($data['product_ids'])) {
            throw ValidationException::withMessages(['product_ids' => 'Select existing active products only.']);
        }
        $labels = $products->map(function ($product) use ($data) {
            $url = rtrim(config('app.url'), '/').'/catalog/'.$product->id;

            return ['product' => $product, 'price' => PosService::money(PosService::unitPriceCents($product)), 'url' => $url, 'svg' => $data['kind'] === 'shelf' ? ProductLabelService::qr($url) : ProductLabelService::ean13($product->barcode ?? '')];
        });

        return view('labels.print', ['labels' => $labels, 'kind' => $data['kind']]);
    }
}
