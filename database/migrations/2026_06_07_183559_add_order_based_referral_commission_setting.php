<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Insert order-based referral commission setting
        DB::table('settings')->insertOrIgnore([
            ['key' => 'order_based_referral_commission', 'value' => '0.50', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove order-based referral commission setting
        DB::table('settings')->where('key', 'order_based_referral_commission')->delete();
    }
};