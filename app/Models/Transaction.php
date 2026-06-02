<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Transaction extends Model
{
    protected $fillable = ['order_id', 'user_id', 'amount', 'balance_before', 'balance_after', 'status', 'type', 'description', 'reference'];
    
    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
    
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            $model->created_at = Carbon::now('Africa/Accra');
            $model->updated_at = Carbon::now('Africa/Accra');
        });
        
        static::updating(function ($model) {
            $model->updated_at = Carbon::now('Africa/Accra');
        });
    }

    /**
     * Create a transaction with balance tracking
     */
    public static function createWithBalance($data)
    {
        $user = \App\Models\User::find($data['user_id']);
        $balanceBefore = $user ? $user->wallet_balance : 0;
        
        $transaction = static::create(array_merge($data, [
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceBefore // Will be updated after wallet change
        ]));
        
        return $transaction;
    }
    
    /**
     * Update the balance_after field
     */
    public function updateBalanceAfter($balanceAfter)
    {
        $this->update(['balance_after' => $balanceAfter]);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
