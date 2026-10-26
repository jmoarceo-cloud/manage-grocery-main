<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    protected $connection = 'pos';

    protected $table = 'sales_details';

    public $timestamps = false;

    protected $guarded = ['id'];
}
