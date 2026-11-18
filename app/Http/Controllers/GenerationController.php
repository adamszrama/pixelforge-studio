<?php

namespace App\Http\Controllers;

use App\Domain\Billing\Money;
use App\Domain\Billing\Quote;
use App\Domain\Generations\CreateGeneration;
use App\Http\Requests\GenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class GenerationController
{
    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()
            ->latest()
            ->paginate(12)
            ->through(fn (Generation $generation): array => (new GenerationResource($generation))->resolve($request));

        return Inertia::render('Studio', ['generations' => $generations]);
    }

    public function quote(GenerationRequest $request, PixelforgeClient $client, Quote $quote): JsonResponse
    {
        try {
            $pricing = $client->pricing();
        } catch (HttpClientException) {
            return response()->json(['message' => 'Pricing is temporarily unavailable. Please try again.'], 503);
        }
        $amounts = $quote->calculate($request->validated(), $pricing);

        return response()->json([
            'estimate' => Money::usd($amounts['estimate']), 'maximum' => Money::usd($amounts['maximum']),
            'rates' => $pricing['rates'],
        ]);
    }

    public function store(GenerationRequest $request, PixelforgeClient $client, CreateGeneration $create): RedirectResponse
    {
        if (! config('pixelforge.api_key')) {
            return back()->withErrors(['provider' => 'An operator must configure the Pixelforge connection first.']);
        }
        try {
            $pricing = $client->pricing();
        } catch (HttpClientException) {
            return back()->withErrors(['provider' => 'Pricing is unavailable. No credits were reserved. Please retry.']);
        }
        $generation = $create->execute($request->user(), $request->validated(), $pricing);
        SyncGeneration::dispatch($generation->id)->afterCommit();

        return to_route('studio')->with('success', 'Generation queued. We will update it automatically.');
    }
}
