<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class DownloadImage
{
    private const MAX_BYTES = 20 * 1024 * 1024;

    public function fetch(string $url): string
    {
        $origin = parse_url(config('pixelforge.base_url'));
        $image = parse_url($url);
        abort_unless(
            is_array($image)
            && ($image['scheme'] ?? null) === 'https'
            && ($image['host'] ?? null) === ($origin['host'] ?? null)
            && ! isset($image['user'])
            && ! isset($image['pass'])
            && ! isset($image['port'])
            && str_starts_with($image['path'] ?? '', '/images/'),
            502,
            'The provider returned an unsupported image address.',
        );

        try {
            $response = Http::accept('image/png')
                ->withoutRedirecting()
                ->connectTimeout(5)
                ->timeout(20)
                ->withOptions(['stream' => true, 'read_timeout' => 20])
                ->get($url);
        } catch (ConnectionException) {
            abort(502, 'The image provider is unavailable. Please try again.');
        }

        abort_if(in_array($response->status(), [403, 404, 410], true), 410, 'This image link is no longer available.');
        abort_unless($response->successful(), 502, 'The image provider is unavailable. Please try again.');

        $stream = $response->toPsrResponse()->getBody();
        $contents = '';
        try {
            while (! $stream->eof()) {
                $contents .= $stream->read(65536);
                abort_if(strlen($contents) > self::MAX_BYTES, 502, 'The image exceeds the download size limit.');
            }
        } catch (\RuntimeException) {
            abort(502, 'The image download was interrupted. Please try again.');
        } finally {
            $stream->close();
        }

        abort_unless(str_starts_with($contents, "\x89PNG\r\n\x1a\n"), 502, 'The provider did not return a PNG image.');

        return $contents;
    }
}
