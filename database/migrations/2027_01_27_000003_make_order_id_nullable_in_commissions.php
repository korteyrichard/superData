<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            // Drop the existing foreign key constraint
            $table->dropForeign(['order_id']);
            
            // Make order_id nullable
            $table->foreignId('order_id')->nullable()->change()->constrained('orders')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            // Drop the foreign key constraint
            $table->dropForeign(['order_id']);
            
            // Make order_id NOT NULL again
            $table->foreignId('order_id')->nullable(false)->change()->constrained('orders')->onDelete('cascade');
        });
    }
};