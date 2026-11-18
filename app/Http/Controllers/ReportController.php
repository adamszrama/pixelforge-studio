<?php

namespace App\Http\Controllers;

use App\Domain\Billing\Money;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class ReportController
{
    public function __invoke(Request $request, PixelforgeClient $client)
    {
        $input = $request->validate([
            'start' => 'sometimes|date_format:Y-m-d',
            'end' => 'sometimes|date_format:Y-m-d|after_or_equal:start',
        ]);
        $start = CarbonImmutable::parse($input['start'] ?? now()->utc()->subDays(29)->toDateString(), 'UTC')->startOfDay();
        $end = CarbonImmutable::parse($input['end'] ?? now()->utc()->toDateString(), 'UTC')->endOfDay();
        abort_if($start->gt($end) || $start->diffInDays($end) >= 92, 422, 'Select at most 92 days.');
        $rows = Generation::query()->select(['id', 'user_id', 'completed_at', 'provider_cost', 'actual_cost'])->with('user:id,name,email')->where('status', 'succeeded')
            ->whereBetween('completed_at', [$start, $end])->get();
        $group = fn ($items) => [
            'count' => $items->count(), 'spend' => Money::usd($items->sum('actual_cost')),
            'provider' => Money::usd($items->sum('provider_cost')),
            'profit' => Money::usd($items->sum('actual_cost') - $items->sum('provider_cost')),
        ];
        $daily = $rows->groupBy(fn ($g) => $g->completed_at->utc()->toDateString())->map($group);
        $users = $rows->groupBy('user_id')->map(fn ($items) => [
            'name' => $items->first()->user->name, 'email' => $items->first()->user->email,
            ...$group($items),
            'days' => $items->groupBy(fn ($g) => $g->completed_at->utc()->toDateString())
                ->map(fn ($items, $date) => ['date' => $date, ...$group($items)])->values(),
        ])->values();
        $usage = null;
        $error = null;
        try {
            $usage = $client->usage($start->toDateString(), $end->toDateString());
        } catch (HttpClientException) {
            $error = 'Provider usage is unavailable. Local totals are shown; reconciliation is not verified.';
        }
        $days = [];
        $providerDays = collect($usage['data'] ?? [])->keyBy('date');
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $key = $date->toDateString();
            $local = $daily->get($key, ['count' => 0, 'spend' => Money::usd(0), 'provider' => Money::usd(0), 'profit' => Money::usd(0)]);
            $reported = $providerDays->get($key)['cost_usd'] ?? null;
            $days[] = ['date' => $key, ...$local, 'reported' => $reported,
                'difference' => $reported === null ? null : Money::usd(Money::fromUsd($local['provider']) - Money::fromUsd($reported))];
        }

        return Inertia::render('Reports', [
            'days' => $days, 'users' => $users, 'totals' => $group($rows), 'error' => $error,
            'start' => $start->toDateString(), 'end' => $end->toDateString(),
            'reviewCount' => Generation::where('needs_review', true)->count(),
        ]);
    }
}
