<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled', 'refunded'])
                ->default('pending')
                ->change();
        });
    }

    public function down(): void
    {
        DB::table('orders')->whereIn('status', ['failed', 'refunded'])->update(['status' => 'cancelled']);

        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', ['pending', 'processing', 'completed', 'cancelled'])
                ->default('pending')
                ->change();
        });
    }
};
