@extends('layouts.base')
@section('title','Printable product labels')
@section('body')
<main id="main" class="label-print-page"><header class="label-print-toolbar no-print"><div><x-brand/><h1>{{ $labels->count() }} {{ $kind==='shelf'?'shelf':'barcode' }} labels ready.</h1><p>Print on A4 paper. Shelf QR codes open the public product page.</p></div><div><a class="button button-outline" href="{{ route('labels.index') }}">Back to selection</a><button class="button" data-print>Print labels</button></div></header>
<section class="product-label-grid {{ $kind==='shelf'?'shelf-label-grid':'barcode-label-grid' }}" aria-label="Printable labels">
@foreach($labels as $label)<article class="product-label" data-product-id="{{ $label['product']->id }}">
<div class="label-code-art">@if($label['svg']){!! $label['svg'] !!}@else<p class="barcode-text">{{ $label['product']->barcode ?: 'No barcode recorded' }}</p><small>Barcode needs a valid EAN-13 value.</small>@endif</div>
<div class="product-label-info"><strong>{{ $label['product']->product_name }}</strong><span>{{ $label['product']->category }}</span><b>PHP {{ $label['price'] }}</b>@if($kind==='shelf')<small>Scan for product information</small>@endif</div>
</article>@endforeach
</section></main>
@endsection
