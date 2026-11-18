<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyPixelforgeSignature
{
    private const MAX_PAYLOAD_BYTES = 131072;

    private const TIMESTAMP_TOLERANCE_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('pixelforge.webhook_secret');
        abort_unless(is_string($secret) && $secret !== '', 503);

        $payload = $request->getContent();
        abort_if(strlen($payload) > self::MAX_PAYLOAD_BYTES, 413);

        $signature = (string) $request->header('Pixelforge-Signature');
        abort_unless(
            preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/D', $signature, $matches) === 1,
            400,
            'Invalid signature.',
        );

        $timestamp = $matches[1];
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        $isRecent = abs(now()->timestamp - (int) $timestamp) <= self::TIMESTAMP_TOLERANCE_SECONDS;
        abort_unless($isRecent && hash_equals($expected, $matches[2]), 400, 'Invalid signature.');

        return $next($request);
    }
}
