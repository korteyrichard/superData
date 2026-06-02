<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Setting;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add DataFlow API setting
        Setting::updateOrCreate(
            ['key' => 'dataflow_api_enabled'],
            ['value' => 'false']
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove DataFlow API setting
        Setting::where('key', 'dataflow_api_enabled')->delete();
    }
};