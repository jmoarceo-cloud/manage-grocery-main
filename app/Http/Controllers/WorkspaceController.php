<?php

namespace App\Http\Controllers;

use App\Http\Requests\RestockRequest;
use App\Services\DomainAudit;
use App\Services\PosService;
use App\Services\StaffContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkspaceController extends Controller
{
    private function sales(Request $request)
    {
        $query = DB::connection('pos')->table('sales');
        if ($request->attributes->get('staff')->role === 'cashier') {
            $query->where('cashier_id', StaffContext::posId($request));
        }

        return $query;
    }

    public function dashboard(Request $request)
    {
        $role = $request->attributes->get('staff')->role;
        $metrics = [];
        $recent = collect();
        $low = collect();
        if (in_array($role, ['admin', 'cashier', 'inventory_staff', 'finance'], true)) {
            $metrics['Active products'] = DB::connection('pos')->table('products')->where('is_active', 1)->count();
            $metrics['Low-stock items'] = DB::connection('pos')->table('products')->where('is_active', 1)->where('stock', '<=', 10)->count();
            $low = DB::connection('pos')->table('products')->where('is_active', 1)->where('stock', '<=', 10)->orderBy('stock')->limit(6)->get(['id', 'product_name', 'stock']);
            if ($role !== 'inventory_staff') {
                $metrics['Transactions today'] = $this->sales($request)->whereDate('transaction_date', now())->count();
                $recent = $this->sales($request)->orderByDesc('id')->limit(6)->get(['id', 'total_amount', 'transaction_date', 'status']);
            }
        }

        return view('workspace.dashboard', compact('metrics', 'recent', 'low'));
    }

    public function reports(Request $request)
    {
        $dates = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $query = $this->sales($request)->whereDate('transaction_date', '>=', $dates['from'] ?? now()->subDays(30)->toDateString())->whereDate('transaction_date', '<=', $dates['to'] ?? now()->toDateString());

        return view('workspace.reports', ['sales' => $query->orderByDesc('id')->paginate(30)->withQueryString()]);
    }

    public function inventory(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'level' => ['nullable', 'in:all,low,out']]);
        $db = DB::connection('pos');
        $products = $db->table('products')->where('is_active', 1);
        if (! empty($data['q'])) {
            $products->where(fn ($query) => $query->where('product_name', 'like', '%'.$data['q'].'%')->orWhere('barcode', $data['q']));
        }
        if (($data['level'] ?? 'all') === 'low') {
            $products->whereBetween('stock', [1, 10]);
        }
        if (($data['level'] ?? 'all') === 'out') {
            $products->where('stock', '<=', 0);
        }

        return view('workspace.inventory', [
            'products' => $products->orderBy('product_name')->paginate(30)->withQueryString(),
            'batches' => $db->table('product_batches as b')->join('products as p', 'p.id', '=', 'b.product_id')->where('b.quantity_remaining', '>', 0)->whereNotNull('b.expiry_date')->where('b.expiry_date', '<=', now()->addDays(30)->toDateString())->orderBy('b.expiry_date')->limit(12)->get(['b.id', 'b.expiry_date', 'b.quantity_remaining', 'p.product_name']),
            'restockOptions' => $request->attributes->get('staff')->role === 'admin' ? $db->table('products')->where('is_active', 1)->orderBy('product_name')->get(['id', 'product_name', 'stock']) : collect(),
        ]);
    }

    public function restock(RestockRequest $request)
    {
        $data = $request->validated();
        $actor = StaffContext::posId($request);
        $db = DB::connection('pos');
        $db->transaction(function () use ($data, $actor, $db) {
            $product = $db->table('products')->where('id', $data['product_id'])->lockForUpdate()->first();
            abort_unless($product, 404);
            if (! $product->is_active) {
                throw ValidationException::withMessages(['product_id' => 'Choose an active product before recording a restock.']);
            }
            $next = $product->stock + $data['quantity'];
            $cost = PosService::cents($data['cost_price']) * $data['quantity'];
            if ($next > 2147483647 || $cost > 9999999999) {
                throw ValidationException::withMessages(['quantity' => 'This quantity exceeds the supported inventory value.']);
            }$db->table('products')->where('id', $product->id)->update(['stock' => $next, 'cost_price' => $data['cost_price']]);
            $db->table('product_batches')->insert(['product_id' => $product->id, 'quantity_received' => $data['quantity'], 'quantity_remaining' => $data['quantity'], 'cost_price' => $data['cost_price'], 'expiry_date' => $data['expiry_date'] ?? null, 'added_by' => $actor, 'notes' => $data['reason']]);
            $db->table('stock_logs')->insert(['product_id' => $product->id, 'change_type' => 'restock', 'quantity_change' => $data['quantity'], 'previous_stock' => $product->stock, 'new_stock' => $next, 'reason' => $data['reason'], 'total_cost' => PosService::money($cost), 'user_id' => $actor]);
            $db->table('notifications')->insert(['product_id' => $product->id, 'message' => 'Restocked: '.$product->product_name.' +'.$data['quantity'].' units.']);
            DomainAudit::record('products', $product->id, 'restocked', ['stock', 'cost_price', 'batches'], $actor);
        });

        return back()->with('status', 'Stock and batch recorded.');
    }
}
