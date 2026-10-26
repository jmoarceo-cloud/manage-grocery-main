@extends('layouts.workspace')
@section('title','Product information')
@section('content')
@php($state=$details->review_status??'draft')
<div class="page-heading"><div><span class="eyebrow">REVIEW BEFORE IT REACHES THE SHELF</span><h1>{{ $product->product_name }}</h1><p>Product description and label information <span class="badge">{{ str_replace('_',' ',ucfirst($state)) }}</span></p></div><a class="button button-outline" href="{{ route('manage.edit',['products',$product->id]) }}">Back to product</a></div>
@if(!$product->is_active)
    <div class="notice notice-error">This product is inactive. Its information and review history remain available; reactivate it before making changes.</div>
@endif
@if($details->rejected_reason??null)
    <div class="notice notice-error"><strong>Admin review:</strong> {{ $details->rejected_reason }}</div>
@endif
<div class="information-grid"><section class="panel"><div class="panel-heading"><h2>Edit product description</h2></div>
<form method="post" action="{{ route('product-information.update',$product->id) }}" class="form-panel" data-information-editor data-has-old-input="{{ session()->hasOldInput('description')?'1':'0' }}">
    @csrf
    <input type="hidden" name="revision" value="{{ $revision }}">
    <label>Description<textarea name="description" maxlength="5000" rows="5">{{ old('description',$details->description??'') }}</textarea></label>
    <div class="form-grid"><label>Brand<input name="brand" maxlength="100" value="{{ old('brand',$details->brand??'') }}"></label><label>Unit name<input name="unit_name" maxlength="50" value="{{ old('unit_name',$details->unit_name??'') }}"></label></div>
    <label>Storage notes<textarea name="storage_notes" maxlength="5000" rows="4">{{ old('storage_notes',$details->storage_notes??'') }}</textarea></label>
    <p class="form-note">Changing verified details removes them from public view until Admin verifies the updated information.</p>
    @if($product->is_active)
    <div class="information-actions"><button class="button button-outline" name="action" value="save">Save changes</button>
        @if(in_array($state,['draft','ai_filled','needs_review']))<button class="button" name="action" value="submit">Submit for review</button>@endif
        @if($state==='rejected')<button class="button" name="action" value="resubmit">Resubmit for review</button>@endif
    </div>
    @endif
</form></section>
<section class="panel"><div class="panel-heading"><h2>Recorded label information</h2><p class="muted">Review these values against the packaging before verification.</p></div><dl class="information-recorded">
@foreach(\App\Services\ProductInformationService::RECORDED as $field=>$label)<dt>{{ $label }}</dt><dd>@if($field==='nutrition_source_url'){{ $sourceReference ?: 'Not listed' }}@else{{ ($details->$field??null)===null||($details->$field??'')===''?'Not listed':($field==='nutrition_basis'?\App\Services\PublicProductFacts::basis($details->$field):$details->$field) }}@endif</dd>@endforeach
@foreach(\App\Services\PublicProductFacts::NUTRIENTS as $field=>$label)<dt>{{ $label }}</dt><dd>{{ ($details->$field??null)===null?'Not listed':number_format((float)$details->$field,2) }}</dd>@endforeach
</dl><p class="information-note">Nutrition, ingredient, photo and AI changes currently use the preserved label-information workflow. A missing value is not zero.</p></section></div>
@if($admin&&$state==='needs_review'&&$product->is_active)
<section class="panel information-review"><div class="panel-heading"><h2>Admin review</h2><p class="muted">Verify the saved description and recorded label information. Save your edits before making a decision.</p></div>
<p class="information-note" data-information-unsaved role="status" hidden>Save or reload your changes before verifying or rejecting saved information.</p>
@if($webFields)
<p class="information-note notice-error">Web-sourced fields require label-information review before verification. They remain hidden from the public.</p>
@else
<form method="post" action="{{ route('product-information.update',$product->id) }}" class="form-panel" data-information-decision data-confirm="Verify this saved product information for public display?" data-busy>@csrf<input type="hidden" name="revision" value="{{ $revision }}"><input type="hidden" name="action" value="verify"><button class="button">Verify saved information</button></form>
@endif
<form method="post" action="{{ route('product-information.update',$product->id) }}" class="form-panel" data-information-decision data-busy>@csrf<input type="hidden" name="revision" value="{{ $revision }}"><input type="hidden" name="action" value="reject"><label>Rejection reason<textarea name="rejected_reason" maxlength="1000" rows="3" required>{{ old('rejected_reason') }}</textarea></label><button class="button button-outline">Reject information</button></form></section>
@endif
<section class="panel information-history"><div class="panel-heading"><h2>Recent review history</h2></div><div class="table-wrap"><table><thead><tr><th>Action</th><th>Result</th><th>Source</th><th>Time</th></tr></thead><tbody>@forelse($history as $entry)<tr><td>{{ ucfirst($entry->action) }}</td><td>{{ str_replace('_',' ',$entry->review_status??'-') }}</td><td>{{ $entry->source }}</td><td>{{ $entry->changed_at }}</td></tr>@empty<tr><td colspan="4" class="empty-state">No review activity yet.</td></tr>@endforelse</tbody></table></div></section>
@endsection
