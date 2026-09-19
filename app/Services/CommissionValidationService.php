<?php

namespace App\Services;

use App\Models\ReferralCommission;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

class CommissionValidationService
{
    public function validateCommissionData(array $data): array
    {
        $validator = Validator::make($data, ReferralCommission::rules());
        
        if ($validator->fails()) {
            return [
                'valid' => false,
                'errors' => $validator->errors()->toArray()
            ];
        }
        
        return ['valid' => true];
    }
    
    public function validateCommissionAmount(float $amount, string $type): bool
    {
        $maxAmounts = [
            'agent_upgrade' => 50.00,
            'order_commission' => 1000.00,
            'referral_bonus' => 100.00,
            'order_based_referral' => 10.00 // Max 10 cedis per day for order-based referrals
        ];
        
        return $amount > 0 && $amount <= ($maxAmounts[$type] ?? 100.00);
    }
    
    public function canCreateCommission(int $referrerId, string $type): bool
    {
        $user = User::find($referrerId);
        
        if (!$user || (!$user->isAgent() && $user->role !== 'dealer')) {
            return false;
        }
        
        // Skip daily limits for order-based referrals
        if ($type === 'order_based_referral') {
            return true;
        }
        
        // Check daily commission limits for other types
        $todayCommissions = ReferralCommission::where('referrer_id', $referrerId)
            ->where('type', $type)
            ->whereDate('created_at', today())
            ->sum('amount');
            
        $dailyLimits = [
            'agent_upgrade' => 500.00,
            'order_commission' => 2000.00,
            'referral_bonus' => 300.00,
            'order_based_referral' => 20.00 // Max 20 cedis per day for order-based referrals
        ];
        
        return $todayCommissions < ($dailyLimits[$type] ?? 200.00);
    }
}