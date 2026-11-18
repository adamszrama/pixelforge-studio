<?php

namespace App\Http\Controllers;

use App\Domain\Billing\Money;
use App\Domain\Billing\Quote;
use App\Domain\Generations\CreateGeneration;
use App\Http\Requests\GenerationRequest;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class GenerationController
{
    public function index(Request $request)
    {
        $generations = Generation::where('user_id', $request->user()->id)->latest()->paginate(12)->through(fn (Generation $g) => [
            'id' => $g->id, 'prompt' => $g->prompt, 'status' => $g->status->value,
            'size' => $g->size, 'quality' => $g->quality,
            'estimated_cost' => Money::usd($g->estimated_cost),
            'actual_cost' => $g->actual_cost === null ? null : Money::usd($g->actual_cost),
            'image_url' => $g->image_url, 'image_expired' => $g->image_url_expires_at?->isPast() ?? false,
            'message' => $g->message, 'needs_review' => $g->needs_review,
            'created_at' => $g->created_at->toIso8601String(),
        ]);

        return Inertia::render('Studio', ['generations' => $generations]);
    }

    public function quote(GenerationRequest $request, PixelforgeClient $client, Quote $quote)
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

    public function store(GenerationRequest $request, PixelforgeClient $client, CreateGeneration $create)
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
