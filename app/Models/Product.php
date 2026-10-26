<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $connection = 'pos';

    public $timestamps = false;

    protected $fillable = ['product_name', 'barcode', 'category', 'price', 'cost_price', 'supplier_id', 'is_active'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'cost_price' => 'decimal:2', 'stock' => 'integer', 'is_active' => 'boolean'];
    }
}
