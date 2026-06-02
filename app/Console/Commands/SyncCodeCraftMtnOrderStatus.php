<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\CodeCraftMtnOrderStatusSyncService;

class SyncCodeCraftMtnOrderStatus extends Command
{
    protected $signature = 'orders:sync-codecraft-mtn-status';
    protected $description = 'Sync MTN order statuses from CodeCraft API';

    public function handle()
    {
        $this->info('Starting CodeCraft MTN order status sync...');
        
        $syncService = new CodeCraftMtnOrderStatusSyncService();
        $syncService->syncOrderStatuses();
        
        $this->info('CodeCraft MTN order status sync completed.');
        
        return 0;
    }
}