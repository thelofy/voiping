<?php

namespace App\Http\Controllers;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Http\Requests\ListCallLogsRequest;
use App\Http\Requests\StoreCallLogRequest;
use App\Http\Resources\CallLogResource;
use App\Models\User;
use App\Services\CallLogService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\View\View;

class CallLogController extends Controller
{
    public function __construct(private readonly CallLogService $callLogs) {}

    public function index(ListCallLogsRequest $request): View
    {
        return view('calls.index', [
            'calls' => $this->callLogs->paginate($request->validated(), $this->viewer($request)),
            'filters' => $request->validated(),
            'statuses' => CallStatus::definitions(),
            'directions' => CallDirection::cases(),
        ]);
    }

    public function apiIndex(ListCallLogsRequest $request): AnonymousResourceCollection
    {
        return CallLogResource::collection(
            $this->callLogs->paginate($request->validated(), $this->viewer($request)),
        );
    }

    public function store(StoreCallLogRequest $request): JsonResponse
    {
        $call = $this->callLogs->record($request->validated());

        return (new CallLogResource($call))
            ->response()
            ->setStatusCode(201);
    }

    private function viewer(FormRequest $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
