<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TestCodeCraftApi extends Command
{
    protected $signature = 'test:codecraft-api';
    protected $description = 'Test CodeCraft API connection and authentication';

    public function handle()
    {
        $apiKey = env('CODECRAFT_API_KEY', '');
        $baseUrl = 'https://api.codecraftnetwork.com/api';

        $this->info('Testing CodeCraft API Connection...');
        $this->info('API Key: ' . (empty($apiKey) ? 'NOT SET' : 'SET (' . strlen($apiKey) . ' chars)'));

        if (empty($apiKey)) {
            $this->error('CodeCraft API key is not configured in .env file');
            return 1;
        }

        // Test payload
        $payload = [
            'recipient_number' => '0248745817',
            'gig' => '1',
            'network' => 'MTN'
        ];

        $endpoints = [
            'Regular' => $baseUrl . '/initiate.php',
            'Special' => $baseUrl . '/special.php'
        ];

        foreach ($endpoints as $name => $endpoint) {
            $this->info("\nTesting {$name} endpoint: {$endpoint}");
            
            try {
                $response = Http::timeout(30)
                    ->withHeaders([
                        'x-api-key' => $apiKey,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json'
                    ])
                    ->post($endpoint, $payload);

                $responseData = $response->json();

                $this->info('Status Code: ' . $response->status());
                $this->info('Response: ' . json_encode($responseData, JSON_PRETTY_PRINT));

                if ($response->status() == 200) {
                    $this->info('✅ API connection successful');
                } elseif ($response->status() == 401) {
                    $this->error('❌ Authentication failed - API key issue');
                } elseif ($response->status() == 402) {
                    $this->error('❌ Agent not found - Contact CodeCraft support');
                } else {
                    $this->warn('⚠️  Unexpected response');
                }

            } catch (\Exception $e) {
                $this->error('Exception: ' . $e->getMessage());
            }
        }

        return 0;
    }
}