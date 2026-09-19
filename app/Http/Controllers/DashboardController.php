<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Product;
use App\Models\Cart;
use App\Models\Transaction;
use App\Models\Order;
use App\Models\Alert;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use App\Services\SmsService;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        
        // Filter products based on user role and stock status
        if ($user->isAdmin()) {
            // Admin can see all products
            $products = Product::inStock()->get();
        } else {
            // Use role-based filtering for other users
            $products = Product::forRole($user->role)->inStock()->get();
        }
        
        $cartCount = 0;
        $cartItems = [];
        $walletBalance = 0;
        $orders = [];
        if (auth()->check()) {
            $cartCount = Cart::where('user_id', auth()->id())->count();
            $cartItems = Cart::where('user_id', auth()->id())->with('product')->get();
            $walletBalance = $user->wallet_balance;
            $orders = Order::where('user_id', $user->id)->get();
        }
        $alerts = Alert::active()->latest()->get();
        
        return Inertia::render('Dashboard/dashboard', [
            'products' => $products,
            'cartCount' => $cartCount,
            'cartItems' => $cartItems,
            'walletBalance' => $walletBalance,
            'orders' => $orders,
            'alerts' => $alerts,
        ]);
    }



    public function terms()
    {
        return Inertia::render('Dashboard/Terms');
    }

    public function viewCart()
    {
        $cartItems = Cart::where('user_id', auth()->id())->with('product')->get();
        return Inertia::render('Dashboard/Cart', ['cartItems' => $cartItems]);
    }

    public function removeFromCart($id)
    {
        Cart::where('user_id', auth()->id())->where('id', $id)->delete();
        return response()->json(['success' => true, 'message' => 'Removed from cart']);
    }

    public function transactions()
    {
        $transactions = Transaction::where('user_id', auth()->id())->latest()->get();
        return Inertia::render('Dashboard/transactions', [
            'transactions' => $transactions,
        ]);
    }

    /**
     * Add to the authenticated user's wallet balance via Paystack
     */
    public function addToWallet(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
        ]);

        $user = auth()->user();
        $reference = 'wallet_' . Str::random(16);
        
        // Calculate 1% transaction fee
        $transactionFee = $request->amount * 0.01;
        $totalAmount = $request->amount + $transactionFee;
        
        // Store pending transaction
        $transaction = Transaction::create([
            'user_id' => $user->id,
            'order_id' => null,
            'amount' => $request->amount,
            'status' => 'pending',
            'type' => 'topup',
            'description' => 'Wallet top-up of GHS ' . number_format($request->amount, 2) . ' (+ GHS ' . number_format($transactionFee, 2) . ' fee)',
            'reference' => $reference,
        ]);

        // Calculate 1% transaction fee
        $transactionFee = $request->amount * 0.01;
        $totalAmount = $request->amount + $transactionFee;
        
        // Initialize Paystack payment
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('paystack.secret_key'),
            'Content-Type' => 'application/json',
        ])->post('https://api.paystack.co/transaction/initialize', [
            'email' => $user->email,
            'amount' => $totalAmount * 100, // Convert to kobo
            'callback_url' => route('wallet.callback'),
            'reference' => $reference,
            'metadata' => [
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'type' => 'wallet_topup',
                'actual_amount' => $request->amount,
                'transaction_fee' => $transactionFee
            ]
        ]);

        if ($response->successful()) {
            return Inertia::location($response->json('data.authorization_url'));
        }

        $transaction->update(['status' => 'failed']);
        return redirect()->back()->with('error', 'Payment initialization failed');
    }

    public function verifyNumber(Request $request)
    {
        $request->validate([
            'recipient' => 'required|string',
        ]);

        $baseUrl = config('services.bundleportal.base_url');
        $apiKey  = config('services.bundleportal.api_key');

        try {
            $payload = [
                'action'    => 'verify_number',
                'network'   => 'mtn',
                'recipient' => $request->recipient,
            ];

            \Log::info('VerifyNumber request', [
                'url'     => $baseUrl,
                'payload' => $payload,
            ]);

            $response = Http::withHeaders([
                'x-api-key'    => $apiKey,
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ])->timeout(15)->post($baseUrl, $payload);

            \Log::info('VerifyNumber response', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            $result = $response->successful()
                ? $response->json()
                : ['success' => false, 'message' => 'API error: ' . $response->status() . ' - ' . $response->body()];
        } catch (\Exception $e) {
            \Log::error('VerifyNumber exception', ['message' => $e->getMessage()]);
            $result = ['success' => false, 'message' => $e->getMessage()];
        }

        return back()->with('verify_result', $result);
    }

    public function handleWalletCallback(Request $request)
    {
        $reference = $request->reference;
        
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('paystack.secret_key'),
        ])->get("https://api.paystack.co/transaction/verify/{$reference}");

        if ($response->successful() && $response->json('data.status') === 'success') {
            $paymentData = $response->json('data');
            $metadata = $paymentData['metadata'];
            
            // Use database transaction with row-level locking to prevent race conditions
            $processed = \DB::transaction(function () use ($metadata) {
                // Lock the transaction row to prevent concurrent processing
                $transaction = Transaction::where('id', $metadata['transaction_id'])
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->first();
                
                if (!$transaction) {
                    return false; // Transaction already processed or not found
                }
                
                $user = $transaction->user;
                
                // Get the actual amount from metadata (excluding transaction fee)
                $actualAmount = isset($metadata['actual_amount']) ? $metadata['actual_amount'] : $transaction->amount;
                
                // Update wallet balance with the actual amount (not including fee)
                $user->wallet_balance += $actualAmount;
                $user->save();
                
                // Update transaction status
                $transaction->update(['status' => 'completed']);
                
                // Send SMS notification
                if ($user->phone) {
                    $smsService = new SmsService();
                    $message = "Your wallet has been topped up with GHS " . number_format($actualAmount, 2) . ". New balance: GHS " . number_format($user->wallet_balance, 2);
                    $smsService->sendSms($user->phone, $message);
                }
                
                return true;
            });
            
            if ($processed) {
                return redirect()->route('dashboard')->with('success', 'Wallet topped up successfully!');
            }
        }

        return redirect()->route('dashboard')->with('info', 'Transaction already processed or invalid.');
    }
}
