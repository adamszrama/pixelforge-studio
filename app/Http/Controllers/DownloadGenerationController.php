<?php

namespace App\Http\Controllers;

use App\Domain\Generations\GenerationStatus;
use App\Services\Pixelforge\DownloadImage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadGenerationController
{
    public function __invoke(Request $request, string $generation, DownloadImage $download): StreamedResponse
    {
        $image = $request->user()->generations()->findOrFail($generation);
        abort_unless($image->status === GenerationStatus::Succeeded && $image->image_url, 409, 'This image is not ready to download.');
        abort_if($image->image_url_expires_at?->isPast(), 410, 'This image link has expired.');

        $contents = $download->fetch($image->image_url);

        return response()->streamDownload(function () use ($contents): void {
            echo $contents;
        }, 'pixelforge-'.$image->id.'.png', [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
