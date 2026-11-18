<?php

namespace App\Http\Controllers;

use App\Http\Requests\PixelforgeWebhookRequest;
use App\Services\Pixelforge\ReceiveWebhook;
use Illuminate\Http\Response;

final class PixelforgeWebhookController
{
    public function __invoke(PixelforgeWebhookRequest $request, ReceiveWebhook $receiveWebhook): Response
    {
        $receiveWebhook->execute($request->event());

        return response()->noContent();
    }
}
