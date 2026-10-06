<?php

namespace App\Enums;

/**
 * Lifecycle of one call log row.
 *
 * Duration is stored only for `ended`, and it is the whole seconds from
 * `answered_at` to `ended_at`. Unanswered calls keep a null duration.
 *
 * - ringing: alerting, not answered, not finished.
 * - answered: answered and still in progress.
 * - ended: answered, then finished normally.
 * - missed: finished without an answer.
 * - busy: the destination was busy.
 * - failed: the call could not be completed.
 * - incomplete: started, and no terminal result was recorded.
 */
enum CallStatus: string
{
    case Ringing = 'ringing';
    case Answered = 'answered';
    case Ended = 'ended';
    case Missed = 'missed';
    case Busy = 'busy';
    case Failed = 'failed';
    case Incomplete = 'incomplete';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Ended, self::Missed, self::Busy, self::Failed, self::Incomplete => true,
            self::Ringing, self::Answered => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Ringing => 'Ringing',
            self::Answered => 'Answered',
            self::Ended => 'Ended',
            self::Missed => 'Missed',
            self::Busy => 'Busy',
            self::Failed => 'Failed',
            self::Incomplete => 'Incomplete',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Ringing => 'The call is alerting and has not been answered.',
            self::Answered => 'The call was answered and is still in progress.',
            self::Ended => 'The call was answered and then finished. Duration is the seconds from answer to end.',
            self::Missed => 'The call ended without being answered.',
            self::Busy => 'The destination was busy.',
            self::Failed => 'The call could not be completed (unavailable, congestion, or a similar failure).',
            self::Incomplete => 'The call started but never received a terminal result.',
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function definitions(): array
    {
        return array_map(
            fn (self $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
                'description' => $status->description(),
            ],
            self::cases(),
        );
    }
}
