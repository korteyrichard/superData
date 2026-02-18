<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\CodeCraftOrderStatusSyncService;

class SyncCodeCraftOrderStatus extends Command
{
    protected $signature = 'orders:sync-codecraft-status';
    protected $description = 'Sync order statuses from CodeCraft API';

    public function handle()
    {
        $this->info('Starting CodeCraft order status sync...');
        
        $syncService = new CodeCraftOrderStatusSyncService();
        $syncService->syncOrderStatuses();
        
        $this->info('CodeCraft order status sync completed.');
        
        return 0;
    }
}