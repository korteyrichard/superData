<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['name', 'price', 'description', 'network', 'expiry','quantity','status', 'product_type'];

    public function carts()
    {
        return $this->hasMany(Cart::class);
    }
    
    public function orders()
    {
        return $this->belongsToMany(Order::class, 'order_product')
            ->withPivot('quantity', 'price', 'beneficiary_number')
            ->withTimestamps();
    }
}
