<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutRequest;
use App\Http\Requests\RefundRequest;
use App\Services\PosService;
use App\Services\ProductAvailability;
use App\Services\StaffContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    public function index()
    {
        return view('workspace.pos');
    }

    public function resolve(Request $request, string $key, PosService $service)
    {
        $id = DB::connection('pos')->table('laravel_checkout_requests')->where('cashier_id', StaffContext::posId($request))->where('request_key', $key)->value('sale_id');
        abort_unless($id, 404);

        return response()->json(['receipt' => $service->receipt((int) $id)]);
    }

    public function lookup(Request $request)
    {
        $data = $request->validate(['q' => ['required', 'string', 'max:100']]);
        $products = ProductAvailability::query()->where(fn ($query) => $query->where('p.barcode', $data['q'])->orWhere('p.product_name', 'like', '%'.$data['q'].'%'))->orderBy('p.product_name')->limit(12)->get();

        return response()->json(['products' => $products]);
    }

    public function checkout(CheckoutRequest $request, PosService $service)
    {
        return response()->json(['receipt' => $service->checkout(StaffContext::posId($request), $request->validated())]);
    }

    public function receipt(Request $request, int $id, PosService $service)
    {
        $sale = DB::connection('pos')->table('sales')->where('id', $id)->first(['cashier_id', 'transaction_date', 'status']);
        abort_unless($sale, 404);
        if ($request->attributes->get('staff')->role === 'cashier') {
            abort_unless((int) $sale->cashier_id === StaffContext::posId($request), 403);
        }

        $canVoid = $request->attributes->get('staff')->role === 'cashier' && $sale->status === 'completed' && substr($sale->transaction_date, 0, 10) === now()->toDateString();

        return view('workspace.receipt', ['receipt' => $service->receipt($id), 'canVoid' => $canVoid]);
    }

    public function refund(RefundRequest $request, int $id, PosService $service)
    {
        $result = $service->refund(StaffContext::posId($request), $id, $request->validated());

        return $request->expectsJson() ? response()->json($result) : back()->with('status', 'Refund recorded.');
    }

    public function void(Request $request, int $id, PosService $service)
    {
        $result = $service->void(StaffContext::posId($request), $id);

        return $request->expectsJson() ? response()->json($result) : back()->with('status', 'Transaction voided. Original stock and batches restored.');
    }
}
