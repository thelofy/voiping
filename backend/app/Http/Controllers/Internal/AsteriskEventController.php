<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAsteriskEventRequest;
use App\Services\CallIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class AsteriskEventController extends Controller
{
    public function store(StoreAsteriskEventRequest $request, CallIngestService $ingest): JsonResponse
    {
        $payload = $request->validated();
        $result = $ingest->ingest($payload);

        Log::info('asterisk.ingest', [
            'event_id' => $payload['event_id'],
            'event_type' => $payload['event_type'],
            'linkedid' => $payload['asterisk']['linkedid'],
            'extension' => $payload['call']['extension'],
            'outcome' => $result->outcome,
            'http_status' => $result->httpStatus,
        ]);

        return response()->json($result->body(), $result->httpStatus);
    }
}
