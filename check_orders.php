<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Order;

echo "Checking orders in database...\n\n";

$totalOrders = Order::count();
echo "Total orders: $totalOrders\n\n";

if ($totalOrders > 0) {
    $orders = Order::select('id', 'reference_id', 'status', 'created_at')
                   ->orderBy('created_at', 'desc')
                   ->limit(10)
                   ->get();
    
    echo "Recent orders:\n";
    echo "ID\tReference ID\t\tStatus\t\tCreated At\n";
    echo "---\t------------\t\t------\t\t----------\n";
    
    foreach ($orders as $order) {
        echo sprintf(
            "%d\t%-15s\t\t%-10s\t%s\n",
            $order->id,
            $order->reference_id ?? 'NULL',
            $order->status,
            $order->created_at
        );
    }
} else {
    echo "No orders found in database.\n";
}