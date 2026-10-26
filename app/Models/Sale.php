<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $connection = 'pos';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['transaction_date' => 'datetime', 'total_amount' => 'decimal:2', 'discount_amount' => 'decimal:2'];
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class, 'sale_id');
    }
}
