<?php

namespace Tests\Feature;

use App\Services\Mtn3BundlePortalOrderPusherService;
use Tests\TestCase;

class Mtn3BundlePortalOrderPusherServiceTest extends TestCase
{
    public function test_it_detects_mtn3_products(): void
    {
        $service = new Mtn3BundlePortalOrderPusherService();
        $method = new \ReflectionMethod($service, 'isMtn3Product');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, 'MTN3 1GB'));
        $this->assertTrue($method->invoke($service, 'mtn3 2GB'));
        $this->assertFalse($method->invoke($service, 'MTN 5GB'));
    }
}
