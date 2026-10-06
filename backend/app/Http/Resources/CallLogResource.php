<?php

namespace App\Http\Resources;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\Call;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Call
 */
class CallLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'user_id' => $this->user_id,
            'phone' => $this->phone,
            'extension' => $this->extension,
            'direction' => $this->direction instanceof CallDirection ? $this->direction->value : $this->direction,
            'status' => $this->status instanceof CallStatus ? $this->status->value : $this->status,
            'unique_id' => $this->unique_id,
            'started_at' => $this->started_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'answered_at' => $this->answered_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'ended_at' => $this->ended_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'duration' => $this->duration,
            'customer' => $this->relationLoaded('customer') && $this->customer !== null
                ? ['id' => $this->customer->id, 'name' => $this->customer->name]
                : null,
            'user' => $this->relationLoaded('user') && $this->user !== null
                ? ['id' => $this->user->id, 'name' => $this->user->name]
                : null,
        ];
    }
}
