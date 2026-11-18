<?php

namespace App\Http\Controllers;

use App\Domain\Billing\Wallet;
use Illuminate\Http\Request;

final class CreditController
{
    public function __invoke(Request $request, Wallet $wallet)
    {
        $input = $request->validate(['purchase_id' => 'required|uuid']);
        $wallet->purchase($request->user(), $input['purchase_id']);

        return back()->with('success', '$10 in demo credits added. No real payment.');
    }
}
