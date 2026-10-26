@extends('layouts.workspace')
@section('title','Inventory')
@section('content')
<div class="page-heading"><div><span class="eyebrow">EVERY ITEM ACCOUNTED FOR</span><h1>Inventory, in view.</h1><p>Review stock and batches. Direct restocking requires administrator access.</p></div><a class="button button-outline" href="/manage-grocery-main/project/inventory_requests">Stock requests &#8599;</a></div>
<section class="panel">
<div class="panel-heading"><h2>Current stock</h2><span class="count-chip">{{ $products->total() }} products</span></div>
<form method="get" class="filter-bar" role="search"><label class="sr-only" for="inventory-search">Search inventory</label><input id="inventory-search" name="q" value="{{ request('q') }}" placeholder="Search product or barcode" maxlength="100"><label class="sr-only" for="inventory-level">Stock level</label><select name="level" id="inventory-level"><option value="all">All stock levels</option><option value="low" @selected(request('level')==='low')>Low stock</option><option value="out" @selected(request('level')==='out')>Out of stock</option></select><button class="button button-small">Filter</button><a class="text-link" href="{{ route('inventory') }}">Clear</a></form>
<div class="table-wrap"><table><thead><tr><th>Product</th><th>Category</th><th class="number">On hand</th><th>Stock level</th></tr></thead><tbody>
@forelse($products as $product)<tr><td>{{ $product->product_name }}</td><td>{{ $product->category ?: '-' }}</td><td class="number">{{ $product->stock }}</td><td><span class="badge {{ $product->stock<=10?'badge-warning':'badge-success' }}">{{ $product->stock<=0?'Out of stock':($product->stock<=10?'Low stock':'Available') }}</span></td></tr>@empty<tr><td colspan="4" class="empty-state">No products match these filters.</td></tr>@endforelse
</tbody></table></div><div class="pagination">{{ $products->links() }}</div></section>
<section class="panel"><div class="panel-heading"><div><span class="eyebrow">BATCH WATCH</span><h2>Expiry in the next 30 days</h2></div></div><div class="table-wrap"><table><thead><tr><th>Product</th><th>Batch</th><th>Expiry</th><th class="number">Remaining</th><th>Status</th></tr></thead><tbody>
@forelse($batches as $batch)<tr><td>{{ $batch->product_name }}</td><td>#{{ $batch->id }}</td><td>{{ $batch->expiry_date }}</td><td class="number">{{ $batch->quantity_remaining }}</td><td><span class="badge badge-warning">{{ $batch->expiry_date < now()->toDateString()?'Expired · do not sell':'Expiring soon' }}</span></td></tr>@empty<tr><td colspan="5" class="empty-state">No batches expiring within 30 days.</td></tr>@endforelse
</tbody></table></div></section>
@if(request()->attributes->get('staff')->role==='admin')
<section class="panel"><div class="panel-heading"><div><span class="eyebrow">ADMINISTRATOR ACTION</span><h2>Record a direct restock</h2></div></div>
<form method="post" action="{{ route('inventory.restock') }}" class="form-panel" data-confirm="Record this restock and its inventory cost?" data-busy>@csrf<div class="form-grid">
<label>Product<select name="product_id" aria-label="Product" required><option value="">Choose a product</option>@foreach($restockOptions as $product)<option value="{{ $product->id }}" @selected((string)old('product_id')===(string)$product->id)>{{ $product->product_name }} · {{ $product->stock }} on hand</option>@endforeach</select></label>
<label>Quantity<input name="quantity" value="{{ old('quantity') }}" type="number" min="1" max="1000000" required></label><label>Unit cost<input name="cost_price" value="{{ old('cost_price') }}" type="number" min="0" max="99999999.99" step="0.01" required></label><label>Batch expiry<input name="expiry_date" value="{{ old('expiry_date') }}" type="date"></label><label class="full-width">Reason<input name="reason" value="{{ old('reason') }}" required maxlength="255"></label></div><div class="form-actions"><button class="button">Record restock</button></div></form></section>
@endif
@endsection
