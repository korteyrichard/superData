<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class RegisterBundlePortalWebhook extends Command
{
    protected $signature = 'bundleportal:register-webhook {--url= : Public HTTPS callback URL}';

    protected $description = 'Register the BundlePortal order webhook and store its one-time secret in .env.';

    public function handle(): int
    {
        $baseUrl = config('services.bundleportal.base_url');
        $apiKey = config('services.bundleportal.api_key');
        $callbackUrl = $this->option('url') ?: rtrim((string) config('app.url'), '/') . '/bundleportal/orders';

        if (!$apiKey) {
            $this->error('BUNDLEPORTAL_API_KEY is not configured.');
            return self::FAILURE;
        }

        if (!str_starts_with($callbackUrl, 'https://')) {
            $this->error('The webhook callback URL must use HTTPS.');
            return self::FAILURE;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'Accept' => 'application/json',
            ])->timeout(30)->post($baseUrl, [
                'action' => 'set_webhook',
                'webhook_url' => $callbackUrl,
            ]);
        } catch (\Throwable $e) {
            $this->error('Could not reach BundlePortal to register the webhook.');
            return self::FAILURE;
        }

        if (!$response->successful() || $response->json('success') !== true) {
            $this->error('BundlePortal rejected the webhook registration (HTTP ' . $response->status() . ').');
            return self::FAILURE;
        }

        $data = $response->json('data', []);
        $secret = $data['webhook_secret'] ?? null;
        $registeredUrl = $data['webhook_url'] ?? null;

        if (!is_string($secret) || $secret === '' || $registeredUrl !== $callbackUrl) {
            $this->error('BundlePortal returned an incomplete registration response. The secret was not saved.');
            return self::FAILURE;
        }

        $envPath = base_path('.env');
        $envContents = @file_get_contents($envPath);

        if ($envContents === false) {
            $this->error('Webhook registered, but .env could not be read; the one-time secret was not saved.');
            return self::FAILURE;
        }

        if (str_contains($secret, "\n") || str_contains($secret, "\r")) {
            $this->error('Webhook registered, but the secret contains invalid line breaks and was not saved.');
            return self::FAILURE;
        }

        $escapedSecret = '"' . addcslashes($secret, "\\\"") . '"';
        $setting = 'BUNDLEPORTAL_WEBHOOK_SECRET=' . $escapedSecret;

        if (preg_match('/^BUNDLEPORTAL_WEBHOOK_SECRET=.*$/m', $envContents)) {
            $envContents = preg_replace_callback(
                '/^BUNDLEPORTAL_WEBHOOK_SECRET=.*$/m',
                static fn () => $setting,
                $envContents
            );
        } else {
            $envContents = rtrim($envContents) . "\n\n# BundlePortal webhook signing secret\n" . $setting . "\n";
        }

        if (@file_put_contents($envPath, $envContents, LOCK_EX) === false) {
            $this->error('Webhook registered, but .env could not be written; the one-time secret was not saved.');
            return self::FAILURE;
        }

        $this->info('BundlePortal webhook registered at ' . $callbackUrl . '.');
        $this->info('The webhook secret has been saved to .env without displaying it.');
        return self::SUCCESS;
    }
}
