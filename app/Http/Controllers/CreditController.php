<?php

namespace App\Http\Controllers;

use App\Domain\Billing\Wallet;
use App\Http\Requests\PurchaseCreditsRequest;
use Illuminate\Http\RedirectResponse;

final class CreditController
{
    public function __invoke(PurchaseCreditsRequest $request, Wallet $wallet): RedirectResponse
    {
        $wallet->purchase($request->user(), $request->validated('purchase_id'));

        return back()->with('success', '$10 in demo credits added. No real payment.');
    }
}
