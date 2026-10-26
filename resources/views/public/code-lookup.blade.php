@extends('layouts.public')
@section('title','Find by barcode - GroceryOne')
@section('content')
<section class="public-section code-lookup-page">
    <a class="text-link" href="{{ route('catalog') }}">&#8592; Back to catalog</a>
    <div class="code-lookup-heading"><span class="eyebrow">FROM PACKAGE TO PRODUCT</span><h1>Find it by code.</h1><p>Enter the barcode or store code printed on the product to see its current price, availability and verified label information.</p></div>
    <div class="code-lookup-grid">
        <section class="panel code-lookup-card" aria-labelledby="code-lookup-title">
            <div class="panel-heading"><h2 id="code-lookup-title">Your product code</h2></div>
            <form method="get" action="{{ route('catalog.scan') }}" class="form-panel">
                @if($notice)
                    <div class="notice notice-error" role="alert">{{ $notice }}</div>
                @endif
                <label>Barcode or store code<input name="b" value="{{ $code }}" inputmode="text" autocomplete="off" autocapitalize="off" spellcheck="false" minlength="3" maxlength="64" pattern="[0-9A-Za-z._\-]{3,64}" required aria-describedby="product-code-help" autofocus></label>
                <p id="product-code-help" class="form-note">Keep any leading zeros. Store codes can include letters, numbers, dots, underscores and hyphens.</p>
                <button class="button">Find product <span aria-hidden="true">&#8594;</span></button>
            </form>
        </section>
        <aside class="code-lookup-guide"><span class="eyebrow">A LITTLE HELP FINDING IT</span><h2>Look below the bars.</h2><svg viewBox="0 0 240 96" role="img" aria-label="Illustration showing a barcode above its printed code"><path d="M20 8v57m5-57v57m10-57v50m9-50v50m5-50v50m12-50v50m7-50v50m10-50v50m5-50v50m13-50v50m6-50v50m13-50v57m5-57v57m12-57v50m6-50v50m10-50v50m8-50v50m6-50v50m12-50v50m8-50v50m7-50v50m11-50v57m5-57v57" fill="none" stroke="currentColor" stroke-width="3"/><text x="120" y="87" text-anchor="middle" font-size="13" letter-spacing="3" fill="currentColor">YOUR PRODUCT CODE</text></svg><p>Type the characters shown beneath the barcode. A connected handheld scanner can also enter the code into this field.</p><p>For a shelf QR label, open its product link directly. You can always browse by product name instead.</p><a class="text-link" href="{{ route('catalog') }}">Browse all products &#8599;</a></aside>
    </div>
</section>
@endsection
