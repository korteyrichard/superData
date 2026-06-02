<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Setting;

class CheckCodeCraftConfig extends Command
{
    protected $signature = 'check:codecraft-config';
    protected $description = 'Check CodeCraft API configuration and settings';

    public function handle()
    {
        $this->info('CodeCraft API Configuration Check');
        $this->info('=====================================');

        // Check environment variables
        $apiKey = env('CODECRAFT_API_KEY', '');
        $this->info('API Key in .env: ' . (empty($apiKey) ? '❌ NOT SET' : '✅ SET (' . strlen($apiKey) . ' chars)'));

        if (!empty($apiKey)) {
            $this->info('API Key: ' . substr($apiKey, 0, 10) . '...' . substr($apiKey, -5));
        }

        // Check database settings
        $mtnApiEnabled = Setting::get('codecraft_mtn_api_enabled', 'false');
        $this->info('MTN API Enabled: ' . ($mtnApiEnabled === 'true' ? '✅ YES' : '❌ NO'));

        $regularApiEnabled = Setting::get('codecraft_api_enabled', 'false');
        $this->info('Regular API Enabled: ' . ($regularApiEnabled === 'true' ? '✅ YES' : '❌ NO'));

        // Check other API settings for comparison
        $dataEasyEnabled = Setting::get('dataeasy_api_enabled', 'false');
        $this->info('DataEasy API Enabled: ' . ($dataEasyEnabled === 'true' ? '✅ YES' : '❌ NO'));

        $prodataWorldEnabled = Setting::get('prodataworld_api_enabled', 'false');
        $this->info('ProdataWorld API Enabled: ' . ($prodataWorldEnabled === 'true' ? '✅ YES' : '❌ NO'));

        $this->info('');
        $this->info('Recommendations:');
        
        if (empty($apiKey)) {
            $this->warn('- Set CODECRAFT_API_KEY in your .env file');
        }
        
        if ($mtnApiEnabled !== 'true') {
            $this->warn('- Enable CodeCraft MTN API in admin settings');
            $this->info('  Run: php artisan tinker');
            $this->info('  Then: App\\Models\\Setting::set("codecraft_mtn_api_enabled", "true")');
        }

        $this->info('');
        $this->info('To test the API connection, run:');
        $this->info('php artisan test:codecraft-api');

        return 0;
    }
}