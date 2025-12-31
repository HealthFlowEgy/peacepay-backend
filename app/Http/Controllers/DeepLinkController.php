<?php

namespace App\Http\Controllers;

use App\Models\Escrow;
use Illuminate\Http\Request;

class DeepLinkController extends Controller
{
    /**
     * Redirect to mobile app to show escrow details
     *
     * @param string $escrow_id
     * @return \Illuminate\Http\Response
     */
    public function escrow($escrow_id)
    {
        // Verify escrow exists
        $escrow = Escrow::where('id', $escrow_id)
            ->orWhere('escrow_id', $escrow_id)
            ->first();

        if (!$escrow) {
            return response()->view('errors.404', [], 404);
        }

        // Get the app scheme from environment or use default
        $appScheme = env('MOBILE_APP_SCHEME', 'peacepay');

        // Create the deep link URL using numeric ID
        $deepLink = $appScheme . '://escrow/' . $escrow->id;

        // Return a view with meta redirect and JavaScript fallback
        return view('deeplink.redirect', [
            'deepLink' => $deepLink,
            'escrow' => $escrow,
            'fallbackUrl' => env('MOBILE_APP_STORE_URL', '#')
        ]);
    }
}
