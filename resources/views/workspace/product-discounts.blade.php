@extends('layouts.workspace')
@section('title','Product discounts')
@section('content')
<div class="page-heading"><div><span class="eyebrow">APPROVED PRICES, CLEAR HISTORY</span><h1>Product discounts.</h1><p>{{ $role==='finance'?'Review product discount changes before they reach the catalog and checkout.':'Propose a product discount, then track its Finance decision.' }}</p></div></div>
@if($role!=='finance')
<section class="panel product-discount-request"><div class="panel-heading"><h2>Request a discount change</h2></div>
<form method="get" class="filter-bar"><label class="search-field">Find a product<input name="q" value="{{ request('q') }}" maxlength="100" placeholder="Product name or exact barcode"></label><button class="button button-outline button-small">Find products</button></form>
<form method="post" action="{{ route('product-discounts.store') }}" class="form-panel" data-busy>
    @csrf
    <div class="form-grid"><label>Product<select name="product_id" required><option value="">Choose a product</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string)old('product_id',request('product_id'))===(string)$product->id)>{{ $product->product_name }} — PHP {{ number_format((float)$product->price,2) }}</option>@endforeach</select></label>
    <label>Proposed discount<select name="discount_type"><option value="percent">Percentage</option><option value="fixed" @selected(old('discount_type')==='fixed')>Fixed amount per unit</option><option value="none" @selected(old('discount_type')==='none')>Remove product discount</option></select></label>
    <label>Discount value<input name="discount_value" type="number" min="0" step="0.01" max="99999999.99" value="{{ old('discount_value','10.00') }}" required></label><label>Business reason<input name="discount_reason" maxlength="255" value="{{ old('discount_reason') }}"></label></div>
    <p class="form-note">For removal, enter zero. Other fixed amounts must be below the selling price; percentages can be at most 100. Current prices stay in place until approval.</p><div class="form-actions"><button class="button">Send product discount request</button></div>
</form></section>
@endif
<section class="panel"><form method="get" class="filter-bar"><label>Request status<select name="status">@foreach(['pending'=>'Awaiting decision','approved'=>'Approved','rejected'=>'Rejected','cancelled'=>'Cancelled','all'=>'All requests'] as $value=>$label)<option value="{{ $value }}" @selected(request('status','pending')===$value)>{{ $label }}</option>@endforeach</select></label><button class="button button-small">Filter requests</button></form>
<p class="form-note product-discount-scroll-note">Scroll across the table to see proposals, reasons and available actions.</p><div class="table-wrap" role="region" aria-label="Product discount requests" tabindex="0"><table><thead><tr><th>Request / product</th><th>Price at request</th><th>Proposal</th><th>Status / reason</th><th>Action</th></tr></thead><tbody>
@forelse($requests as $item)
    @php($candidate=(object)['price'=>$item->price_at_request,'discount_type'=>$item->discount_type,'discount_value'=>$item->discount_value])
    <tr data-product-request-id="{{ $item->request_id }}"><td><strong>#{{ $item->request_id }} · {{ $item->product_name ?: 'Product unavailable' }}</strong><small class="muted block">{{ $item->requested_at }}</small><small class="muted block">{{ (int)$item->requested_by===$actor?'Your request':'Requested by POS #'.$item->requested_by }}</small></td>
    <td>PHP {{ number_format((float)$item->price_at_request,2) }}<small class="muted block">Previous discount: {{ $item->old_discount_type }} {{ number_format((float)$item->old_discount_value,2) }}</small></td><td>{{ $item->discount_type==='none'?'Remove discount':($item->discount_type==='percent'?number_format((float)$item->discount_value,2).'%':'PHP '.number_format((float)$item->discount_value,2).' off') }}<small class="muted block">Proposed price: PHP {{ \App\Services\PosService::money(\App\Services\PosService::unitPriceCents($candidate)) }}</small></td>
    <td><span class="badge {{ $item->status==='pending'?'badge-warning':'' }}">{{ ucfirst($item->status) }}</span><p>{{ $item->discount_reason ?: 'No business reason listed' }}</p>@if($item->reject_reason)<small class="muted block">Finance: {{ $item->reject_reason }}</small>@endif</td><td>
    @if($item->status==='pending')
        @if(in_array($role,['admin','finance']))
        <div class="discount-decisions"><form method="post" action="{{ route('product-discounts.decide',$item->request_id) }}" data-confirm="Approve and apply this product discount?" data-busy>@csrf<input type="hidden" name="decision" value="approved"><button class="button button-small">Approve product request #{{ $item->request_id }}</button></form><form method="post" action="{{ route('product-discounts.decide',$item->request_id) }}" data-busy>@csrf<input type="hidden" name="decision" value="rejected"><label class="sr-only" for="product-reject-{{ $item->request_id }}">Rejection reason for product request {{ $item->request_id }}</label><input id="product-reject-{{ $item->request_id }}" name="reason" maxlength="255" placeholder="Optional rejection reason"><button class="button button-outline button-small">Reject product request #{{ $item->request_id }}</button></form></div>
        @endif
        @if($role==='admin'||($role==='inventory_staff'&&(int)$item->requested_by===$actor))
        <form method="post" action="{{ route('product-discounts.cancel',$item->request_id) }}" data-confirm="Cancel this product discount request?" data-busy>@csrf<button class="text-button danger">Cancel product request #{{ $item->request_id }}</button></form>
        @endif
    @else<span class="muted">{{ $item->decided_at ?: '-' }}</span>
    @endif
    </td></tr>
@empty<tr><td colspan="5" class="empty-state">No product discount requests match this status.</td></tr>
@endforelse
</tbody></table></div><div class="pagination">{{ $requests->links() }}</div></section>
@endsection
