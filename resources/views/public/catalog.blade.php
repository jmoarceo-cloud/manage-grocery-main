<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Product Catalog — Grocery Store</title>




@include('legacy.login-theme-init')




@vite(['resources/css/legacy/catalog.css','resources/js/legacy/catalog.js'])
</head>
<body class="catalog-page">

<!-- ========== NAVBAR ========== -->
<nav class="navbar">
    <div class="nav-left">
        <a href="{{ route('home') }}" class="back-btn" >
            <svg viewBox="0 0 20 20" fill="none">
                <path d="M12 4l-6 6 6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Back to Home
        </a>
        <div class="nav-divider"></div>
        <div class="nav-brand">
            <div class="nav-logo">
                <svg viewBox="0 0 48 48" aria-hidden="true">
                    <path d="M10 18h28l-2.5 18.5a3 3 0 0 1-3 2.5h-17a3 3 0 0 1-3-2.5L10 18z" fill="#ffffff"/>
                    <path d="M10 18h28v4a2 2 0 0 1-2 2H12a2 2 0 0 1-2-2v-4z" fill="#dbeafe"/>
                    <path d="M16 18c0-5 3.5-9 8-9s8 4 8 9" fill="none" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"/>
                </svg>
            </div>
            <span class="nav-title">Manage <span>Grocery</span></span>
        </div>

            <a class="private-access" href="{{ rtrim(config('app.legacy_url'),'/') }}/catalog_supervisor_login.php?embedded=1" data-private-login aria-label="Open private catalog access">Private access</a>

    </div>


    <button class="theme-toggle" id="themeToggle" type="button" aria-label="Switch to dark mode" aria-pressed="false">
        <span>Dark mode</span>
    </button>
</nav>

<div class="access-modal" id="accessModal" role="dialog" aria-modal="true" aria-label="Private catalog access">
    <div class="access-dialog">
        <button class="access-close" type="button" data-close-access aria-label="Close employee access">&times;</button>
        <iframe id="accessFrame" title="Supervisor password access" loading="lazy"></iframe>
    </div>
</div>

<!-- ========== HERO ========== -->
<header class="hero">
    <div class="hero-content">
        <div class="hero-tag">Fresh &amp; Packaged Catalog</div>
        <h1>Shop at <span class="accent">Grocery Store</span></h1>
        <p>Browse our full inventory before visiting the store. Out-of-stock items are clearly flagged in real-time so you plan every trip effortlessly.</p>
    </div>
</header>

<!-- ========== STORE INFO ========== -->
<section class="store-info-bar">
    <div class="store-info-item">
        📍 <span><strong>Store:</strong> Philippine Christian University (PCU), Dasmariñas, Cavite</span>
    </div>
    <div class="store-info-item">
        📞 <span><strong>Hotline:</strong> 09948966218</span>
    </div>
    <div class="store-info-item">
        🕒 <span><strong>Store Hours:</strong> 6:00 AM – 5:00 PM Mon to Sun</span>
    </div>
</section>

<!-- ========== PAGE BODY ========== -->
<div class="page-body">

    <aside class="filter-sidebar">
        <div class="filter-sidebar-head">
            <h3>Categories</h3>
            <span class="total-badge">Total: {{ $totalProducts }}</span>
        </div>
        <div class="filter-list">
            <a href="{{ route('catalog', $search !== '' ? ['search'=>$search] : []) }}"
               class="filter-item {{ $category === '' ? 'active' : '' }}">
                All Products
                <span class="count">{{ $totalProducts }}</span>
            </a>
            @foreach($categories as $cat)
@php($cnt = $categoryCounts[$cat->category_name] ?? 0)
@continue($cnt == 0)
                <a href="{{ route('catalog', array_filter(['category'=>$cat->category_name,'search'=>$search], fn($value)=>$value!=='')) }}"
                   class="filter-item {{ $category === $cat->category_name ? 'active' : '' }}">
                    {{ $cat->category_name }}
                    <span class="count">{{ $cnt }}</span>
                </a>
            @endforeach
        </div>
        <div class="filter-sidebar-head" style="margin-top:20px">
            <h3>Allergen safety</h3>
        </div>
        <form method="get" class="filter-list">
            @if($search !== '')<input type="hidden" name="search" value="{{ $search }}">@endif
            @if($category !== '')<input type="hidden" name="category" value="{{ $category }}">@endif
            <label style="font-size:.75rem;color:var(--text-muted)" for="exclude_allergen">Hide products containing</label>
            <select id="exclude_allergen" name="exclude_allergen" onchange="this.form.submit()" style="padding:9px;border:1px solid var(--border);border-radius:10px;background:var(--surface);color:var(--text-body)">
                <option value="">No exclusion</option>
                @foreach($allowedAllergens as $allergen)
                    <option value="{{ $allergen }}" @selected($excludeAllergen === $allergen)>{{ ucwords($allergen) }}</option>
                @endforeach
            </select>
            @if($excludeAllergen !== '')<div class="notice" role="note">Filtered on verified label information only. Products without verified allergen data are hidden from these results. Always check the package.</div>@endif
        </form>
    </aside>

    <div class="catalog-area">
        <div class="catalog-header">
            <div>
                <h2>
                    @if($category !== '')
                        {{ $category }}
                    @elseif($search !== '')
                        Results for "{{ $search }}"
                    @else
                        All Products
                    @endif
                </h2>
                <p>{{ $products->count() }} product{{ $products->count() != 1 ? 's' : '' }} found</p>
            </div>
            <form method="GET" action="{{ route('catalog') }}" id="catalogSearchForm" class="catalog-search-form">
                @if($category !== '')<input type="hidden" name="category" value="{{ $category }}">@endif
                <input type="text" name="search" id="catalogSearchInput" class="search-input" placeholder="Search products..." value="{{ $search }}">
                <button type="submit" class="btn-search">Search</button>
            </form>
            @if($search !== '' || $category !== '' || $excludeAllergen !== '')
                <a href="{{ route('catalog') }}" class="clear-link">Clear filters</a>
            @endif
        </div>

        <div class="product-grid">
            @if($products->isEmpty())
                <div class="empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">
                        <circle cx="11" cy="11" r="8"/>
                        <path d="m21 21-4.35-4.35"/>
                    </svg>
                    <h3>No products found</h3>
                    <p>Try a different search term or category.</p>
                    <a href="{{ route('catalog') }}">View all products</a>
                </div>
            @else
                @foreach($products as $p)
@php($in_stock = $p->sellable_stock > 0)
@php($has_image = $p->has_catalog_image)
                    <a class="product-card {{ $in_stock ? '' : 'out-of-stock' }}"
                       href="{{ route('product',$p->id) }}"
                       aria-label="View {{ $p->product_name }}">
                        <div class="product-img">
                            @if($has_image)
                                <img src="{{ route('product.image',$p->id) }}" alt="{{ $p->product_name }}">
                            @else
                                <div class="product-img-placeholder">🛒</div>
                            @endif
                            @if(!$in_stock)
                                <div class="out-of-stock-overlay"><span>Out of Stock</span></div>
                            @endif
                        </div>
                        <div class="product-body">
                            <div class="product-name">{{ $p->product_name }}</div>
                            <div class="product-price">&#8369;{{ number_format((float)$p->price,2) }}</div>
                            @if($in_stock)
                                <span class="product-available">{{ (int)$p->sellable_stock }} left</span>
                            @else
                                <span class="product-outofstock-badge">Out of stock</span>
                            @endif
                        </div>
                    </a>
                @endforeach
            @endif
        </div>
    </div>
</div>

<!-- ========== FOOTER ========== -->
<footer class="footer">
    <strong>HCI GroceryOne</strong> &mdash; Your Everyday Needs &mdash;
    &copy; {{ date('Y') }} All rights reserved.
</footer>


</body>

</html>
