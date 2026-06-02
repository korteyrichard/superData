<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Setting;

class CheckDataFlowConfig extends Command
{
    protected $signature = 'check:dataflow';
    protected $description = 'Check DataFlow API configuration';

    public function handle()
    {
        $this->info('Checking DataFlow API Configuration...');
        
        // Check API Key
        $apiKey = env('DATAFLOW_API_KEY');
        if ($apiKey) {
            $this->info('✅ API Key: SET (' . strlen($apiKey) . ' characters)');
        } else {
            $this->error('❌ API Key: NOT SET');
        }
        
        // Check Base URL
        $baseUrl = env('DATAFLOW_BASE_URL', 'https://dataflowghana.com/api/v1');
        $this->info('🌐 Base URL: ' . $baseUrl);
        
        // Check Database Setting
        try {
            $setting = Setting::where('key', 'dataflow_api_enabled')->first();
            if ($setting) {
                $enabled = $setting->value === 'true';
                $this->info('⚙️  Database Setting: ' . ($enabled ? '✅ ENABLED' : '❌ DISABLED') . ' (value: ' . $setting->value . ')');
            } else {
                $this->error('❌ Database Setting: NOT FOUND');
            }
        } catch (\Exception $e) {
            $this->error('❌ Database Error: ' . $e->getMessage());
        }
        
        return 0;
    }
}