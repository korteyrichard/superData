<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Commission;

return new class extends Migration
{
    public function up(): void
    {
        // First, check if there are any mashup commissions and handle them
        if (Schema::hasTable('commissions')) {
            // Delete all mashup-related commissions to avoid foreign key issues
            Commission::where('type', 'mashup')->delete();
        }

        // Remove mashup-related columns from commissions table
        if (Schema::hasTable('commissions') && Schema::hasColumn('commissions', 'mashup_order_id')) {
            Schema::table('commissions', function (Blueprint $table) {
                $table->dropForeign(['mashup_order_id']);
                $table->dropColumn(['mashup_order_id', 'type']);
            });
        }

        // Drop mashup-related tables
        Schema::dropIfExists('agent_mashup_packages');
        Schema::dropIfExists('mashup_orders');
        Schema::dropIfExists('mashup_packages');
    }

    public function down(): void
    {
        // Recreate mashup_packages table
        Schema::create('mashup_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->string('size');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Recreate mashup_orders table
        Schema::create('mashup_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('mashup_package_id')->constrained()->onDelete('cascade');
            $table->string('recipient_number');
            $table->string('email');
            $table->decimal('amount', 10, 2);
            $table->string('paystack_reference')->unique();
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->json('paystack_data')->nullable();
            $table->timestamps();
        });

        // Recreate agent_mashup_packages table
        Schema::create('agent_mashup_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_shop_id')->constrained()->onDelete('cascade');
            $table->foreignId('mashup_package_id')->constrained()->onDelete('cascade');
            $table->decimal('agent_price', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->unique(['agent_shop_id', 'mashup_package_id']);
        });

        // Re-add mashup columns to commissions table
        if (Schema::hasTable('commissions')) {
            Schema::table('commissions', function (Blueprint $table) {
                $table->foreignId('mashup_order_id')->nullable()->constrained('mashup_orders')->onDelete('cascade')->after('order_id');
                $table->string('type')->default('order')->after('status');
            });
        }
    }
};