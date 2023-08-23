<?php

namespace Fieroo\Payment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    public $timestamps = true;

    protected $fillable = [
        'exhibitor_id',
        // 'code_module_id',
        'furnishing_id',
        'qty',
        'is_supplied',
        'price',
        'event_id',
        'payment_id'
    ];
}
