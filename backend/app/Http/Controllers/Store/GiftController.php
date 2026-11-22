<?php

namespace App\Http\Controllers\Store;

use App\Domain\Commerce\CheckoutException;
use App\Domain\Commerce\RedeemGiftCode;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class GiftController extends Controller
{
    public function show()
    {
        return view('store.gift');
    }

    public function redeem(Request $request, RedeemGiftCode $redeem)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        try {
            $grant = $redeem->handle($request->user(), $data['code']);
        } catch (CheckoutException $e) {
            return back()->withErrors(['code' => $e->getMessage()])->withInput();
        }

        return redirect()->route('library')->with('status', __('gift.redeemed', ['name' => $grant->package->name]));
    }
}
