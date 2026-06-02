<?php

namespace App\Services;

use App\Models\Withdrawal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WithdrawalService
{
    public function approveWithdrawal(Withdrawal $withdrawal, string $notes = null)
    {
        return DB::transaction(function () use ($withdrawal, $notes) {
            // Update withdrawal status to processing
            $withdrawal->update([
                'status' => 'processing',
                'notes' => $notes,
                'processed_at' => now()
            ]);

            // Mark corresponding commissions as withdrawn
            $this->markCommissionsAsWithdrawn($withdrawal);

            return $withdrawal;
        });
    }

    public function markAsApproved(Withdrawal $withdrawal, string $notes = null)
    {
        $withdrawal->update([
            'status' => 'approved',
            'notes' => $notes,
            'processed_at' => now()
        ]);
        return $withdrawal;
    }

    public function markAsPaid(Withdrawal $withdrawal, string $notes = null)
    {
        $withdrawal->update([
            'status' => 'paid',
            'notes' => $notes,
            'processed_at' => now()
        ]);
        return $withdrawal;
    }

    public function rejectWithdrawal(Withdrawal $withdrawal, string $notes = null)
    {
        $withdrawal->update([
            'status' => 'rejected',
            'notes' => $notes,
            'processed_at' => now()
        ]);

        return $withdrawal;
    }

    private function markCommissionsAsWithdrawn(Withdrawal $withdrawal)
    {
        $agent = $withdrawal->agent;
        $amount = $withdrawal->amount;

        // Mark regular commissions as withdrawn
        $commissions = $agent->commissions()
            ->where('status', 'available')
            ->orderBy('created_at')
            ->get();

        foreach ($commissions as $commission) {
            if ($amount <= 0) break;
            
            $availableAmount = $commission->amount - $commission->withdrawn_amount;
            
            if ($availableAmount <= 0) continue;
            
            if ($availableAmount <= $amount) {
                // Fully withdraw this commission
                $commission->update([
                    'withdrawn_amount' => $commission->withdrawn_amount + $availableAmount,
                    'status' => 'withdrawn'
                ]);
                $amount -= $availableAmount;
            } else {
                // Partially withdraw this commission
                $commission->update([
                    'withdrawn_amount' => $commission->withdrawn_amount + $amount
                ]);
                $amount = 0;
            }
        }

        // Mark referral commissions as withdrawn if needed
        if ($amount > 0) {
            $referralCommissions = $agent->referralCommissions()
                ->where('status', 'available')
                ->orderBy('created_at')
                ->get();

            foreach ($referralCommissions as $refCommission) {
                if ($amount <= 0) break;
                
                $availableAmount = $refCommission->amount - $refCommission->withdrawn_amount;
                
                if ($availableAmount <= 0) continue;
                
                if ($availableAmount <= $amount) {
                    // Fully withdraw this referral commission
                    $refCommission->update([
                        'withdrawn_amount' => $refCommission->withdrawn_amount + $availableAmount,
                        'status' => 'withdrawn'
                    ]);
                    $amount -= $availableAmount;
                } else {
                    // Partially withdraw this referral commission
                    $refCommission->update([
                        'withdrawn_amount' => $refCommission->withdrawn_amount + $amount
                    ]);
                    $amount = 0;
                }
            }
        }
    }

    private function restoreCommissionsToAvailable(Withdrawal $withdrawal)
    {
        $agent = $withdrawal->agent;
        $amount = $withdrawal->amount;

        // Restore regular commissions to available
        $commissions = $agent->commissions()
            ->where('status', 'withdrawn')
            ->orderByDesc('created_at')
            ->get();

        foreach ($commissions as $commission) {
            if ($amount <= 0) break;
            
            $withdrawnAmount = $commission->withdrawn_amount;
            
            if ($withdrawnAmount <= 0) continue;
            
            if ($withdrawnAmount <= $amount) {
                // Fully restore this commission
                $commission->update([
                    'withdrawn_amount' => 0,
                    'status' => 'available'
                ]);
                $amount -= $withdrawnAmount;
            } else {
                // Partially restore this commission
                $commission->update([
                    'withdrawn_amount' => $withdrawnAmount - $amount
                ]);
                $amount = 0;
            }
        }

        // Restore referral commissions to available if needed
        if ($amount > 0) {
            $referralCommissions = $agent->referralCommissions()
                ->where('status', 'withdrawn')
                ->orderByDesc('created_at')
                ->get();

            foreach ($referralCommissions as $refCommission) {
                if ($amount <= 0) break;
                
                $withdrawnAmount = $refCommission->withdrawn_amount;
                
                if ($withdrawnAmount <= 0) continue;
                
                if ($withdrawnAmount <= $amount) {
                    // Fully restore this referral commission
                    $refCommission->update([
                        'withdrawn_amount' => 0,
                        'status' => 'available'
                    ]);
                    $amount -= $withdrawnAmount;
                } else {
                    // Partially restore this referral commission
                    $refCommission->update([
                        'withdrawn_amount' => $withdrawnAmount - $amount
                    ]);
                    $amount = 0;
                }
            }
        }
    }
}