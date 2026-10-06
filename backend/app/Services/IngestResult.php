<?php

namespace App\Services;

use App\Models\Call;

class IngestResult
{
    /**
     * @param  array{user_id: int, call: array<string, mixed>}|null  $broadcast
     */
    public function __construct(
        public int $httpStatus,
        public bool $duplicate,
        public bool $applied,
        public ?string $reason,
        public ?Call $call,
        public bool $summarizeCall,
        public ?array $broadcast,
        public string $outcome,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        $body = [
            'duplicate' => $this->duplicate,
            'applied' => $this->applied,
        ];

        if ($this->reason !== null) {
            $body['reason'] = $this->reason;
        }

        if ($this->call !== null) {
            $call = [
                'id' => $this->call->id,
                'status' => $this->call->status->value,
                'direction' => $this->call->direction->value,
            ];

            if (! $this->summarizeCall) {
                $this->call->loadMissing('customer');
                $customer = $this->call->customer;
                $call['extension'] = $this->call->extension_number;
                $call['customer'] = $customer === null ? null : [
                    'id' => $customer->id,
                    'name' => $customer->name,
                ];
            }

            $body['call'] = $call;
        }

        return $body;
    }
}
