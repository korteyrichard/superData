<?php

namespace App\Console\Commands;

use App\Services\BundlePortalOrderStatusSyncService;
use Illuminate\Console\Command;

class SyncBundlePortalOrderStatus extends Command
{
    protected $signature   = 'orders:sync-bundleportal-status';
    protected $description = 'Sync Telecel/AT/Ishare order statuses with Bundle Portal API';

    public function handle()
    {
        $this->info('Starting Bundle Portal order status sync (Telecel/AT)...');
        (new BundlePortalOrderStatusSyncService())->syncOrderStatuses();
        $this->info('Bundle Portal order status sync (Telecel/AT) completed.');
    }
}
