<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Setting;

return new class extends Migration
{
    public function up(): void
    {
        Setting::updateOrCreate(
            ['key' => 'bundleportal_api_enabled'],
            ['value' => 'false']
        );
    }

    public function down(): void
    {
        Setting::where('key', 'bundleportal_api_enabled')->delete();
    }
};
