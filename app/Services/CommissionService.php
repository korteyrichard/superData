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

    public function calculateAndCreateCommissionFromShop(Order $order, $shop = null)
    {
        Log::info('=== CALCULATE AND CREATE COMMISSION FROM SHOP START ===', [
            'order_id' => $order->id,
            'agent_id' => $order->agent_id,
            'shop_id' => $shop ? $shop->id : null,
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

        $commissionAmount = $this->calculateCommissionAmountFromShop($order, $shop);
        
        Log::info('Commission amount calculated from shop', [
            'order_id' => $order->id,
            'commission_amount' => $commissionAmount,
            'shop_id' => $shop ? $shop->id : null
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
        
        Log::info('Commission created successfully from shop', [
            'order_id' => $order->id,
            'commission_id' => $commission->id,
            'commission_amount' => $commission->amount,
            'agent_id' => $commission->agent_id,
            'shop_id' => $shop ? $shop->id : null
        ]);

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

        // This method is for orders where the agent has their own shop
        if (!$order->agent || !$order->agent->agentShop) {
            Log::warning('No agent shop found for commission calculation', [
                'order_id' => $order->id,
                'agent_id' => $order->agent_id
            ]);
            return 0;
        }

        $shop = $order->agent->agentShop;
        Log::info('Using agent\'s own shop for commission calculation', [
            'shop_id' => $shop->id,
            'shop_owner_id' => $shop->user_id
        ]);

        foreach ($order->products as $product) {
            Log::info('Processing product for commission', [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'base_price' => $product->price,
                'pivot_price' => $product->pivot->price,
                'pivot_quantity' => $product->pivot->quantity
            ]);
            
            $agentProduct = $shop->agentProducts()
                ->where('product_id', $product->id)
                ->first();

            if ($agentProduct) {
                // Commission = agent_price - base_price (NO quantity multiplication for data bundles)
                $commission = $agentProduct->agent_price - $product->pivot->price;
                $totalCommission += max(0, $commission);
                
                Log::info('Agent product found - commission calculated', [
                    'product_id' => $product->id,
                    'agent_price' => $agentProduct->agent_price,
                    'base_price' => $product->price,
                    'pivot_price' => $product->pivot->price,
                    'quantity' => $product->pivot->quantity,
                    'calculation' => "({$agentProduct->agent_price} - {$product->pivot->price})",
                    'commission_for_product' => $commission,
                    'total_commission_so_far' => $totalCommission,
                    'note' => 'No quantity multiplication for data bundles',
                    'shop_used' => $shop->id
                ]);
            } else {
                Log::warning('No agent product found for commission calculation', [
                    'product_id' => $product->id,
                    'agent_id' => $order->agent_id,
                    'shop_id' => $shop->id,
                    'available_agent_products' => $shop->agentProducts->pluck('product_id')->toArray()
                ]);
            }
        }
        
        Log::info('=== COMMISSION SERVICE DEBUG END ===', [
            'order_id' => $order->id,
            'final_total_commission' => $totalCommission
        ]);

        return $totalCommission;
    }

    private function calculateCommissionAmountFromShop(Order $order, $shop = null)
    {
        $totalCommission = 0;
        
        Log::info('=== COMMISSION SERVICE FROM SHOP DEBUG START ===', [
            'order_id' => $order->id,
            'agent_id' => $order->agent_id,
            'shop_id' => $shop ? $shop->id : null,
            'products_count' => $order->products->count()
        ]);

        // Use the provided shop, or fall back to the original logic
        if (!$shop) {
            Log::info('No shop provided, falling back to original logic');
            return $this->calculateCommissionAmount($order);
        }

        Log::info('Using provided shop for commission calculation', [
            'shop_id' => $shop->id,
            'shop_owner_id' => $shop->user_id,
            'shop_owner_role' => $shop->user ? $shop->user->role : null
        ]);

        foreach ($order->products as $product) {
            Log::info('Processing product for commission from shop', [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'base_price' => $product->price,
                'pivot_price' => $product->pivot->price,
                'pivot_quantity' => $product->pivot->quantity,
                'shop_id' => $shop->id
            ]);
            
            $agentProduct = $shop->agentProducts()
                ->where('product_id', $product->id)
                ->first();

            if ($agentProduct) {
                // Commission = agent_price - base_price (NO quantity multiplication for data bundles)
                $commission = $agentProduct->agent_price - $product->pivot->price;
                $totalCommission += max(0, $commission);
                
                Log::info('Agent product found in shop - commission calculated', [
                    'product_id' => $product->id,
                    'agent_price' => $agentProduct->agent_price,
                    'base_price' => $product->price,
                    'pivot_price' => $product->pivot->price,
                    'quantity' => $product->pivot->quantity,
                    'calculation' => "({$agentProduct->agent_price} - {$product->pivot->price})",
                    'commission_for_product' => $commission,
                    'total_commission_so_far' => $totalCommission,
                    'note' => 'No quantity multiplication for data bundles',
                    'shop_used' => $shop->id
                ]);
            } else {
                Log::warning('No agent product found in shop for commission calculation', [
                    'product_id' => $product->id,
                    'agent_id' => $order->agent_id,
                    'shop_id' => $shop->id,
                    'available_agent_products' => $shop->agentProducts->pluck('product_id')->toArray()
                ]);
            }
        }
        
        Log::info('=== COMMISSION SERVICE FROM SHOP DEBUG END ===', [
            'order_id' => $order->id,
            'final_total_commission' => $totalCommission,
            'shop_id' => $shop->id
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