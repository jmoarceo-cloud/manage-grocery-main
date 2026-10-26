<?php

namespace App\Http\Controllers;

use App\Http\Requests\DiscountApprovalRequest;
use App\Services\DiscountApprovalService;
use App\Services\StaffContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DiscountApprovalController extends Controller
{
    private function query(Request $request)
    {
        $query = DB::connection('pos')->table('discount_requests as d')->leftJoin('laravel_checkout_requests as c', 'c.discount_request_id', '=', 'd.request_id')->select('d.request_id', 'd.cashier_id', 'd.discount_type', 'd.discount_value', 'd.subtotal_at_request', 'd.computed_discount_amount', 'd.reason', 'd.status', 'd.reject_reason', 'd.requested_at', 'd.decided_at', 'c.sale_id');
        if ($request->attributes->get('staff')->role === 'cashier') {
            $query->where('d.cashier_id', StaffContext::posId($request));
        }

        return $query;
    }

    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected,cancelled,all']]);
        $query = $this->query($request);
        if (($data['status'] ?? 'pending') !== 'all') {
            $query->where('d.status', $data['status'] ?? 'pending');
        }

        return view('workspace.discounts', ['requests' => $query->orderByDesc('d.request_id')->paginate(20)->withQueryString()]);
    }

    public function available(Request $request)
    {
        return response()->json(['requests' => $this->query($request)->where('d.status', 'approved')->whereNull('c.sale_id')->orderByDesc('d.request_id')->limit(30)->get(['d.request_id'])]);
    }

    public function store(DiscountApprovalRequest $request, DiscountApprovalService $service)
    {
        $id = $service->request(StaffContext::posId($request), $request->validated());

        return response()->json(['request_id' => $id, 'status' => 'pending'], 201);
    }

    public function cancel(Request $request, int $id, DiscountApprovalService $service)
    {
        $service->cancel(StaffContext::posId($request), $id);

        return $request->expectsJson() ? response()->json(['status' => 'cancelled']) : back()->with('status', 'Discount request cancelled.');
    }

    public function decide(Request $request, int $id, DiscountApprovalService $service)
    {
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected'], 'reason' => ['nullable', 'string', 'max:255']]);
        $service->decide(StaffContext::posId($request), $id, $data['decision'], $data['reason'] ?? null);

        return $request->expectsJson() ? response()->json(['status' => $data['decision']]) : back()->with('status','Finance decision recorded.');
    }
}
