<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Product;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\Setting;
use App\Models\Alert;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Services\CommissionService;
use App\Services\OrderPusherService;
use App\Services\CodeCraftOrderPusherService;
use App\Services\CodeCraftMtnOrderPusherService;
use App\Services\ProdataWorldOrderPusherService;
use App\Services\DataEasyOrderPusherService;
use App\Services\DataFlowOrderPusherService;
use App\Services\BundlePortalMtnOrderPusherService;
use App\Services\BundlePortalOrderPusherService;

class AdminDashboardController extends Controller
{
    /**
     * Display the admin dashboard.
     */
    public function index()
    {
        $userCount = User::count();
        $productCount = Product::count();
        $orderCount = Order::count();
        $transactionCount = Transaction::count();

        $today = now()->today();
        $todayUserCount = User::whereDate('created_at', $today)->count();
        $todayOrderCount = Order::whereDate('created_at', $today)->count();
        $todayTransactionCount = Transaction::whereDate('created_at', $today)->count();
        
        $todayRevenue = Order::whereDate('created_at', $today)->sum('total') ?: 0;
        $totalRevenue = Order::sum('total') ?: 0;

        return Inertia::render('Admin/Dashboard', [
            'userCount' => $userCount,
            'productCount' => $productCount,
            'orderCount' => $orderCount,
            'transactionCount' => $transactionCount,
            'todayUserCount' => $todayUserCount,
            'todayOrderCount' => $todayOrderCount,
            'todayTransactionCount' => $todayTransactionCount,
            'todayRevenue' => $todayRevenue,
            'totalRevenue' => $totalRevenue,
            'apiEnabled' => Setting::get('api_enabled', 'true') === 'true',
            'codeCraftApiEnabled' => Setting::get('codecraft_api_enabled', 'true') === 'true',
            'codeCraftMtnApiEnabled' => Setting::get('codecraft_mtn_api_enabled', 'false') === 'true',
            'prodataWorldApiEnabled' => Setting::get('prodataworld_api_enabled', 'false') === 'true',
            'dataEasyApiEnabled' => Setting::get('dataeasy_api_enabled', 'false') === 'true',
            'dataFlowApiEnabled' => Setting::get('dataflow_api_enabled', 'false') === 'true',
            'bundlePortalMtnApiEnabled' => Setting::get('bundleportal_mtn_api_enabled', 'false') === 'true',
            'bundlePortalApiEnabled' => Setting::get('bundleportal_api_enabled', 'false') === 'true',
        ]);
    }

    /**
     * Display the admin users page.
     */
    public function users(Request $request)
    {
        $users = User::query();

        // Search by email
        if ($request->has('email') && $request->input('email') !== '') {
            $users->where('email', 'like', '%' . $request->input('email') . '%');
        }

        // Filter by role
        if ($request->has('role') && $request->input('role') !== '') {
            $users->where('role', $request->input('role'));
        }

        // Get user statistics
        $totalUsers = User::count();
        $customerCount = User::where('role', 'customer')->count();
        $agentCount = User::where('role', 'agent')->count();
        $dealerCount = User::where('role', 'dealer')->count();
        $eliteCount = User::where('role', 'elite')->count();
        $adminCount = User::where('role', 'admin')->count();
        $totalWalletBalance = User::sum('wallet_balance');

        return Inertia::render('Admin/Users', [
            'users' => $users->select('id', 'name', 'email', 'role', 'wallet_balance', 'created_at', 'updated_at')->paginate(15),
            'filterEmail' => $request->input('email', ''),
            'filterRole' => $request->input('role', ''),
            'userStats' => [
                'total' => $totalUsers,
                'customers' => $customerCount,
                'agents' => $agentCount,
                'dealers' => $dealerCount,
                'elite' => $eliteCount,
                'admins' => $adminCount,
                'totalWalletBalance' => $totalWalletBalance,
            ],
        ]);
    }

    /**
     * Display the admin products page.
     */
    public function products(Request $request)
    {
        $products = Product::query();

        if ($request->has('network') && $request->input('network') !== '') {
            $products->where('network', 'like', '%' . $request->input('network') . '%');
        }

        return Inertia::render('Admin/Products', [
            'products' => $products->get(),
            'filterNetwork' => $request->input('network', ''),
        ]);
    }

    /**
     * Display the admin orders page.
     */
    public function orders(Request $request)
    {
        $orders = Order::with(['products' => function($query) {
            $query->withPivot('quantity', 'price', 'beneficiary_number');
        }, 'user', 'commission'])->select('orders.*')->latest();

        if ($request->filled('network')) {
            $orders->where('network', 'like', '%' . $request->input('network') . '%');
        }

        if ($request->filled('status')) {
            $orders->where('status', $request->input('status'));
        }

        if ($request->filled('api_status')) {
            $orders->where('api_status', $request->input('api_status'));
        }

        if ($request->filled('order_id')) {
            $orders->where('id', $request->input('order_id'));
        }

        if ($request->filled('beneficiary_number')) {
            $orders->where('beneficiary_number', 'like', '%' . $request->input('beneficiary_number') . '%');
        }

        if ($request->filled('email')) {
            $orders->where(function($q) use ($request) {
                $q->where('customer_email', 'like', '%' . $request->input('email') . '%')
                  ->orWhereHas('user', fn($u) => $u->where('email', 'like', '%' . $request->input('email') . '%'));
            });
        }

        if ($request->filled('date')) {
            $orders->whereDate('created_at', $request->input('date'));
        }

        if ($request->has('recovered') && $request->input('recovered') === 'true') {
            $orders->whereNotNull('paystack_reference');
        }

        $today = now()->today();
        $dailySales = Order::whereDate('created_at', $today)->sum('total');
        $dailyCommissions = \App\Models\Commission::whereDate('created_at', $today)->sum('amount');
        $recoveredOrdersCount = Order::whereNotNull('paystack_reference')->count();
        $allNetworks = Order::whereNotNull('network')->distinct()->pluck('network');

        return Inertia::render('Admin/Orders', [
            'orders' => $orders->paginate(50)->withQueryString(),
            'filterNetwork' => $request->input('network', ''),
            'filterStatus' => $request->input('status', ''),
            'filterApiStatus' => $request->input('api_status', ''),
            'filterEmail' => $request->input('email', ''),
            'filterDate' => $request->input('date', ''),
            'searchOrderId' => $request->input('order_id', ''),
            'searchBeneficiaryNumber' => $request->input('beneficiary_number', ''),
            'filterRecovered' => $request->input('recovered', ''),
            'dailySales' => $dailySales,
            'dailyCommissions' => $dailyCommissions,
            'recoveredOrdersCount' => $recoveredOrdersCount,
            'allNetworks' => $allNetworks,
        ]);
    }

    /**
     * Display commissions management page.
     */
    public function commissions(Request $request)
    {
        $commissions = \App\Models\Commission::with(['agent', 'order'])->latest();

        if ($request->has('status') && $request->input('status') !== '') {
            $commissions->where('status', $request->input('status'));
        }

        if ($request->has('agent_id') && $request->input('agent_id') !== '') {
            $commissions->where('agent_id', $request->input('agent_id'));
        }

        // Calculate ORDER commission totals
        $totalOrderCommissions = \App\Models\Commission::sum('amount');
        $availableOrderCommissions = \App\Models\Commission::where('status', 'available')->sum('amount');
        $pendingOrderCommissions = \App\Models\Commission::where('status', 'pending')->sum('amount');
        $paidOrderCommissions = \App\Models\Commission::where('status', 'paid')->sum('amount');
        $withdrawnOrderCommissions = \App\Models\Commission::where('status', 'withdrawn')->sum('amount');
        
        // Get ORDER commission counts
        $totalCommissionCount = \App\Models\Commission::count();
        $availableCommissionCount = \App\Models\Commission::where('status', 'available')->count();
        $pendingCommissionCount = \App\Models\Commission::where('status', 'pending')->count();
        $paidCommissionCount = \App\Models\Commission::where('status', 'paid')->count();
        $withdrawnCommissionCount = \App\Models\Commission::where('status', 'withdrawn')->count();

        // Fetch referral commissions with pagination
        $referralCommissions = \App\Models\ReferralCommission::with(['referrer'])->latest()->paginate(50);

        // Calculate REFERRAL commission totals
        $totalReferralCommissions = \App\Models\ReferralCommission::sum('amount');
        $availableReferralCommissions = \App\Models\ReferralCommission::where('status', 'available')->sum('amount');
        $pendingReferralCommissions = \App\Models\ReferralCommission::where('status', 'pending')->sum('amount');
        $withdrawnReferralCommissions = \App\Models\ReferralCommission::where('status', 'withdrawn')->sum('amount');

        // Get REFERRAL commission counts
        $totalReferralCount = \App\Models\ReferralCommission::count();
        $availableReferralCount = \App\Models\ReferralCommission::where('status', 'available')->count();
        $pendingReferralCount = \App\Models\ReferralCommission::where('status', 'pending')->count();
        $withdrawnReferralCount = \App\Models\ReferralCommission::where('status', 'withdrawn')->count();

        return Inertia::render('Admin/Commissions', [
            'commissions' => $commissions->paginate(50),
            'referralCommissions' => $referralCommissions,
            'filterStatus' => $request->input('status', ''),
            'filterAgentId' => $request->input('agent_id', ''),
            'agents' => User::whereIn('role', ['agent', 'dealer'])->get(['id', 'name', 'email']),
            
            // Order Commission Data
            'totalOrderCommissions' => $totalOrderCommissions,
            'availableOrderCommissions' => $availableOrderCommissions,
            'pendingOrderCommissions' => $pendingOrderCommissions,
            'paidOrderCommissions' => $paidOrderCommissions,
            'withdrawnOrderCommissions' => $withdrawnOrderCommissions,
            'totalCommissionCount' => $totalCommissionCount,
            'availableCommissionCount' => $availableCommissionCount,
            'pendingCommissionCount' => $pendingCommissionCount,
            'paidCommissionCount' => $paidCommissionCount,
            'withdrawnCommissionCount' => $withdrawnCommissionCount,
            
            // Referral Commission Data
            'totalReferralCommissions' => $totalReferralCommissions,
            'availableReferralCommissions' => $availableReferralCommissions,
            'pendingReferralCommissions' => $pendingReferralCommissions,
            'withdrawnReferralCommissions' => $withdrawnReferralCommissions,
            'totalReferralCount' => $totalReferralCount,
            'availableReferralCount' => $availableReferralCount,
            'pendingReferralCount' => $pendingReferralCount,
            'withdrawnReferralCount' => $withdrawnReferralCount,
            
            // Combined totals for overview
            'totalAllCommissions' => $totalOrderCommissions + $totalReferralCommissions,
            'totalAvailableCommissions' => $availableOrderCommissions + $availableReferralCommissions
        ]);
    }

    /**
     * Update commission status to available.
     */
    public function makeCommissionAvailable(\App\Models\Commission $commission)
    {
        if ($commission->status !== 'pending') {
            return redirect()->back()->with('error', 'Only pending commissions can be made available');
        }

        $commission->update([
            'status' => 'available',
            'available_at' => now()
        ]);

        // Also update any related referral commissions
        \App\Models\ReferralCommission::where('commission_id', $commission->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'available',
                'available_at' => now()
            ]);

        return redirect()->back()->with('success', 'Commission made available for withdrawal');
    }

    /**
     * Retry pushing a single failed/disabled order to the external API.
     */
    public function retryOrder(Order $order)
    {
        $this->pushOrderToEnabledPusher($order);
        return redirect()->back()->with('success', "Order #{$order->id} retry initiated.");
    }

    /**
     * Bulk retry pushing failed/disabled orders to the external API.
     */
    public function bulkRetryOrders(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'exists:orders,id',
        ]);

        $orders = Order::whereIn('id', $request->order_ids)
            ->where('status', 'processing')
            ->whereIn('api_status', ['failed', 'disabled'])
            ->get();

        foreach ($orders as $order) {
            $this->pushOrderToEnabledPusher($order);
        }

        return redirect()->back()->with('success', "Retried {$orders->count()} order(s).");
    }

    private function pushOrderToEnabledPusher(Order $order)
    {
        try {
            $isMtn = stripos($order->network ?? '', 'mtn') !== false;
            if ($isMtn) {
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
            \Log::error('Retry order push failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            $order->update(['api_status' => 'failed']);
        }
    }

    /**
     * Delete an order.
     */
    public function deleteOrder(Order $order)
    {
        $order->delete();
        return redirect()->back()->with('success', 'Order deleted successfully.');
    }

    /**
     * Update an order's status.
     */
    public function updateOrderStatus(Request $request, Order $order)
    {
        $request->validate([
            'status' => 'required|string|in:pending,processing,completed,cancelled',
        ]);

        $oldStatus = $order->status;
        $order->update(['status' => $request->status]);

        // Handle automatic refund when order is cancelled
        if ($request->status === 'cancelled' && $oldStatus !== 'cancelled') {
            $user = $order->user;
            $refundAmount = $order->total;
            $balanceBefore = $user->wallet_balance;
            
            // Add refund to user's wallet
            $user->increment('wallet_balance', $refundAmount);
            
            // Create refund transaction record
            Transaction::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'amount' => $refundAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $user->fresh()->wallet_balance,
                'status' => 'completed',
                'type' => 'refund',
                'description' => "Refund for cancelled order #{$order->id}",
            ]);

            // Remove commission from dealer/agent if one exists
            (new CommissionService())->reverseCommission($order);
        }

        return redirect()->back()->with('success', 'Order status updated successfully.');
    }

    /**
     * Bulk update order statuses.
     */
    public function bulkUpdateOrderStatus(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'exists:orders,id',
            'status' => 'required|string|in:pending,processing,completed,cancelled',
        ]);

        $orders = Order::with('user')->whereIn('id', $request->order_ids)->get();
        
        $updatedCount = Order::whereIn('id', $request->order_ids)
            ->update(['status' => $request->status]);

        if ($request->status === 'cancelled') {
            $commissionService = new CommissionService();
            foreach ($orders as $order) {
                if ($order->status !== 'cancelled') {
                    $user = $order->user;
                    $refundAmount = $order->total;
                    $balanceBefore = $user->wallet_balance;
                    
                    $user->increment('wallet_balance', $refundAmount);
                    
                    Transaction::create([
                        'user_id' => $user->id,
                        'order_id' => $order->id,
                        'amount' => $refundAmount,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $user->fresh()->wallet_balance,
                        'status' => 'completed',
                        'type' => 'refund',
                        'description' => "Refund for cancelled order #{$order->id}",
                    ]);

                    $commissionService->reverseCommission($order);
                }
            }
        }

        return redirect()->back()->with('success', "Updated {$updatedCount} order(s) successfully.");
    }

    /**
     * Display the admin transactions page.
     */
    public function transactions(Request $request)
    {
        $transactions = Transaction::with('user', 'order.user')->latest();

        if ($request->filled('type')) {
            $transactions->where('type', $request->input('type'));
        }

        if ($request->filled('date')) {
            $transactions->whereDate('created_at', $request->input('date'));
        }

        return Inertia::render('Admin/Transactions', [
            'transactions' => $transactions->paginate(10)->withQueryString(),
            'filterType' => $request->input('type', ''),
            'filterDate' => $request->input('date', ''),
        ]);
    }

    /**
     * Store a new user.
     */
    public function storeUser(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|string|in:customer,agent,dealer,elite,admin',
        ]);

        User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'role' => $request->role,
        ]);

        return redirect()->route('admin.users');
    }

    /**
     * Update the user's role.
     */
    public function updateUserRole(Request $request, User $user)
    {
        $request->validate([
            'role' => 'required|string|in:customer,agent,dealer,elite,admin',
        ]);

        $user->update([
            'role' => $request->role,
        ]);

        return redirect()->route('admin.users');
    }

    /**
     * Delete the user.
     */
    public function deleteUser(User $user)
    {
        $user->delete();

        return redirect()->route('admin.users');
    }

    /**
     * Credit user's wallet.
     */
    public function creditWallet(Request $request, User $user)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

        $balanceBefore = $user->wallet_balance;
        $user->increment('wallet_balance', $request->amount);

        // Create transaction record
        Transaction::create([
            'user_id' => $user->id,
            'order_id' => null,
            'amount' => $request->amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $user->fresh()->wallet_balance,
            'status' => 'completed',
            'type' => 'credit',
            'description' => 'Admin wallet credit of GHS ' . number_format($request->amount, 2),
        ]);

        return redirect()->route('admin.users')->with('success', 'Wallet credited successfully.');
    }

    /**
     * Debit user's wallet.
     */
    public function debitWallet(Request $request, User $user)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

        if ($user->wallet_balance < $request->amount) {
            return redirect()->route('admin.users')->with('error', 'Insufficient wallet balance.');
        }

        $balanceBefore = $user->wallet_balance;
        $user->decrement('wallet_balance', $request->amount);

        // Create transaction record
        Transaction::create([
            'user_id' => $user->id,
            'order_id' => null,
            'amount' => $request->amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $user->fresh()->wallet_balance,
            'status' => 'completed',
            'type' => 'debit',
            'description' => 'Admin wallet debit of GHS ' . number_format($request->amount, 2),
        ]);

        return redirect()->route('admin.users')->with('success', 'Wallet debited successfully.');
    }

    /**
     * Store a new product.
     */
    public function storeProduct(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'network' => 'required|string|max:255',
            'description' =>'required|string|max:255',
            'expiry' =>'required|string|max:255',
            'status'=>'required|string|max:255',
            'quantity' => 'required|string|max:255',
            'price' => 'required|numeric',
            'product_type' => 'required|string|in:agent_product,customer_product,dealer_product,elite_product',
        ]);

        Product::create([
            'name' => $request->name,
            'network' => $request->network,
            'description'=> $request ->description,
            'expiry'=> $request->expiry,
            'status'=>$request ->status,
            'quantity'=>$request->quantity,
            'price' => $request->price,
            'product_type' => $request->product_type,
        ]);

        return redirect()->route('admin.products');
    }

    /**
     * Update a product.
     */
    public function updateProduct(Request $request, Product $product)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'network' => 'required|string|max:255',
            'description' =>'required|string|max:255',
            'expiry' =>'required|string|max:255',
            'status'=>'required|string|max:255',
            'quantity' => 'required|string|max:255',
            'price' => 'required|numeric',
            'product_type' => 'required|string|in:agent_product,customer_product,dealer_product,elite_product',
        ]);

        $product->update([
            'name' => $request->name,
            'network' => $request->network,
            'description'=> $request->description,
            'expiry'=> $request->expiry,
            'status'=>$request->status,
            'quantity'=>$request->quantity,
            'price' => $request->price,
            'product_type' => $request->product_type,
        ]);

        return redirect()->route('admin.products');
    }

    /**
     * Delete a product.
     */
    public function deleteProduct(Product $product)
    {
        $product->delete();
        return redirect()->route('admin.products');
    }

    /**
     * Set all MTN products out of stock at once.
     */
    public function bulkMtnOutOfStock()
    {
        $count = Product::where('network', 'like', '%MTN%')
            ->where('status', 'IN STOCK')
            ->update(['status' => 'OUT OF STOCK']);

        return redirect()->route('admin.products')
            ->with('success', "{$count} MTN product(s) set to OUT OF STOCK.");
    }

    /**
     * Set all MTN products in stock at once.
     */
    public function bulkMtnInStock()
    {
        $count = Product::where('network', 'like', '%MTN%')
            ->where('status', 'OUT OF STOCK')
            ->update(['status' => 'IN STOCK']);

        return redirect()->route('admin.products')
            ->with('success', "{$count} MTN product(s) set to IN STOCK.");
    }

    /**
     * Display user transaction history.
     */
    public function userTransactions(Request $request, User $user)
    {
        $transactions = Transaction::where('user_id', $user->id)
            ->with('order')
            ->latest();

        if ($request->filled('type')) {
            $transactions->where('type', $request->input('type'));
        }

        if ($request->filled('date')) {
            $transactions->whereDate('created_at', $request->input('date'));
        }

        return Inertia::render('Admin/UserTransactions', [
            'user' => $user,
            'transactions' => $transactions->paginate(20)->withQueryString(),
            'filterType' => $request->input('type', ''),
            'filterDate' => $request->input('date', ''),
        ]);
    }

    /**
     * Export selected orders to CSV.
     */
    public function exportOrders(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'exists:orders,id',
        ]);

        $orders = Order::with(['products' => function($query) {
            $query->withPivot('quantity', 'beneficiary_number');
        }])->whereIn('id', $request->order_ids)->get();

        $filename = 'orders_' . date('Y-m-d_H-i-s') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($orders) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Number', 'Volume']);
            
            foreach ($orders as $order) {
                foreach ($order->products as $product) {
                    fputcsv($file, [
                        $product->pivot->beneficiary_number ?? 'N/A',
                        $product->pivot->quantity ?? 0
                    ]);
                }
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Toggle API status.
     */
    public function toggleApi(Request $request)
    {
        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        Setting::set('api_enabled', $request->enabled ? 'true' : 'false');

        return redirect()->back()->with('success', 'API status updated successfully.');
    }

    /**
     * Toggle CodeCraft API status.
     */
    public function toggleCodeCraftApi(Request $request)
    {
        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        Setting::set('codecraft_api_enabled', $request->enabled ? 'true' : 'false');

        return redirect()->back()->with('success', 'CodeCraft API status updated successfully.');
    }

    /**
     * Toggle ProdataWorld API status.
     */
    public function toggleProdataWorldApi(Request $request)
    {
        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        Setting::set('prodataworld_api_enabled', $request->enabled ? 'true' : 'false');

        return redirect()->back()->with('success', 'ProdataWorld API status updated successfully.');
    }

    /**
     * Toggle CodeCraft MTN API status.
     */
    public function toggleCodeCraftMtnApi(Request $request)
    {
        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        Setting::set('codecraft_mtn_api_enabled', $request->enabled ? 'true' : 'false');

        return redirect()->back()->with('success', 'CodeCraft MTN API status updated successfully.');
    }

    /**
     * Toggle DataEasy API status.
     */
    public function toggleDataEasyApi(Request $request)
    {
        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        Setting::set('dataeasy_api_enabled', $request->enabled ? 'true' : 'false');

        return redirect()->back()->with('success', 'DataEasy API status updated successfully.');
    }

    /**
     * Toggle Bundle Portal API status (Telecel/AT/Ishare).
     */
    public function toggleBundlePortalApi(Request $request)
    {
        $request->validate(['enabled' => 'required|boolean']);
        Setting::set('bundleportal_api_enabled', $request->enabled ? 'true' : 'false');
        return redirect()->back()->with('success', 'Bundle Portal API (Telecel/AT) status updated successfully.');
    }

    /**
     * Toggle Bundle Portal MTN API status.
     */
    public function toggleBundlePortalMtnApi(Request $request)
    {
        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        Setting::set('bundleportal_mtn_api_enabled', $request->enabled ? 'true' : 'false');

        return redirect()->back()->with('success', 'Bundle Portal MTN API status updated successfully.');
    }

    /**
     * Toggle DataFlow API status.
     */
    public function toggleDataFlowApi(Request $request)
    {
        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        Setting::set('dataflow_api_enabled', $request->enabled ? 'true' : 'false');

        return redirect()->back()->with('success', 'DataFlow API status updated successfully.');
    }

    /**
     * Display alerts management page.
     */
    public function alerts()
    {
        $alerts = Alert::latest()->get();
        return Inertia::render('Admin/Alerts', [
            'alerts' => $alerts
        ]);
    }

    /**
     * Store a new alert.
     */
    public function storeAlert(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'required|string|in:info,warning,success,error',
            'expires_at' => 'nullable|date|after:now'
        ]);

        Alert::create($request->all());
        return redirect()->back()->with('success', 'Alert created successfully.');
    }

    /**
     * Update an alert.
     */
    public function updateAlert(Request $request, Alert $alert)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'required|string|in:info,warning,success,error',
            'is_active' => 'boolean',
            'expires_at' => 'nullable|date|after:now'
        ]);

        $data = $request->all();
        if (empty($data['expires_at'])) {
            $data['expires_at'] = null;
        }
        
        $alert->update($data);
        return redirect()->back()->with('success', 'Alert updated successfully.');
    }

    /**
     * Delete an alert.
     */
    public function deleteAlert(Alert $alert)
    {
        $alert->delete();
        return redirect()->back()->with('success', 'Alert deleted successfully.');
    }
}