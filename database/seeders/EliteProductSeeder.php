<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;

class EliteProductSeeder extends Seeder
{
    public function run(): void
    {
        Product::create([
            'name' => 'Elite 50GB Premium Data',
            'network' => 'MTN',
            'description' => 'Exclusive premium data package for elite users',
            'expiry' => '30 days',
            'status' => 'IN STOCK',
            'quantity' => '50GB',
            'price' => 150.00,
            'product_type' => 'elite_product',
        ]);

        Product::create([
            'name' => 'Elite Unlimited Premium',
            'network' => 'TELECEL',
            'description' => 'Unlimited premium package for elite members',
            'expiry' => 'non expiry',
            'status' => 'IN STOCK',
            'quantity' => '100GB',
            'price' => 300.00,
            'product_type' => 'elite_product',
        ]);
    }
}