<?php

namespace Tests\Unit;

use App\Enums\CallStatus;
use App\Support\CallStateMachine;
use PHPUnit\Framework\TestCase;

class CallStateMachineTest extends TestCase
{
    private CallStateMachine $states;

    protected function setUp(): void
    {
        parent::setUp();

        $this->states = new CallStateMachine;
    }

    public function test_ringing_starts_a_call(): void
    {
        $this->assertSame(CallStatus::Ringing, $this->states->next(null, 'call.ringing', null));
    }

    public function test_a_second_ringing_stays_ringing(): void
    {
        $this->assertSame(
            CallStatus::Ringing,
            $this->states->next(CallStatus::Ringing, 'call.ringing', null),
        );
    }

    public function test_answer_then_end_becomes_ended(): void
    {
        $answered = $this->states->next(CallStatus::Ringing, 'call.answered', 'ANSWER');
        $ended = $this->states->next($answered, 'call.ended', 'ANSWER');

        $this->assertSame(CallStatus::Answered, $answered);
        $this->assertSame(CallStatus::Ended, $ended);
    }

    public function test_unanswered_dial_status_selects_the_terminal_state(): void
    {
        $this->assertSame(CallStatus::Missed, $this->states->next(CallStatus::Ringing, 'call.ended', 'NOANSWER'));
        $this->assertSame(CallStatus::Missed, $this->states->next(CallStatus::Ringing, 'call.ended', 'CANCEL'));
        $this->assertSame(CallStatus::Missed, $this->states->next(CallStatus::Ringing, 'call.ended', '16'));
        $this->assertSame(CallStatus::Busy, $this->states->next(CallStatus::Ringing, 'call.ended', 'BUSY'));
        $this->assertSame(CallStatus::Busy, $this->states->next(CallStatus::Ringing, 'call.ended', '17'));
        $this->assertSame(CallStatus::Failed, $this->states->next(CallStatus::Ringing, 'call.ended', 'CHANUNAVAIL'));
        $this->assertSame(CallStatus::Failed, $this->states->next(CallStatus::Ringing, 'call.ended', 'CONGESTION'));
        $this->assertSame(CallStatus::Failed, $this->states->next(CallStatus::Ringing, 'call.ended', '34'));
    }

    public function test_events_never_move_a_call_backward(): void
    {
        $this->assertNull($this->states->next(CallStatus::Answered, 'call.ringing', null));
        $this->assertNull($this->states->next(CallStatus::Ended, 'call.answered', 'ANSWER'));
        $this->assertNull($this->states->next(CallStatus::Missed, 'call.ended', 'NOANSWER'));
        $this->assertNull($this->states->next(null, 'call.answered', 'ANSWER'));
    }
}
