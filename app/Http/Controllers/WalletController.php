<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    public function index()
    {
        // Use array of allowed transaction types with strict validation
        $allowedTypes = ['topup', 'credit', 'debit'];
        $currentUserId = auth()->id();
        
        // Validate user ID to prevent issues
        if (!is_numeric($currentUserId) || $currentUserId <= 0) {
            abort(403, 'Invalid user');
        }
        
        return Inertia::render('Dashboard/Wallet', [
            'transactions' => Transaction::where('user_id', $currentUserId)
                ->whereIn('type', $allowedTypes)
                ->select('id', 'amount', 'status', 'type', 'description', 'reference', 'created_at')
                ->latest()
                ->paginate(10),
            'settings' => [
                'how_to_verify_topup_youtube_link' => Setting::get('how_to_verify_topup_youtube_link')
            ]
        ]);
    }

   public function verifyPayment(Request $request)
    {
        // Check Order Recovery settings first
        $launchDate = config('app.order_recovery_launch_date', '2026-07-30');
        $maxAgeDays = (int) config('app.order_recovery_max_age_days', 30);
        
        if (now() < $launchDate) {
            return redirect()->back()->with('error', 'Order recovery feature not yet available.');
        }
        
        $validatedData = $request->validate([
            'reference' => 'required|string|max:255|regex:/^[a-zA-Z0-9_-]+$/'
        ]);

        $reference = $validatedData['reference'];
        $userId = auth()->id();

        // Add rate limiting to prevent rapid verification attempts
        $cacheKey = "verify_payment_{$userId}_{$reference}";
        if (\Cache::has($cacheKey)) {
            \Log::warning('Rate limit hit for payment verification', [
                'reference' => $reference,
                'user_id' => $userId
            ]);
            return redirect()->back()->with('error', 'Please wait before trying again.');
        }
        \Cache::put($cacheKey, true, 30); // 30-second lock

        try {
            // Use database transaction with proper locking
            $result = DB::transaction(function () use ($reference, $userId, $maxAgeDays) {
                // Lock the transaction row to prevent concurrent processing
                $transaction = Transaction::where('reference', $reference)
                    ->where('user_id', $userId)
                    ->where('type', 'topup')
                    ->lockForUpdate()
                    ->first();

                \Log::info('Transaction lookup result', [
                    'reference' => $reference,
                    'user_id' => $userId,
                    'transaction_found' => $transaction ? 'yes' : 'no',
                    'transaction_status' => $transaction ? $transaction->status : null
                ]);

                if (!$transaction) {
                    return ['success' => false, 'message' => 'Transaction not found'];
                }

                // Check if transaction is within recovery age limit
                $transactionAge = $transaction->created_at->diffInDays(now());
                if ($transactionAge > $maxAgeDays) {
                    return ['success' => false, 'message' => "Transaction is too old (${transactionAge} days). Recovery limit is ${maxAgeDays} days."];
                }

                if ($transaction->status === 'completed') {
                    return ['success' => false, 'message' => 'Transaction already verified'];
                }

                \Log::info('Calling Paystack API', [
                    'reference' => $reference,
                    'api_url' => "https://api.paystack.co/transaction/verify/{$reference}",
                    'secret_key_exists' => config('paystack.secret_key') ? 'yes' : 'no'
                ]);

                // Verify with Paystack inside the transaction
                $response = Http::timeout(30)->withHeaders([
                    'Authorization' => 'Bearer ' . config('paystack.secret_key'),
                    'Content-Type' => 'application/json',
                ])->get("https://api.paystack.co/transaction/verify/{$reference}");

                \Log::info('Paystack API response', [
                    'reference' => $reference,
                    'status_code' => $response->status(),
                    'successful' => $response->successful(),
                    'response_body' => $response->json()
                ]);

                if (!$response->successful()) {
                    return ['success' => false, 'message' => 'Payment verification request failed. Status: ' . $response->status()];
                }

                $paystackData = $response->json();

                // Check multiple success indicators with comprehensive patterns
                $rootStatusSuccess = $paystackData['status'] === true;
                $dataStatusSuccess = $paystackData['data']['status'] === 'success';
                $isPaystackSuccess = $rootStatusSuccess && $dataStatusSuccess;
                
                // Check gateway response for success patterns
                $gatewayResponse = strtolower($paystackData['data']['gateway_response'] ?? '');
                $isGatewaySuccess = stripos($gatewayResponse, 'successful') !== false ||
                    stripos($gatewayResponse, 'approved') !== false ||
                    stripos($gatewayResponse, 'completed') !== false ||
                    stripos($gatewayResponse, 'success') !== false;
                
                // Check message for success patterns
                $message = strtolower($paystackData['data']['message'] ?? '');
                $isMessageSuccess = stripos($message, 'successful') !== false ||
                    stripos($message, 'approved') !== false ||
                    stripos($message, 'completed') !== false ||
                    stripos($message, 'success') !== false;
                
                $hasPaidAt = !empty($paystackData['data']['paid_at']);
                $hasValidAmount = isset($paystackData['data']['amount']) && $paystackData['data']['amount'] > 0;
                
                // Check if status itself indicates success
                $statusIndicatesSuccess = in_array(strtolower($paystackData['data']['status'] ?? ''), [
                    'success', 'successful', 'approved', 'completed'
                ]);
                
                // Special case: Root status true + success message should work
                $rootSuccessWithMessage = $rootStatusSuccess && $isMessageSuccess;



                // More balanced verification: Require both root status success AND data status success
                // But also check for paid_at as additional confirmation
                $allowVerification = $isPaystackSuccess && ($hasPaidAt || $hasValidAmount);

                if (!$allowVerification) {
                    \Log::warning('Paystack verification failed', [
                        'reference' => $reference,
                        'paystack_status' => $paystackData['status'] ?? 'unknown',
                        'paystack_data_status' => $paystackData['data']['status'] ?? 'unknown'
                    ]);
                    
                    // Handle different payment statuses
                    $dataStatus = $paystackData['data']['status'] ?? 'unknown';
                    if ($dataStatus === 'ongoing') {
                        return ['success' => false, 'message' => 'Payment is still in progress. Please try again in a few minutes.'];
                    } elseif ($dataStatus === 'failed') {
                        return ['success' => false, 'message' => 'Payment failed. Please try a new payment.'];
                    } elseif ($dataStatus === 'abandoned') {
                        return ['success' => false, 'message' => 'Payment was abandoned. Please try a new payment.'];
                    } else {
                        return ['success' => false, 'message' => 'Payment verification failed. Status: ' . $dataStatus];
                    }
                }

                // Validate payment amount matches (account for 1% fee)
                $paystackAmount = $paystackData['data']['amount'] / 100; // Convert from kobo
                $expectedAmount = (float) $transaction->amount;
                $expectedAmountWithFee = $expectedAmount * 1.01; // Add 1% fee
                
                $amountDifference = abs($paystackAmount - $expectedAmount);
                $amountDifferenceWithFee = abs($paystackAmount - $expectedAmountWithFee);
                

                
                // Allow either exact amount match or amount + 1% fee match
                if ($amountDifference > 0.02 && $amountDifferenceWithFee > 0.02) {
                    return ['success' => false, 'message' => "Payment amount mismatch. Expected: {$expectedAmount} or {$expectedAmountWithFee} (with fee), Got: {$paystackAmount}"];
                }

                // Lock and update user balance atomically
                $user = User::lockForUpdate()->findOrFail($userId);
                $balanceBefore = $user->wallet_balance;
                
                // Update transaction status first
                $transaction->update([
                    'status' => 'completed',
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceBefore + $transaction->amount
                ]);

                // Update user balance
                $user->increment('wallet_balance', $transaction->amount);

                \Log::info('Payment verification successful', [
                    'reference' => $reference,
                    'user_id' => $userId,
                    'amount' => $transaction->amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceBefore + $transaction->amount
                ]);

                return [
                    'success' => true, 
                    'message' => 'Payment verified and balance updated',
                    'amount' => $transaction->amount,
                    'new_balance' => $user->fresh()->wallet_balance
                ];
            });

            \Cache::forget($cacheKey); // Release the lock

            if ($result['success']) {
                return redirect()->back()->with('success', $result['message']);
            } else {
                return redirect()->back()->with('error', $result['message']);
            }

        } catch (\Exception $e) {
            \Cache::forget($cacheKey); // Release lock on error
            \Log::error('Payment verification error', [
                'reference' => $reference,
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->back()->with('error', 'Error verifying payment: ' . $e->getMessage());
        }
    }
}

