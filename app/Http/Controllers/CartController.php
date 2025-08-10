<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CartController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|string|min:1',
            'beneficiary_number' => 'required|string|max:20',
        ]);

        $user = Auth::user();
        
        Cart::create([
            'user_id' => $user->id,
            'product_id' => $request->product_id,
            'quantity' => $request->quantity,
            'beneficiary_number' => $request->beneficiary_number,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Added to cart']);
        }
        
        return redirect()->back()->with('success', 'Product added to cart!');
    }

    public function index()
    {
        $cartItems = Cart::with('product')->where('user_id', Auth::id())->get();
        return inertia('Dashboard/Cart', [
            'cartItems' => $cartItems,
        ]);
    }

    public function destroy($id)
    {
        $cart = Cart::where('user_id', Auth::id())->where('id', $id)->first();
        
        if (!$cart) {
            abort(404, 'Cart item not found');
        }
        
        $cart->delete();
        return redirect()->back()->with('success', 'Product removed from cart!');
    }
}
