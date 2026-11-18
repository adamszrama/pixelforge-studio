<?php

namespace App\Domain\Billing;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class Wallet
{
    public function purchase(User $user, string $purchaseId): void
    {
        DB::transaction(function () use ($user, $purchaseId) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            if (DB::table('ledger_entries')->where('purchase_id', $purchaseId)->exists()) {
                return;
            }
            $amount = Money::fromUsd('10');
            DB::table('ledger_entries')->insert([
                'user_id' => $user->id, 'purchase_id' => $purchaseId,
                'kind' => 'purchase', 'amount' => $amount, 'created_at' => now(),
            ]);
            $locked->increment('balance', $amount);
        }, 3);
    }
}
