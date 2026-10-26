@php($money = static fn($value) => '₱'.number_format((float)$value,2))
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $display['product_name'] }} - Product details</title>







@vite(['resources/css/legacy/product.css','resources/js/legacy/product.js'])
</head>
<body>
<nav class="navbar">
    <div class="nav-left">
        <a href="{{ $detailBackHref }}" class="back-btn"><svg viewBox="0 0 20 20" fill="none"><path d="M12 4l-6 6 6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>{{ $detailBackLabel }}</a>
        <div class="nav-divider"></div>
        <a class="nav-brand" href="{{ $detailBackHref }}">
            <div class="nav-logo"><svg viewBox="0 0 48 48" aria-hidden="true"><path d="M10 18h28l-2.5 18.5a3 3 0 0 1-3 2.5h-17a3 3 0 0 1-3-2.5L10 18z" fill="#fff"/><path d="M10 18h28v4a2 2 0 0 1-2 2H12a2 2 0 0 1-2-2v-4z" fill="#dbeafe"/><path d="M16 18c0-5 3.5-9 8-9s8 4 8 9" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/></svg></div>
            <span class="nav-title">Manage <span>Grocery</span></span>
        </a>
    </div>
    <div class="nav-search"><form method="get" action="{{ $productSearchAction }}">@if($searchField === 'search')<input type="hidden" name="private" value="{{ $catalogPrivate ? '1' : '0' }}">@endif<input class="search-input" name="{{ $searchField }}" placeholder="Search products..." aria-label="Search products"><button class="btn-search" type="submit">Search</button></form></div>
    <button class="theme-toggle" type="button" aria-label="Switch to dark mode" aria-pressed="false"><svg class="moon-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20.4 15.4A8.5 8.5 0 0 1 8.6 3.6 9 9 0 1 0 20.4 15.4Z" stroke-linecap="round" stroke-linejoin="round"/></svg><svg class="sun-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4" stroke-linecap="round"/></svg><span>Dark</span></button>
</nav>
<main class="wrap">
    <section class="detail">
        <div class="photo">
            @php($image = $productImage)
            @if($image)<img src="{{ $image }}" alt="{{ $display['product_name'] }}">@else<div class="placeholder" aria-hidden="true">🛒</div>@endif
        </div>
        <div class="detail-summary">
            <h1>{{ $display['product_name'] }}</h1>
            <div class="price">{{ $money($price) }}@if($hasDiscount)<del class="muted">{{ $money($display['price']) }}</del>@endif</div>
            <div class="stock" style="--original-stock-color:{{ $display['stock'] > 0 ? '#15803d' : '#b91c1c' }}">{{ $stockLabel }}</div>
            @if(!$detailsAreVerified)
                <div class="notice">This product’s details are still awaiting human verification and are not yet published for public viewing.</div>
            @endif
            @if($detailsAreVerified && ($display['calories'] !== null || $display['protein_g'] !== null || $display['carbs_g'] !== null || $display['fat_g'] !== null || $display['sugar_g'] !== null || $display['sodium_mg'] !== null))
                <div class="nutrition-hero">
                    <h2>Nutrition facts</h2>
                    @if((string) ($display['serving_size'] ?? '') !== '')
                        <p>Serving size: {{ $display['serving_size'] }}@if($display['servings_per_pack'] !== null) · {{ $display['servings_per_pack'] }} servings per pack
                        @endif</p>
                    @endif
                    <table class="nutrition-table"><tbody>
                        @foreach(['calories' => 'Calories', 'protein_g' => 'Protein (g)', 'carbs_g' => 'Carbohydrates (g)', 'fat_g' => 'Fat (g)', 'sugar_g' => 'Sugar (g)', 'sodium_mg' => 'Sodium (mg)'] as $key => $label)
                            @if($display[$key] !== null)<tr><td>{{ $label }}</td><td>{{ rtrim(rtrim(number_format((float) $display[$key], 2, '.', ''), '0'), '.') }}</td></tr>@endif
                        @endforeach
                    </tbody></table>
                    @if((string) ($display['nutrition_basis'] ?? '') !== '')<p class="source-note">Values are {{ str_replace('_', ' ', $display['nutrition_basis']) }}@if((string) ($display['nutrition_source_url'] ?? '') !== '') · <a href="{{ $display['nutrition_source_url'] }}" target="_blank" rel="noopener noreferrer">View source</a>@endif</p>@endif
                </div>
            @endif
            @php($hasDetails = $detailsAreVerified && ((string) ($display['description'] ?? '') !== '' || (string) ($display['ingredients'] ?? '') !== '' || (string) ($display['allergens'] ?? '') !== '' || (string) ($display['storage_notes'] ?? '') !== ''))
        </div>
    </section>
    @if($hasDetails)
        <section class="detail-info">
            @if((string) ($display['description'] ?? '') !== '' || (string) ($display['brand'] ?? '') !== '' || (string) ($display['unit_name'] ?? '') !== '' || (string) ($display['net_weight'] ?? '') !== '')
                <div class="info-card">
                    <h2>Product information</h2>
                    @if((string) ($display['description'] ?? '') !== '')<p>{!! nl2br(e($display['description'])) !!}</p>@endif
                    <div class="info-grid">
                        @foreach(['brand' => 'Brand', 'unit_name' => 'Unit', 'net_weight' => 'Net weight'] as $key => $label)
                            @if((string) ($display[$key] ?? '') !== '')<div class="info-item"><strong>{{ $label }}</strong><span>{{ $display[$key] }}</span></div>@endif
                        @endforeach
                    </div>
                </div>
            @endif
            @if((string) ($display['ingredients'] ?? '') !== '' || (string) ($display['allergens'] ?? '') !== '' || (string) ($display['storage_notes'] ?? '') !== '')
                <div class="info-card">
                    <h2>Ingredients, allergens &amp; storage</h2>
                    <div class="info-grid">
                        @foreach(['ingredients' => 'Ingredients', 'allergens' => 'Allergens', 'storage_notes' => 'Storage notes'] as $key => $label)
                            @if((string) ($display[$key] ?? '') !== '')<div class="info-item"><strong>{{ $label }}</strong><span>{!! nl2br(e($display[$key])) !!}</span></div>@endif
                        @endforeach
                    </div>
                </div>
            @endif
        </section>
    @endif
    @if($relatedProducts)
        <h2>Related products</h2>
        <div class="related">
            @foreach($relatedProducts as $item)
                <a href="{{ route('product',array_filter(['id'=>$item['id'],'return'=>$returnKey],fn($value)=>$value!=='')) }}">
                    @php($relatedImage = $item['image_url'])
                    @if($relatedImage)<img src="{{ $relatedImage }}" alt="">@endif
                    <strong>{{ $item['product_name'] }}</strong>
                    <span>{{ $money($item['price']) }}</span>
                    <small class="related-stock">{{ (int) $item['stock'] }} left</small>
                </a>
            @endforeach
        </div>
    @endif
</main>

</body>
</html>
