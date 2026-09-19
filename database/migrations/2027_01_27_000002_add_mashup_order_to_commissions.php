<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            // Add mashup_order_id for mashup commission tracking
            $table->foreignId('mashup_order_id')->nullable()->constrained('mashup_orders')->onDelete('cascade')->after('order_id');
            
            // Add type field to distinguish between regular and mashup commissions
            $table->string('type')->default('order')->after('status'); // 'order' or 'mashup'
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropForeign(['mashup_order_id']);
            $table->dropColumn(['mashup_order_id', 'type']);
        });
    }
};