<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nutrition comparison - Grocery Store</title>



@vite(['resources/css/legacy/compare.css','resources/js/legacy/compare.js'])
</head>
<body>
<main class="wrap">
<nav class="navbar">
        <div class="nav-left">
            <a href="{{ route('home') }}" class="back-btn"><svg viewBox="0 0 20 20" fill="none"><path d="M12 4l-6 6 6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>Back to Home</a>
            <div class="nav-divider"></div>
            <a class="nav-brand" href="{{ route('catalog') }}"><div class="nav-logo"><svg viewBox="0 0 48 48" aria-hidden="true"><path d="M10 18h28l-2.5 18.5a3 3 0 0 1-3 2.5h-17a3 3 0 0 1-3-2.5L10 18z" fill="#fff"/><path d="M10 18h28v4a2 2 0 0 1-2 2H12a2 2 0 0 1-2-2v-4z" fill="#dbeafe"/><path d="M16 18c0-5 3.5-9 8-9s8 4 8 9" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/></svg></div><span class="nav-title">Manage <span>Grocery</span></span></a>
        </div>
        <div class="nav-search"><form method="get" action="{{ route('catalog') }}"><input class="search-input" name="search" placeholder="Search products..." aria-label="Search products"><button class="btn-search" type="submit">Search</button></form></div>
        <button class="theme-toggle" type="button" aria-label="Switch to dark mode" aria-pressed="false"><svg class="moon-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20.4 15.4A8.5 8.5 0 0 1 8.6 3.6 9 9 0 1 0 20.4 15.4Z" stroke-linecap="round" stroke-linejoin="round"/></svg><svg class="sun-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4" stroke-linecap="round"/></svg><span>Dark</span></button>
    </nav>
    <h1>Nutrition comparison</h1>
    <p class="muted">Only active products with verified label information can be compared.</p>
    @if($errors->any())<section class="panel notice" role="alert">{{ $errors->first() }}</section>@endif
    <section class="panel">
        <form method="get" class="picker">
            @for($index=0;$index<3;$index++)
                <label>Product {{ $index+1 }}
                    <select name="ids[]" aria-label="Comparison product {{ $index+1 }}">
                        <option value="">Choose a product</option>
                        @foreach($choices as $choice)
                            <option value="{{ $choice->product_id }}" @selected(($selected[$index]??null)===(int)$choice->product_id)>{{ $choice->product_name }}</option>
                        @endforeach
                    </select>
                </label>
            @endfor
            <button type="submit">Compare products</button>
        </form>
    </section>
    @if($notice)
        <section class="panel notice" role="status">{{ $notice }}</section>
    @elseif($facts->isNotEmpty())
        <section class="panel table-wrap" tabindex="0" role="region" aria-label="Nutrition comparison table">
            <table class="compare comparison-table">
                <thead><tr><th scope="col">Nutrient</th>@foreach($facts as $product)<th scope="col">{{ $product->product_name }}@if($product->brand)<br><span class="muted">{{ $product->brand }}</span>@endif</th>@endforeach</tr></thead>
                <tbody>
                    @foreach(['serving_size'=>'Serving size','calories'=>'Calories (kcal)','protein_g'=>'Protein (g)','carbs_g'=>'Carbohydrates (g)','fat_g'=>'Fat (g)','sugar_g'=>'Sugar (g)','sodium_mg'=>'Sodium (mg)','allergens'=>'Allergens'] as $field=>$label)
                        @php
                            $numeric=in_array($field,['calories','protein_g','carbs_g','fat_g','sugar_g','sodium_mg'],true);
                            $values=$numeric ? $facts->pluck($field)->filter(fn($value)=>$value!==null&&$value!=='')->map(fn($value)=>(float)$value) : collect();
                            $minimum=$values->count()>1 ? $values->min() : null;
                        @endphp
                        <tr><th scope="row">{{ $label }}</th>@foreach($facts as $product)
                            @php($value=$product->$field)
                            @php($isMinimum=$minimum!==null&&$value!==null&&$value!==''&&abs((float)$value-$minimum)<0.0001)
                            <td @class(['best'=>$isMinimum])>{{ $value===null||$value===''?'Not listed':$value }}</td>@endforeach</tr>
                    @endforeach
                </tbody>
            </table>
            <p class="muted">Values are shown as verified from the product label. “Not listed” is not a zero.</p>
        </section>
    @endif
</main>
</body>
</html>
