<?php

namespace Database\Factories;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\Call;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Call>
 */
class CallFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phone' => '09121234567',
            'extension' => '1001',
            'direction' => CallDirection::Inbound,
            'status' => CallStatus::Ringing,
            'unique_id' => null,
            'started_at' => now()->subMinutes(5),
            'answered_at' => null,
            'ended_at' => null,
            'duration' => null,
        ];
    }

    public function ended(): static
    {
        return $this->state(function (): array {
            $started = now()->subMinutes(10);
            $answered = $started->copy()->addSeconds(8);
            $ended = $answered->copy()->addSeconds(90);

            return [
                'status' => CallStatus::Ended,
                'started_at' => $started,
                'answered_at' => $answered,
                'ended_at' => $ended,
                'duration' => 90,
            ];
        });
    }
}
