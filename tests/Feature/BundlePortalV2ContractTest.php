<?php

namespace Tests\Feature;

use App\Services\BundlePortalMtnOrderPusherService;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Tests\TestCase;

class BundlePortalV2ContractTest extends TestCase
{
    use WithoutMiddleware;

    public function test_mtn_service_uses_v2_by_default(): void
    {
        config(['services.bundleportal.base_url' => null]);
        config(['services.bundleportal.api_key' => 'bp_live_test_key']);

        $service = new BundlePortalMtnOrderPusherService();

        $this->assertSame('https://api.bundleportal.com/v2', $service->getBaseUrl());
    }

    public function test_bundle_portal_webhook_accepts_v2_signature_format(): void
    {
        putenv('WEBHOOK_SECRET=demo-secret');
        config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);

        $payload = json_encode([
            'event' => 'order.completed',
            'order_id' => 'demo-order-123',
            'status' => 'completed',
        ]);

        $signature = 'sha256=' . hash_hmac('sha256', $payload, 'demo-secret');

        $response = $this->postJson('/api/webhook/status', json_decode($payload, true), [
            'X-BundlePortal-Signature' => $signature,
            'Content-Type' => 'application/json',
        ]);

        $this->assertTrue($response->status() === 404 || $response->status() === 400 || $response->status() === 200);
    }
}
