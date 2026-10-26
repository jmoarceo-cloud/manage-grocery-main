<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ProductAvailability
{
    public static function query()
    {
        $batches = DB::connection('pos')->table('product_batches')->select('product_id')->selectRaw('COUNT(*) as batch_count')->selectRaw('SUM(CASE WHEN quantity_remaining > 0 AND (expiry_date IS NULL OR expiry_date >= ?) THEN quantity_remaining ELSE 0 END) as sellable_stock', [now()->toDateString()])->groupBy('product_id');

        return DB::connection('pos')->table('products as p')->leftJoinSub($batches, 'b', 'b.product_id', '=', 'p.id')->where('p.is_active', 1)->select('p.id', 'p.product_name', 'p.barcode', 'p.category', 'p.price', 'p.image', 'p.discount_type', 'p.discount_value')->selectRaw('GREATEST(0, CASE WHEN COALESCE(b.batch_count,0)>0 THEN LEAST(p.stock,COALESCE(b.sellable_stock,0)) WHEN p.expiry_date IS NOT NULL AND p.expiry_date < ? THEN 0 ELSE p.stock END) as sellable_stock', [now()->toDateString()]);
    }
}
