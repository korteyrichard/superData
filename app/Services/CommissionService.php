<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Commission;
use App\Models\ReferralCommission;
use App\Models\Referral;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CommissionService
{
    const REFERRAL_COMMISSION_AMOUNT = 20.00; // Fixed 20 cedis per referral commission
    const REFUND_WINDOW_DAYS = 7;

    public function __construct()
    {
        // Override defaults with config values if available
        if (config('agent.commission.referral_amount')) {
            $this->referralAmount = config('agent.commission.referral_amount');
        }
        if (config('agent.commission.refund_window_days')) {
            $this->refundWindowDays = config('agent.commission.refund_window_days');
        }
    }

    private $referralAmount = self::REFERRAL_COMMISSION_AMOUNT;
    private $refundWindowDays = self::REFUND_WINDOW_DAYS;

    public function calculateAndCreateCommission(Order $order)
    {
        Log::info('=== CALCULATE AND CREATE COMMISSION START ===', [
            'order_id' => $order->id,
            'agent_id' => $order->agent_id,
            'has_agent_id' => $order->agent_id ? 'yes' : 'no'
        ]);
        
        if (!$order->agent_id) {
            Log::info('No agent_id found, skipping commission calculation', [
                'order_id' => $order->id
            ]);
            return null;
        }

        // Check if commission already exists for this order
        $existingCommission = Commission::where('order_id', $order->id)->first();
        if ($existingCommission) {
            Log::warning('Commission already exists for this order, skipping', [
                'order_id' => $order->id,
                'existing_commission_id' => $existingCommission->id
            ]);
            return $existingCommission;
        }

        $commissionAmount = $this->calculateCommissionAmount($order);
        
        Log::info('Commission amount calculated', [
            'order_id' => $order->id,
            'commission_amount' => $commissionAmount
        ]);
        
        if ($commissionAmount <= 0) {
            Log::info('Commission amount is zero or negative, skipping commission creation', [
                'order_id' => $order->id,
                'commission_amount' => $commissionAmount
            ]);
            return null;
        }

        $commission = Commission::create([
            'agent_id' => $order->agent_id,
            'order_id' => $order->id,
            'amount' => $commissionAmount,
            'status' => 'available',
            'available_at' => now()
        ]);
        
        Log::info('Commission created successfully', [
            'order_id' => $order->id,
            'commission_id' => $commission->id,
            'commission_amount' => $commission->amount,
            'agent_id' => $commission->agent_id
        ]);

        // Note: Referral commissions are only created when someone becomes a dealer (pays 60 cedis)
        // Not for regular sales commissions

        return $commission;
    }

    private function calculateCommissionAmount(Order $order)
    {
        $totalCommission = 0;
        
        Log::info('=== COMMISSION SERVICE DEBUG START ===', [
            'order_id' => $order->id,
            'agent_id' => $order->agent_id,
            'products_count' => $order->products->count()
        ]);

        foreach ($order->products as $product) {
            Log::info('Processing product for commission', [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'base_price' => $product->price,
                'pivot_price' => $product->pivot->price,
                'pivot_quantity' => $product->pivot->quantity
            ]);
            
            $agentProduct = $order->agent->agentShop->agentProducts()
                ->where('product_id', $product->id)
                ->first();

            if ($agentProduct) {
                // Commission = agent_price - base_price (NO quantity multiplication for data bundles)
                $commission = $agentProduct->agent_price - $product->price;
                $totalCommission += max(0, $commission);
                
                Log::info('Agent product found - commission calculated', [
                    'product_id' => $product->id,
                    'agent_price' => $agentProduct->agent_price,
                    'base_price' => $product->price,
                    'pivot_price' => $product->pivot->price,
                    'quantity' => $product->pivot->quantity,
                    'calculation' => "({$agentProduct->agent_price} - {$product->price})",
                    'commission_for_product' => $commission,
                    'total_commission_so_far' => $totalCommission,
                    'note' => 'No quantity multiplication for data bundles'
                ]);
            } else {
                Log::warning('No agent product found for commission calculation', [
                    'product_id' => $product->id,
                    'agent_id' => $order->agent_id,
                    'agent_shop_id' => $order->agent->agentShop ? $order->agent->agentShop->id : null,
                    'available_agent_products' => $order->agent->agentShop ? $order->agent->agentShop->agentProducts->pluck('product_id')->toArray() : []
                ]);
            }
        }
        
        Log::info('=== COMMISSION SERVICE DEBUG END ===', [
            'order_id' => $order->id,
            'final_total_commission' => $totalCommission
        ]);

        return $totalCommission;
    }

    private function createReferralCommission(Commission $commission)
    {
        $referral = Referral::where('referred_id', $commission->agent_id)->first();
        
        if ($referral) {
            ReferralCommission::create([
                'referrer_id' => $referral->referrer_id,
                'commission_id' => $commission->id,
                'amount' => $this->referralAmount, // Fixed 20 cedis
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