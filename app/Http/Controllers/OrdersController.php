<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Cart;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use App\Services\OrderPusherService;
use App\Services\ReferralService;
use App\Services\CommissionValidationService;
use App\Services\CodeCraftOrderPusherService;
use App\Services\CodeCraftMtnOrderPusherService;
use App\Services\ProdataWorldOrderPusherService;
use App\Services\DataEasyOrderPusherService;
use App\Services\BundlePortalMtnOrderPusherService;
use App\Services\BundlePortalOrderPusherService;
use App\Services\DataFlowOrderPusherService;
use App\Models\Setting;

class OrdersController extends Controller
{
    // Display a listing of the user's orders
    public function index(Request $request)
    {
        $userId = Auth::id();
        
        $orders = Order::with(['products' => function($query) {
            $query->withPivot('quantity', 'price', 'beneficiary_number');
        }])->where('user_id', '=', $userId)
          ->select('id', 'user_id', 'total', 'status', 'created_at', 'network', 'beneficiary_number', 'customer_email', 'paystack_reference');

        // Search by order ID with proper validation
        if ($request->has('order_id') && $request->filled('order_id')) {
            $orderId = $request->input('order_id');
            // Validate that order_id is numeric to prevent SQL injection
            if (is_numeric($orderId)) {
                $orders->where('id', (int)$orderId);
            }
        }

        // Search by beneficiary number with proper validation
        if ($request->has('beneficiary_number') && $request->filled('beneficiary_number')) {
            $beneficiaryNumber = $request->input('beneficiary_number');
            // Validate beneficiary number format to prevent SQL injection
            if (preg_match('/^[0-9]{1,15}$/', $beneficiaryNumber)) {
                $searchTerm = '%' . $beneficiaryNumber . '%';
                $orders->where('beneficiary_number', 'like', $searchTerm);
            }
        }

        $orders = $orders->latest()->get();

        return Inertia::render('Dashboard/orders', [
            'orders' => $orders,
            'searchOrderId' => $request->input('order_id', ''),
            'searchBeneficiaryNumber' => $request->input('beneficiary_number', '')
        ]);
    }

    // Handle checkout and create a new order
    public function checkout(Request $request)
    {
        Log::info('=== CHECKOUT ROUTE HIT ===', [
            'timestamp'      => now(),
            'user_id'        => Auth::id(),
            'user_role'      => Auth::user()->role ?? 'unknown',
            'request_method' => $request->method(),
            'request_url'    => $request->fullUrl(),
        ]);

        $userId = Auth::id();

        // Add rate limiting to prevent rapid concurrent requests
        $cacheKey = "checkout_lock_{$userId}";
        if (\Cache::has($cacheKey)) {
            return redirect()->back()->with('error', 'Please wait before placing another order.');
        }
        \Cache::put($cacheKey, true, 5); // 5-second lock

        DB::beginTransaction();
        Log::info('Database transaction started.');

        try {
            // ✅ FIX 1: Lock the user row for update — prevents concurrent reads
            // of the same wallet balance before either request commits its deduction.
            $user = \App\Models\User::lockForUpdate()->findOrFail($userId);

            // ✅ FIX 2: Fetch AND lock cart items inside the transaction —
            // prevents two concurrent requests from processing the same cart.
            $cartItems = Cart::where('user_id', $user->id)
                ->with('product')
                ->lockForUpdate()
                ->get();

            Log::info('Cart items fetched.', [
                'cartItemsCount' => $cartItems->count(),
                'user_id'        => $user->id,
            ]);

            if ($cartItems->isEmpty()) {
                DB::rollBack();
                \Cache::forget($cacheKey);
                Log::warning('Cart is empty for user.', ['userId' => $user->id]);
                return redirect()->back()->with('error', 'Cart is empty');
            }

            // Validate that all products still exist and are in stock
            foreach ($cartItems as $item) {
                if (!$item->product) {
                    DB::rollBack();
                    \Cache::forget($cacheKey);
                    return redirect()->back()->with('error', 'One or more products are no longer available.');
                }
                
                // Check product stock if applicable
                if (isset($item->product->stock) && $item->product->stock < $item->quantity) {
                    DB::rollBack();
                    \Cache::forget($cacheKey);
                    return redirect()->back()->with('error', "Insufficient stock for {$item->product->name}.");
                }
            }

            foreach ($cartItems as $item) {
                Log::info('Cart item details', [
                    'item_id'            => $item->id,
                    'product_id'         => $item->product_id,
                    'quantity'           => $item->quantity,
                    'price'              => $item->price,
                    'product_price'      => $item->product->price ?? 'null',
                    'beneficiary_number' => $item->beneficiary_number,
                ]);
            }

            // Calculate total with validation
            $total = 0;
            foreach ($cartItems as $item) {
                $price = (float) ($item->price ?? $item->product->price ?? 0);
                if ($price <= 0) {
                    DB::rollBack();
                    \Cache::forget($cacheKey);
                    return redirect()->back()->with('error', 'Invalid product price detected.');
                }
                $total += $price;
            }

            Log::info('Total calculated.', [
                'total'         => $total,
                'walletBalance' => $user->wallet_balance,
            ]);

            // ✅ FIX 3: Balance check is now inside the transaction on the locked row —
            // the value read here is the true current balance, not a stale snapshot.
            if ($user->wallet_balance < $total) {
                DB::rollBack();
                \Cache::forget($cacheKey);
                Log::warning('Insufficient wallet balance.', [
                    'userId'        => $user->id,
                    'walletBalance' => $user->wallet_balance,
                    'total'         => $total,
                ]);
                return redirect()->back()->with('error', 'Insufficient wallet balance. Top up to proceed with the purchase.');
            }

            // Deduct wallet balance
            Log::info('About to deduct wallet balance', [
                'current_balance' => $user->wallet_balance,
                'total_to_deduct' => $total,
            ]);

            $balanceBefore = (float) $user->wallet_balance;
            $newBalance    = bcsub((string) $user->wallet_balance, (string) $total, 2);

            $user->wallet_balance = (float) $newBalance;
            $user->save();

            Log::info('Wallet balance deducted.', [
                'userId'           => $user->id,
                'newWalletBalance' => $user->wallet_balance,
            ]);

            $beneficiaryNumber = $cartItems->first()->beneficiary_number ?? null;
            $network           = $cartItems->first()->product->network ?? null;

            Log::info('Beneficiary and network info.', [
                'beneficiaryNumber' => $beneficiaryNumber,
                'network'           => $network,
            ]);

        // Use cryptographically secure random for order reference
        $orderReference = 'ORD_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(8));
            // Create the order with idempotency check
            $order = Order::create([
                'user_id'            => $user->id,
                'status'             => 'processing',
                'total'              => $total,
                'beneficiary_number' => $beneficiaryNumber,
                'network'            => $network,
                'reference'          => $orderReference,
            ]);

            Log::info('Order created successfully.', [
                'orderId'    => $order->id,
                'reference'  => $orderReference,
                'order_data' => $order->toArray(),
            ]);

            // Attach products to the order and update stock if applicable
            foreach ($cartItems as $item) {
                $price    = (float) ($item->price ?? $item->product->price ?? 0);
                $quantity = $item->quantity;

                $order->products()->attach($item->product_id, [
                    'quantity'           => $quantity,
                    'price'              => $price,
                    'beneficiary_number' => $item->beneficiary_number,
                ]);

                // Update product stock if applicable
                if (isset($item->product->stock)) {
                    $item->product->decrement('stock', $quantity);
                }

                Log::info('Product attached to order.', [
                    'orderId'           => $order->id,
                    'productId'         => $item->product_id,
                    'beneficiaryNumber' => $item->beneficiary_number,
                ]);
            }

            // ✅ FIX 4: Delete cart using the locked IDs we already fetched —
            // avoids re-querying and ensures only this session's items are cleared.
            $cartItemIds = $cartItems->pluck('id');
            Cart::whereIn('id', $cartItemIds)->delete();
            Log::info('Cart cleared.', ['userId' => $user->id, 'deleted_ids' => $cartItemIds]);

            // ✅ FIX 5: Use the captured $balanceBefore instead of back-calculating —
            // the back-calculation with bcsub(-$total) was logically inverted and
            // could reflect the post-deduction value in a concurrent scenario.
            $transaction = \App\Models\Transaction::create([
                'user_id'        => $user->id,
                'order_id'       => $order->id,
                'amount'         => $total,
                'balance_before' => $balanceBefore,
                'balance_after'  => $user->wallet_balance,
                'status'         => 'completed',
                'type'           => 'order',
                'description'    => 'Order placed for data/airtime.',
                'reference'      => $orderReference,
            ]);

            Log::info('Transaction created for order.', [
                'orderId' => $order->id,
                'userId'  => $user->id,
            ]);

            DB::commit();
            \Cache::forget($cacheKey); // Release the lock after successful commit
            Log::info('Database transaction committed.');

            // Post-commit side effects (referral commission, external API push)
            // These run outside the transaction intentionally — they are non-critical
            // and should not roll back a successful payment if they fail.

            try {
                $commissionValidator = new CommissionValidationService();
                $referralService     = new ReferralService($commissionValidator);
                $referralService->createOrderBasedReferralCommission($order);
                Log::info('Order-based referral commission processed', ['order_id' => $order->id]);
            } catch (\Exception $e) {
                Log::error('Failed to process order-based referral commission', [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
            }

            try {
                if ($this->isMtnOrder($order)) {
                    if (Setting::get('bundleportal_mtn_api_enabled', 'false') === 'true') {
                        (new BundlePortalMtnOrderPusherService())->pushOrderToApi($order);
                    } elseif (Setting::get('codecraft_mtn_api_enabled', 'false') === 'true') {
                        (new CodeCraftMtnOrderPusherService())->pushOrderToApi($order);
                    } elseif (Setting::get('dataflow_api_enabled', 'false') === 'true') {
                        (new DataFlowOrderPusherService())->pushOrderToApi($order);
                    } elseif (Setting::get('dataeasy_api_enabled', 'false') === 'true') {
                        (new DataEasyOrderPusherService())->pushOrderToApi($order);
                    } elseif (Setting::get('prodataworld_api_enabled', 'false') === 'true') {
                        (new ProdataWorldOrderPusherService())->pushOrderToApi($order);
                    } else {
                        (new OrderPusherService())->pushOrderToApi($order);
                    }
                } else {
                    if (Setting::get('bundleportal_api_enabled', 'false') === 'true') {
                        (new BundlePortalOrderPusherService())->pushOrderToApi($order);
                    } else {
                        (new CodeCraftOrderPusherService())->pushOrderToApi($order);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Failed to push order to external API', [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                    'trace'    => $e->getTraceAsString(),
                ]);
                $order->update(['api_status' => 'failed']);
            }

            return redirect()->route('dashboard.orders')->with('success', 'Order placed successfully!');

        } catch (\Exception $e) {
            DB::rollBack();
            \Cache::forget($cacheKey); // Release lock on error
            Log::error('Checkout failed during transaction.', [
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
                'user_id' => $userId,
                'line'    => $e->getLine(),
                'file'    => $e->getFile(),
            ]);
            return redirect()->back()->with('error', 'Checkout failed: ' . $e->getMessage());
        }
    }

    private function isMtnOrder($order)
    {
        $network = strtolower($order->network ?? '');
        return stripos($network, 'mtn') !== false;
    }
}
