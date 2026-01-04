<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Commission;
use App\Models\ReferralCommission;
use App\Models\Referral;
use Carbon\Carbon;

class CommissionService
{
    const REFERRAL_COMMISSION_PERCENTAGE = 0.10; // 10% of referred agent's commission
    const REFUND_WINDOW_DAYS = 7;

    public function __construct()
    {
        // Override defaults with config values if available
        if (config('agent.commission.referral_percentage')) {
            $this->referralPercentage = config('agent.commission.referral_percentage');
        }
        if (config('agent.commission.refund_window_days')) {
            $this->refundWindowDays = config('agent.commission.refund_window_days');
        }
    }

    private $referralPercentage = self::REFERRAL_COMMISSION_PERCENTAGE;
    private $refundWindowDays = self::REFUND_WINDOW_DAYS;

    public function calculateAndCreateCommission(Order $order)
    {
        if (!$order->agent_id) {
            return null;
        }

        $commissionAmount = $this->calculateCommissionAmount($order);
        
        if ($commissionAmount <= 0) {
            return null;
        }

        $commission = Commission::create([
            'agent_id' => $order->agent_id,
            'order_id' => $order->id,
            'amount' => $commissionAmount,
            'status' => 'pending'
        ]);

        $this->createReferralCommission($commission);

        return $commission;
    }

    private function calculateCommissionAmount(Order $order)
    {
        $totalCommission = 0;

        foreach ($order->products as $product) {
            $agentProduct = $order->agent->agentShop->agentProducts()
                ->where('product_id', $product->id)
                ->first();

            if ($agentProduct) {
                $commission = ($agentProduct->agent_price - $product->price) * $product->pivot->quantity;
                $totalCommission += max(0, $commission);
            }
        }

        return $totalCommission;
    }

    private function createReferralCommission(Commission $commission)
    {
        $referral = Referral::where('referred_id', $commission->agent_id)->first();
        
        if ($referral) {
            $referralAmount = $commission->amount * $this->referralPercentage;
            
            ReferralCommission::create([
                'referrer_id' => $referral->referrer_id,
                'commission_id' => $commission->id,
                'amount' => $referralAmount,
                'status' => 'pending'
            ]);
        }
    }

    public function makeCommissionAvailable(Order $order)
    {
        if ($order->status !== 'completed') {
            return;
        }

        $availableAt = Carbon::now()->addDays($this->refundWindowDays);

        Commission::where('order_id', $order->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'available',
                'available_at' => $availableAt
            ]);

        ReferralCommission::whereHas('commission', function ($query) use ($order) {
            $query->where('order_id', $order->id);
        })
        ->where('status', 'pending')
        ->update([
            'status' => 'available',
            'available_at' => $availableAt
        ]);
    }

    public function reverseCommission(Order $order)
    {
        Commission::where('order_id', $order->id)
            ->whereIn('status', ['pending', 'available'])
            ->delete();

        ReferralCommission::whereHas('commission', function ($query) use ($order) {
            $query->where('order_id', $order->id);
        })
        ->whereIn('status', ['pending', 'available'])
        ->delete();
    }
}