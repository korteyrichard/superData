<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AdminSettingsController extends Controller
{
    public function index()
    {
        $settings = [
            'how_to_track_orders_youtube_link' => Setting::get('how_to_track_orders_youtube_link', ''),
            'how_to_verify_topup_youtube_link' => Setting::get('how_to_verify_topup_youtube_link', ''),
            'minimum_withdrawal' => Setting::get('minimum_withdrawal', '10.00'),
            'agent_fee' => Setting::get('agent_fee', '0.00'),
            'referral_commission' => Setting::get('referral_commission', '0.50'),
            'order_based_referral_commission' => Setting::get('order_based_referral_commission', '0.50'),
        ];

        return Inertia::render('Admin/Settings', [
            'settings' => $settings
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'how_to_track_orders_youtube_link' => 'nullable|url',
            'how_to_verify_topup_youtube_link' => 'nullable|url',
            'minimum_withdrawal' => 'required|numeric|min:0',
            'agent_fee' => 'required|numeric|min:0',
            'referral_commission' => 'required|numeric|min:0',
            'order_based_referral_commission' => 'required|numeric|min:0',
        ]);

        $settings = [
            'how_to_track_orders_youtube_link',
            'how_to_verify_topup_youtube_link',
            'minimum_withdrawal',
            'agent_fee',
            'referral_commission',
            'order_based_referral_commission'
        ];

        foreach ($settings as $key) {
            Setting::set($key, $request->input($key));
        }

        return redirect()->back()->with('success', 'Settings updated successfully!');
    }
}