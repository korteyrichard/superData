<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    public function index()
    {
        return Inertia::render('Dashboard/Wallet', [
            'transactions' => Transaction::where('user_id', auth()->id())
                ->whereIn('type', ['topup', 'credit', 'debit'])
                ->select('id', 'amount', 'status', 'type', 'description', 'reference', 'created_at')
                ->latest()
                ->paginate(10),
        ]);
    }

    public function verifyPayment(Request $request)
    {
        $request->validate([
            'reference' => 'required|string'
        ]);

        $reference = $request->reference;
        $userId = auth()->id();

        $transaction = Transaction::where('reference', $reference)
            ->where('user_id', $userId)
            ->where('type', 'topup')
            ->first();

        if (!$transaction) {
            return redirect()->back()->with('error', 'Transaction not found');
        }

        if ($transaction->status === 'completed') {
            return redirect()->back()->with('error', 'Transaction already verified');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . config('paystack.secret_key'),
                'Content-Type' => 'application/json',
            ])->get("https://api.paystack.co/transaction/verify/{$reference}");

            $paystackData = $response->json();

            if ($response->successful() && $paystackData['status'] && $paystackData['data']['status'] === 'success') {
                DB::transaction(function () use ($transaction, $userId) {
                    $transaction->update(['status' => 'completed']);
                    $user = User::find($userId);
                    $user->increment('wallet_balance', $transaction->amount);
                });

                return redirect()->back()->with('success', 'Payment verified and balance updated');
            } else {
                return redirect()->back()->with('error', 'Payment verification failed');
            }
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error verifying payment: ' . $e->getMessage());
        }
    }
}

