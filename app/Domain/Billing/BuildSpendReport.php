<?php

namespace App\Domain\Billing;

use App\Domain\Generations\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Collection;

final class BuildSpendReport
{
    public function __construct(private PixelforgeClient $client) {}

    public function execute(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $generations = Generation::query()
            ->select(['id', 'user_id', 'completed_at', 'provider_cost', 'actual_cost'])
            ->with('user:id,name,email')
            ->where('status', GenerationStatus::Succeeded)
            ->whereBetween('completed_at', [$start, $end])
            ->get();

        $usage = [];
        $error = null;
        try {
            $usage = $this->client->usage($start->toDateString(), $end->toDateString());
        } catch (HttpClientException) {
            $error = 'Provider usage is unavailable. Local totals are shown; reconciliation is not verified.';
        }

        return [
            'days' => $this->dailyReconciliation($generations, $usage, $start, $end),
            'users' => $this->perUser($generations),
            'totals' => $this->totals($generations),
            'error' => $error,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'reviewCount' => Generation::where('needs_review', true)->count(),
        ];
    }

    /** @return array{count: int, spend: string, provider: string, profit: string} */
    private function totals(Collection $generations): array
    {
        $spend = $generations->sum('actual_cost');
        $providerCost = $generations->sum('provider_cost');

        return [
            'count' => $generations->count(),
            'spend' => Money::usd($spend),
            'provider' => Money::usd($providerCost),
            'profit' => Money::usd($spend - $providerCost),
        ];
    }

    private function byDay(Collection $generations): Collection
    {
        return $generations
            ->groupBy(fn (Generation $generation): string => $generation->completed_at->utc()->toDateString())
            ->map(fn (Collection $items): array => $this->totals($items));
    }

    private function perUser(Collection $generations): Collection
    {
        return $generations->groupBy('user_id')->map(fn (Collection $items): array => [
            'name' => $items->first()->user->name,
            'email' => $items->first()->user->email,
            ...$this->totals($items),
            'days' => $this->byDay($items)
                ->map(fn (array $totals, string $date): array => ['date' => $date, ...$totals])
                ->values(),
        ])->values();
    }

    private function dailyReconciliation(Collection $generations, array $usage, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $localDays = $this->byDay($generations);
        $providerDays = collect($usage['data'] ?? [])->keyBy('date');
        $days = [];

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $key = $date->toDateString();
            $local = $localDays->get($key, $this->totals(collect()));
            $reported = $providerDays->get($key)['cost_usd'] ?? null;
            $days[] = [
                'date' => $key,
                ...$local,
                'reported' => $reported,
                'difference' => $reported === null
                    ? null
                    : Money::usd(Money::fromUsd($local['provider']) - Money::fromUsd($reported)),
            ];
        }

        return $days;
    }
}
