<?php

namespace App\Services\Pixelforge;

use App\Models\Generation;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class PixelforgeClient
{
    private function http(): PendingRequest
    {
        return Http::baseUrl(config('pixelforge.base_url'))->acceptJson()
            ->withToken((string) config('pixelforge.api_key'))->connectTimeout(5)->timeout(20);
    }

    public function pricing(): array
    {
        return Cache::remember('pixelforge.pricing', 60, fn () => $this->http()->get('/pricing')->throw()->json());
    }

    public function submit(Generation $generation): Response
    {
        return $this->http()->withHeader('Idempotency-Key', $generation->id)->post('/generations', [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => $generation->reference_images,
            'metadata' => ['generation_id' => $generation->id],
        ]);
    }

    public function retrieve(string $id): Response
    {
        return $this->http()->get('/generations/'.rawurlencode($id));
    }

    public function usage(string $start, string $end): array
    {
        return $this->http()->get('/usage', ['start_date' => $start, 'end_date' => $end])->throw()->json();
    }
}
