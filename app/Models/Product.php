<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['name', 'price', 'description', 'network', 'expiry','quantity','status', 'product_type'];

    protected $casts = [
        'price' => 'decimal:2',
    ];

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

    // Scope methods for role-based filtering
    public function scopeForCustomers($query)
    {
        return $query->where('product_type', 'customer_product');
    }

    public function scopeForAgents($query)
    {
        return $query->where('product_type', 'agent_product');
    }

    public function scopeForDealers($query)
    {
        return $query->where('product_type', 'dealer_product');
    }

    public function scopeForElite($query)
    {
        return $query->where('product_type', 'elite_product');
    }

    public function scopeInStock($query)
    {
        return $query->where('status', 'IN STOCK');
    }

    public function scopeForRole($query, $role)
    {
        $productType = match ($role) {
            'dealer' => 'dealer_product',
            'agent' => 'agent_product',
            'customer' => 'customer_product',
            'elite' => 'elite_product',
            default => null,
        };

        if ($productType === null) {
            return $query; // Admin sees all
        }

        return $query->where(function ($roleProducts) use ($productType) {
            $roleProducts->where('product_type', $productType)
                ->orWhere(function ($instantProduct) {
                    $instantProduct->whereRaw("LOWER(REPLACE(TRIM(name), ' ', '')) = ?", ['mtninstant']);
                });
        });
    }
}
