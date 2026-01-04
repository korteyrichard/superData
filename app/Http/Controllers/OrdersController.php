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

class OrdersController extends Controller
{
    // Display a listing of the user's orders
    public function index(Request $request)
    {
        $userId = Auth::id();
        
        $orders = Order::with(['products' => function($query) {
            $query->withPivot('quantity', 'price', 'beneficiary_number');
        }])->where('user_id', $userId);

        // Search by order ID
        if ($request->has('order_id') && $request->input('order_id') !== '') {
            $orders->where('id', $request->input('order_id'));
        }

        // Search by beneficiary number
        if ($request->has('beneficiary_number') && $request->input('beneficiary_number') !== '') {
            $orders->where('beneficiary_number', 'like', '%' . $request->input('beneficiary_number') . '%');
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
        Log::info('Checkout process started.');
        $user = Auth::user();

        $cartItems = Cart::where('user_id', $user->id)->with('product')->get();
        Log::info('Cart items fetched.', ['cartItemsCount' => $cartItems->count()]);

        if ($cartItems->isEmpty()) {
            Log::warning('Cart is empty for user.', ['userId' => $user->id]);
            return redirect()->back()->with('error', 'Cart is empty');
        }

        // Calculate total using cart item prices (agent prices if from agent shop)
        $total = $cartItems->sum(function ($item) {
            return (float) ($item->price ?? $item->product->price ?? 0) * $item->quantity;
        });
        Log::info('Total calculated.', ['total' => $total, 'walletBalance' => $user->wallet_balance]);

        // Check if user has enough wallet balance
        if ($user->wallet_balance < $total) {
            Log::warning('Insufficient wallet balance.', ['userId' => $user->id, 'walletBalance' => $user->wallet_balance, 'total' => $total]);
            return redirect()->back()->with('error', 'Insufficient wallet balance. Top up to proceed with the purchase.');
        }

        DB::beginTransaction();
        Log::info('Database transaction started.');
        try {
            // Deduct wallet balance (use bcsub for decimal math and cast to float for decimal:2)
            $user->wallet_balance = (float) bcsub((string) $user->wallet_balance, (string) $total, 2);
            $user->save();
            Log::info('Wallet balance deducted.', ['userId' => $user->id, 'newWalletBalance' => $user->wallet_balance]);

            // Get beneficiary_number from the first cart item (assuming all items have the same number)
            $beneficiaryNumber = $cartItems->first()->beneficiary_number ?? null;
            // Get network from the first product (assuming all items have the same network)
            $network = $cartItems->first()->product->network ?? null;
            Log::info('Beneficiary and network info.', ['beneficiaryNumber' => $beneficiaryNumber, 'network' => $network]);

            // Create the order
            $order = Order::create([
                'user_id' => $user->id,
                'status' => 'processing', // set status to processing after deduction
                'total' => $total,
                'beneficiary_number' => $beneficiaryNumber,
                'network' => $network,
            ]);
            Log::info('Order created.', ['orderId' => $order->id]);

            // Attach products to the order and create agent commissions
            foreach ($cartItems as $item) {
                $price = (float) ($item->price ?? $item->product->price ?? 0);
                $order->products()->attach($item->product_id, [
                    'quantity' => (int) ($item->quantity ?? 1),
                    'price' => $price,
                    'beneficiary_number' => $item->beneficiary_number,
                ]);
                
                // Create agent commission if item was purchased through agent shop
                if ($item->agent_id) {
                    $basePrice = (float) $item->product->price;
                    $commissionAmount = ($price - $basePrice) * $item->quantity;
                    
                    if ($commissionAmount > 0) {
                        \App\Models\Commission::create([
                            'agent_id' => $item->agent_id,
                            'order_id' => $order->id,
                            'amount' => $commissionAmount,
                            'status' => 'pending'
                        ]);
                        Log::info('Agent commission created.', [
                            'agentId' => $item->agent_id, 
                            'orderId' => $order->id, 
                            'commission' => $commissionAmount
                        ]);
                    }
                }
                
                Log::info('Product attached to order.', ['orderId' => $order->id, 'productId' => $item->product_id, 'beneficiaryNumber' => $item->beneficiary_number]);
            }

            // Clear user's cart
            Cart::where('user_id', $user->id)->delete();
            Log::info('Cart cleared.', ['userId' => $user->id]);

            // Create a transaction record for the order
            \App\Models\Transaction::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'amount' => $total,
                'status' => 'completed',
                'type' => 'order',
                'description' => 'Order placed for ' . $order->network . ' data/airtime.',
            ]);
            Log::info('Transaction created for order.', ['orderId' => $order->id, 'userId' => $user->id]);

            DB::commit();
            Log::info('Database transaction committed.');

            // Push order to external API
            try {
                $orderPusher = new OrderPusherService();
                $orderPusher->pushOrderToApi($order);
            } catch (\Exception $e) {
                Log::error('Failed to push order to external API', ['error' => $e->getMessage()]);
            }

            // Redirect to orders page with success message
            return redirect()->route('dashboard.orders')->with('success', 'Order placed successfully!');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Checkout failed during transaction.', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return redirect()->back()->with('error', 'Checkout failed: ' . $e->getMessage());
        }
    }
}
