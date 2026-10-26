<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductInformationRequest;
use App\Services\ProductInformationService;
use App\Services\PublicProductFacts;
use App\Services\StaffContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductInformationController extends Controller
{
    public function edit(Request $request, int $id)
    {
        $db = DB::connection('pos');
        $product = $db->table('products')->where('id', $id)->first(['id', 'product_name', 'barcode', 'category', 'is_active']);
        abort_unless($product, 404);
        $details = $db->table('product_details')->where('product_id', $id)->first();
        $context = ProductInformationService::context($id);

        return view('manage.product-information', ['product' => $product, 'details' => $details, 'sourceReference' => PublicProductFacts::safeSourceUrl($details->nutrition_source_url ?? null), 'revision' => ProductInformationService::revision($id, $details, $context), 'webFields' => ($details->nutrition_source ?? null) === 'web' || $context['web_fields'], 'admin' => $request->attributes->get('staff')->role === 'admin', 'history' => $db->table('product_detail_audit')->where('product_id', $id)->orderByDesc('audit_id')->limit(10)->get(['action', 'source', 'review_status', 'changed_at'])]);
    }

    public function update(ProductInformationRequest $request, int $id, ProductInformationService $service)
    {
        try {
            $state = $service->change(StaffContext::posId($request), $id, $request->validated());
        } catch (ValidationException $exception) {
            if (! $request->expectsJson() && isset($exception->errors()['revision'])) {
                $request->session()->forget('_old_input');

                return redirect()->route('product-information.edit', $id)->withErrors($exception->errors());
            }
            throw $exception;
        }

        return $request->expectsJson() ? response()->json(['review_status' => $state]) : redirect()->route('product-information.edit', $id)->with('status', match ($request->validated('action')) {
            'verify' => 'Product information verified.', 'reject' => 'Product information rejected.',
            'save' => 'Changes saved. Updated verified information needs review before publication.', default => 'Product information submitted for review.',
        });
    }
}
