<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Referral;
use Carbon\Carbon;

class UpdateExistingReferrals extends Command
{
    protected $signature = 'referrals:update-start-dates';
    protected $description = 'Update existing referrals with referral_start_date to exclude them from new order commission feature';

    public function handle()
    {
        $today = Carbon::today();
        
        $updatedCount = Referral::whereNull('referral_start_date')
            ->update(['referral_start_date' => $today]);
        
        $this->info("Updated {$updatedCount} existing referrals with referral_start_date set to {$today->toDateString()}");
        
        return Command::SUCCESS;
    }
}