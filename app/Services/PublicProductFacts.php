<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PublicProductFacts
{
    public const NUTRIENTS = ['calories' => 'Calories (kcal)', 'protein_g' => 'Protein (g)', 'carbs_g' => 'Carbohydrates (g)', 'fat_g' => 'Fat (g)', 'sugar_g' => 'Sugar (g)', 'sodium_mg' => 'Sodium (mg)'];

    private const FIELDS = ['description', 'ingredients', 'allergens', 'brand', 'unit_name', 'net_weight', 'storage_notes', 'nutrition_basis', 'serving_size', 'servings_per_pack', 'calories', 'protein_g', 'carbs_g', 'fat_g', 'sugar_g', 'sodium_mg', 'nutrition_source', 'nutrition_source_url'];

    public static function query()
    {
        return DB::connection('pos')->table('product_details as d')->join('products as p', 'p.id', '=', 'd.product_id')->where('p.is_active', 1)->where('d.review_status', 'verified')->select(['d.product_id', 'p.product_name', 'p.category', ...array_map(fn ($field) => 'd.'.$field, self::FIELDS)]);
    }

    public static function get(array $ids): Collection
    {
        return self::query()->whereIn('p.id', $ids)->get()->map(function ($facts) {
            $facts->nutrition_source_url = self::safeSourceUrl($facts->nutrition_source_url);

            return $facts;
        })->keyBy('product_id');
    }

    public static function safeSourceUrl(?string $url): ?string
    {
        $parts = is_string($url) ? parse_url($url) : false;

        return $parts && filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && ! isset($parts['user']) && ! isset($parts['pass']) ? $url : null;
    }

    public static function basis(?string $basis): string
    {
        return match ($basis) {
            'per_100g' => 'Per 100 g','per_serving' => 'Per serving',default => 'Measurement basis not recorded'
        };
    }

    public static function comparisonNotice(Collection $facts): ?string
    {
        if ($facts->count() < 2) {
            return null;
        }
        $categories = $facts->map(fn ($item) => mb_strtolower(trim($item->category ?? '')));
        if ($categories->contains('') || $categories->unique()->count() > 1) {
            return 'Choose products from the same recorded category to compare.';
        }
        $bases = $facts->pluck('nutrition_basis');
        if ($bases->contains(null) || $bases->contains('')) {
            return 'A measurement basis is missing. Choose products with a recorded basis.';
        }
        if ($bases->unique()->count() > 1) {
            return 'These products use different measurement bases. Choose matching bases to compare.';
        }
        if ($bases->first() === 'per_serving') {
            $servings = $facts->map(fn ($item) => preg_replace('/\s+/u', '', mb_strtolower(trim($item->serving_size ?? ''))));
            if ($servings->contains('') || $servings->unique()->count() > 1) {
                return 'Serving sizes differ or are missing. Choose matching listed serving sizes to compare.';
            }
        }

        return null;
    }
}
