<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add elite_product to the existing enum
        DB::statement("ALTER TABLE products MODIFY COLUMN product_type ENUM('agent_product', 'customer_product', 'dealer_product', 'elite_product') DEFAULT 'customer_product'");
    }

    public function down(): void
    {
        // Remove elite_product from enum
        DB::statement("ALTER TABLE products MODIFY COLUMN product_type ENUM('agent_product', 'customer_product', 'dealer_product') DEFAULT 'customer_product'");
    }
};