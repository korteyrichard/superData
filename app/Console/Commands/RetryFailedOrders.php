<?php

namespace App\Console\Commands;

use App\Services\FailedOrderRetryService;
use Illuminate\Console\Command;

class RetryFailedOrders extends Command
{
    protected $signature = 'orders:retry-failed';
    protected $description = 'Retry failed MTN orders from today if no successful order exists for the same beneficiary';

    public function handle(FailedOrderRetryService $retryService)
    {
        $this->info('Starting failed MTN order retry process...');
        
        $retryService->retryFailedOrders();
        
        $this->info('Failed MTN order retry process completed.');
    }
}