<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestBundlePortalWebhook extends Command
{
    protected $signature = 'bundleportal:test-webhook {--url=http://127.0.0.1:8000/bundleportal/orders} {--secret=} {--order-id=test-order-001} {--status=completed} {--event=order.completed}';

    protected $description = 'Send a signed Bundle Portal v2 webhook payload to validate the callback signature and route.';

    public function handle(): int
    {
        $url = $this->option('url');
        $secret = $this->option('secret') ?: config('services.bundleportal.webhook_secret', '');

        if (empty($secret)) {
            $this->error('No webhook secret configured. Set BUNDLEPORTAL_WEBHOOK_SECRET in .env.');
            return 1;
        }

        $payload = [
            'event' => $this->option('event'),
            'order_id' => $this->option('order-id'),
            'reference' => 'KT-88213',
            'status' => $this->option('status'),
            'network' => 'mtn',
            'bundle' => '5GB',
            'recipient' => '0244000000',
            'amount' => 24.5,
            'failure_reason' => null,
            'settled_at' => now()->toISOString(),
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $this->info('Sending signed Bundle Portal webhook test request...');
        $this->line('URL: ' . $url);
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-BundlePortal-Signature' => $signature,
                'Accept' => 'application/json',
            ])->withBody($body, 'application/json')->post($url);

            $this->line('HTTP status: ' . $response->status());
            $this->line('Response body: ' . $response->body());

            if ($response->successful()) {
                $this->info('Webhook request succeeded.');
                return 0;
            }

            $this->error('Webhook request failed.');
            return 1;
        } catch (\Throwable $e) {
            $this->error('HTTP request failed: ' . $e->getMessage());
            return 1;
        }
    }
}
