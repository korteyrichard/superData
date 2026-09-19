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
        // Insert default admin settings
        DB::table('settings')->insertOrIgnore([
            ['key' => 'how_to_track_orders_youtube_link', 'value' => '', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'how_to_verify_topup_youtube_link', 'value' => '', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'minimum_withdrawal', 'value' => '10.00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'agent_fee', 'value' => '0.00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'referral_commission', 'value' => '0.50', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove admin settings
        DB::table('settings')->whereIn('key', [
            'how_to_track_orders_youtube_link',
            'how_to_verify_topup_youtube_link',
            'minimum_withdrawal',
            'agent_fee',
            'referral_commission'
        ])->delete();
    }
};
