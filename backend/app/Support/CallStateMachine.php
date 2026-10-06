<?php

namespace App\Support;

use App\Enums\CallStatus;

/**
 * Legal call transitions from a canonical Asterisk event.
 *
 * A null result means the event must not change the row.
 */
class CallStateMachine
{
    public function next(?CallStatus $current, string $eventType, ?string $dialStatus): ?CallStatus
    {
        if ($current === null) {
            return $eventType === 'call.ringing' ? CallStatus::Ringing : null;
        }

        if ($current->isTerminal()) {
            return null;
        }

        if ($current === CallStatus::Ringing) {
            return match ($eventType) {
                'call.ringing' => CallStatus::Ringing,
                'call.answered' => CallStatus::Answered,
                'call.ended' => $this->unansweredStatus($dialStatus),
                default => null,
            };
        }

        if ($current === CallStatus::Answered) {
            return match ($eventType) {
                'call.answered' => CallStatus::Answered,
                'call.ended' => CallStatus::Ended,
                default => null,
            };
        }

        return null;
    }

    public function unansweredStatus(?string $dialStatus): CallStatus
    {
        $value = strtoupper(trim((string) $dialStatus));
        $value = preg_replace('/^CAUSE[:\s]*/', '', $value) ?? $value;
        $value = str_replace(' ', '', $value);

        return match (true) {
            in_array($value, ['BUSY', '17'], true) => CallStatus::Busy,
            in_array($value, ['NOANSWER', 'CANCEL', '19'], true) => CallStatus::Missed,
            in_array($value, ['CHANUNAVAIL', 'CONGESTION', '1', '34'], true) => CallStatus::Failed,
            default => CallStatus::Missed,
        };
    }
}
