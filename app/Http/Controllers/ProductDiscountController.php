<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductDiscountRequest;
use App\Services\ProductDiscountService;
use App\Services\StaffContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductDiscountController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected,cancelled,all'], 'q' => ['nullable', 'string', 'max:100'], 'product_id' => ['nullable', 'integer', 'min:1', 'max:2147483647']]);
        $actor = StaffContext::posId($request);
        $role = $request->attributes->get('staff')->role;
        $db = DB::connection('pos');
        $query = $db->table('product_discount_requests as d')->leftJoin('products as p', 'p.id', '=', 'd.product_id')->select('d.request_id', 'd.product_id', 'd.requested_by', 'd.discount_type', 'd.discount_value', 'd.discount_reason', 'd.price_at_request', 'd.old_discount_type', 'd.old_discount_value', 'd.status', 'd.reject_reason', 'd.requested_at', 'd.decided_at', 'p.product_name');
        if ($role === 'inventory_staff') {
            $query->where('d.requested_by', $actor);
        }
        if (($data['status'] ?? 'pending') !== 'all') {
            $query->where('d.status', $data['status'] ?? 'pending');
        }
        $products = collect();
        if ($role !== 'finance') {
            $options = $db->table('products')->where('is_active', 1);
            if (! empty($data['q'])) {
                $options->where(fn ($q) => $q->where('product_name', 'like', '%'.$data['q'].'%')->orWhere('barcode', $data['q']));
            }
            $products = $options->orderBy('product_name')->limit(100)->get(['id', 'product_name', 'price']);
            if (! empty($data['product_id']) && ! $products->contains('id', $data['product_id'])) {
                $selected = $db->table('products')->where('id', $data['product_id'])->where('is_active', 1)->first(['id', 'product_name', 'price']);
                if ($selected) {
                    $products = collect([$selected])->concat($products)->take(100);
                }
            }
        }

        return view('workspace.product-discounts', ['requests' => $query->orderByDesc('d.request_id')->paginate(20)->withQueryString(), 'products' => $products, 'role' => $role, 'actor' => $actor]);
    }

    public function store(ProductDiscountRequest $request, ProductDiscountService $service)
    {
        $id = $service->request(StaffContext::posId($request), $request->validated());

        return $request->expectsJson() ? response()->json(['request_id' => $id, 'status' => 'pending'], 201) : redirect()->route('product-discounts.index')->with('status', 'Product discount requested. The current price stays in place until approval.');
    }

    public function decide(Request $request, int $id, ProductDiscountService $service)
    {
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected'], 'reason' => ['nullable', 'string', 'max:255']]);
        $service->transition(StaffContext::posId($request), $id, $data['decision'], $data['reason'] ?? null);

        return $request->expectsJson() ? response()->json(['status' => $data['decision']]) : back()->with('status', $data['decision'] === 'approved' ? 'Product discount approved and applied.' : 'Product discount rejected.');
    }

    public function cancel(Request $request, int $id, ProductDiscountService $service)
    {
        $service->transition(StaffContext::posId($request), $id, 'cancelled');

        return $request->expectsJson() ? response()->json(['status' => 'cancelled']) : back()->with('status', 'Product discount request cancelled.');
    }
}
