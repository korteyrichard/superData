<?php

namespace App\Console\Commands;

use App\Services\BundlePortalMtnOrderStatusSyncService;
use Illuminate\Console\Command;

class SyncBundlePortalMtnOrderStatus extends Command
{
    protected $signature   = 'orders:sync-bundleportal-mtn-status';
    protected $description = 'Sync MTN order statuses with Bundle Portal API';

    public function handle()
    {
        $this->info('Starting Bundle Portal MTN order status sync...');
        (new BundlePortalMtnOrderStatusSyncService())->syncOrderStatuses();
        $this->info('Bundle Portal MTN order status sync completed.');
    }
}
