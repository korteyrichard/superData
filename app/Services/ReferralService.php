<?php

namespace App\Services;

use App\Models\User;
use App\Models\Referral;
use App\Models\ReferralCommission;
use App\Models\Commission;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReferralService
{
    private $commissionValidator;
    
    public function __construct(CommissionValidationService $commissionValidator)
    {
        $this->commissionValidator = $commissionValidator;
    }
    
    public function processReferral(string $referralCode, User $newUser): ?User
    {
        // Validate referral code format
        if (!preg_match('/^[A-Z0-9]{8}$/', $referralCode)) {
            return null;
        }
        
        $referrer = User::where('referral_code', $referralCode)->first();
        
        if (!$referrer || $referrer->id === $newUser->id) {
            return null;
        }

        // Check if referral already exists to avoid duplicate entry
        $existingReferral = Referral::where('referrer_id', $referrer->id)
            ->where('referred_id', $newUser->id)
            ->first();

        if (!$existingReferral) {
            Referral::create([
                'referrer_id' => $referrer->id,
                'referred_id' => $newUser->id,
                'referral_start_date' => now()
            ]);
        }

        return $referrer;
    }

    public function markAsConverted(User $user): void
    {
        Referral::where('referred_id', $user->id)
            ->whereNull('converted_at')
            ->update(['converted_at' => now()]);
    }

    public function createReferralCommission(Commission $commission): void
    {
        $agent = $commission->agent;
        $referral = $agent->referredBy;

        if (!$referral) {
            return;
        }

        $referrer = $referral->referrer;
        $referralCommissionRate = 0.10; // 10% of agent's commission - this remains fixed
        $referralAmount = $commission->amount * $referralCommissionRate;

        // Validate commission data
        $commissionData = [
            'referrer_id' => $referrer->id,
            'commission_id' => $commission->id,
            'amount' => $referralAmount,
            'status' => 'pending',
            'type' => 'order_commission',
            'available_at' => $commission->available_at
        ];
        
        $validation = $this->commissionValidator->validateCommissionData($commissionData);
        if (!$validation['valid']) {
            Log::error('Invalid commission data', $validation['errors']);
            return;
        }
        
        if (!$this->commissionValidator->canCreateCommission($referrer->id, 'order_commission')) {
            Log::warning('Commission creation blocked - daily limit reached', ['referrer_id' => $referrer->id]);
            return;
        }

        ReferralCommission::create($commissionData);
    }
    
    public function createAgentUpgradeCommission(int $referrerId, float $amount): bool
    {
        if (!$this->commissionValidator->validateCommissionAmount($amount, 'agent_upgrade')) {
            Log::error('Invalid commission amount', ['amount' => $amount, 'type' => 'agent_upgrade']);
            return false;
        }
        
        if (!$this->commissionValidator->canCreateCommission($referrerId, 'agent_upgrade')) {
            Log::warning('Commission creation blocked - daily limit reached', ['referrer_id' => $referrerId]);
            return false;
        }
        
        $commissionData = [
            'referrer_id' => $referrerId,
            'commission_id' => null,
            'amount' => $amount,
            'status' => 'available',
            'available_at' => now(),
            'type' => 'agent_upgrade'
        ];
        
        $validation = $this->commissionValidator->validateCommissionData($commissionData);
        if (!$validation['valid']) {
            Log::error('Invalid commission data', $validation['errors']);
            return false;
        }
        
        try {
            ReferralCommission::create($commissionData);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to create referral commission', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function generateReferralLink(User $user): string
    {
        return $user->getReferralLink();
    }

    public function createOrderBasedReferralCommission(Order $order): void
    {
        Log::info('=== ORDER BASED REFERRAL COMMISSION DEBUG ===', [
            'order_id' => $order->id,
            'user_id' => $order->user_id
        ]);
        
        // Check if order has products with total data >= 5GB
        $totalDataSize = $this->calculateTotalDataSize($order);
        
        Log::info('Total data size calculated', ['total_gb' => $totalDataSize]);
        
        if ($totalDataSize < 5) {
            Log::info('Order does not meet 5GB threshold', ['total_gb' => $totalDataSize]);
            return; // Order doesn't meet 5GB threshold
        }
        
        // Find referral relationship for the order user
        $referral = Referral::where('referred_id', $order->user_id)->first();
        
        Log::info('Referral lookup result', [
            'referral_found' => $referral ? 'yes' : 'no',
            'referral_id' => $referral ? $referral->id : null
        ]);
        
        if (!$referral) {
            Log::info('No referral relationship exists for user', ['user_id' => $order->user_id]);
            return; // No referral relationship exists
        }
        
        // Check if this referral is eligible (created after the start date)
        $startDate = env('REFERRAL_ORDER_COMMISSION_START_DATE');
        if ($startDate && $referral->referral_start_date && $referral->referral_start_date->lt($startDate)) {
            Log::info('Referral not eligible - created before feature start date', [
                'referral_start_date' => $referral->referral_start_date,
                'feature_start_date' => $startDate
            ]);
            return; // Referral was created before the feature start date
        }
        
        Log::info('Referral is eligible for order-based commission', [
            'referral_start_date' => $referral->referral_start_date,
            'feature_start_date' => $startDate
        ]);
        
        $referrer = $referral->referrer;
        $commissionAmount = (float) Setting::get('order_based_referral_commission', '0.50'); // Get from order-based setting
        
        Log::info('Order-based referral commission setting retrieved', [
            'setting_value' => $commissionAmount,
            'referrer_id' => $referrer->id,
            'order_id' => $order->id
        ]);
        
        // Validate commission data
        $commissionData = [
            'referrer_id' => $referrer->id,
            'commission_id' => null, // Not tied to a specific commission
            'amount' => $commissionAmount,
            'status' => 'available',
            'type' => 'order_based_referral',
            'available_at' => now()
        ];
        
        Log::info('About to validate commission data', $commissionData);
        
        $validation = $this->commissionValidator->validateCommissionData($commissionData);
        if (!$validation['valid']) {
            Log::error('Invalid order-based referral commission data', $validation['errors']);
            return;
        }
        
        Log::info('Commission data validation passed');
        
        if (!$this->commissionValidator->canCreateCommission($referrer->id, 'order_based_referral')) {
            Log::warning('Order-based referral commission creation blocked by validation service', ['referrer_id' => $referrer->id]);
            return;
        }
        
        Log::info('Commission creation limit check passed - creating commission');
        
        try {
            ReferralCommission::create($commissionData);
            
            Log::info('Order-based referral commission created successfully', [
                'order_id' => $order->id,
                'referrer_id' => $referrer->id,
                'referred_user_id' => $order->user_id,
                'amount' => $commissionAmount,
                'total_data_size' => $totalDataSize
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create order-based referral commission', [
                'order_id' => $order->id,
                'referrer_id' => $referrer->id,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    private function calculateTotalDataSize(Order $order): float
    {
        $totalGB = 0;
        
        foreach ($order->products as $product) {
            $dataSize = $this->extractDataSizeFromQuantity($product->quantity);
            $totalGB += $dataSize;
        }
        
        return $totalGB;
    }
    
    private function extractDataSizeFromQuantity(string $quantity): float
    {
        // Extract numeric value and unit from quantities like "5GB", "10GB", "1.5GB"
        if (preg_match('/(\d+(?:\.\d+)?)\s*(GB|MB)/i', $quantity, $matches)) {
            $value = floatval($matches[1]);
            $unit = strtoupper($matches[2]);
            
            if ($unit === 'MB') {
                return $value / 1024; // Convert MB to GB
            }
            
            return $value; // Already in GB
        }
        
        return 0; // Default if no match
    }

    public function getReferralStats(User $user): array
    {
        $referrals = $user->referrals()->with('referred')->get();
        $referralCommissions = $user->referralCommissions()->with('commission.order')->get();
        
        return [
            'total_referrals' => $referrals->count(),
            'total_earnings' => $referralCommissions->sum('amount'),
            'available_earnings' => $referralCommissions->where('status', 'available')->sum('amount'),
            'pending_earnings' => $referralCommissions->where('status', 'pending')->sum('amount'),
            'referrals' => $referrals,
            'commissions' => $referralCommissions
        ];
    }
}