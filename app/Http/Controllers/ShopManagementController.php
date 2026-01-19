<?php

namespace App\Http\Controllers;

use App\Models\AgentShop;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Validation\Rule;

class ShopManagementController extends Controller
{
    public function create()
    {
        $user = auth()->user();
        
        if ($user->agentShop) {
            return redirect()->route('dealer.shop.edit');
        }

        return Inertia::render('Dashboard/CreateShop');
    }

    public function store(Request $request)
    {
        $user = $request->user();
        
        if ($user->agentShop) {
            return redirect()->route('dealer.shop.edit')->with('error', 'You already have a shop');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:50|unique:agent_shops,username|regex:/^[a-zA-Z0-9_-]+$/',
            'color' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/'
        ]);

        $shop = AgentShop::create([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'username' => $validated['username'],
            'color' => $validated['color'],
            'is_active' => true
        ]);

        return redirect()->route('dealer.dashboard')->with('success', 'Shop created successfully!');
    }

    public function edit()
    {
        $user = auth()->user();
        
        if (!$user->agentShop) {
            return redirect()->route('dealer.shop.create');
        }

        return Inertia::render('Dashboard/EditShop', [
            'shop' => $user->agentShop
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $shop = $user->agentShop;
        
        if (!$shop) {
            return redirect()->route('dealer.shop.create');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'is_active' => 'boolean'
        ]);

        $shop->update($validated);

        return redirect()->back()->with('success', 'Shop updated successfully!');
    }
}