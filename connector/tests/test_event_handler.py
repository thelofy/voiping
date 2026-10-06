import sys
import unittest
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from event_handler import normalize

FIXTURES = Path(__file__).resolve().parent / "fixtures"
WHEN = datetime(2026, 10, 5, 8, 10, tzinfo=timezone.utc)


def fixture(name: str) -> str:
    return (FIXTURES / name).read_text(encoding="utf-8")


class NormalizeTest(unittest.TestCase):
    def test_dialbegin_inbound(self):
        event = normalize(fixture("dialbegin_inbound.txt"), WHEN)
        self.assertIsNotNone(event)
        self.assertEqual("call.ringing", event["event_type"])
        self.assertEqual("1001", event["call"]["extension"])
        self.assertEqual("09121234567", event["call"]["caller_number"])
        self.assertEqual("1728000000.1", event["asterisk"]["linkedid"])
        self.assertEqual("1728000000.2", event["asterisk"]["uniqueid"])
        self.assertEqual("SIP/1001-00000002", event["asterisk"]["channel"])
        self.assertEqual("2026-10-05T08:10:00Z", event["occurred_at"])

    def test_dialend_answer(self):
        event = normalize(fixture("dialend_answer.txt"), WHEN)
        self.assertEqual("call.answered", event["event_type"])
        self.assertEqual("ANSWER", event["call"]["dial_status"])
        self.assertEqual("1001", event["call"]["extension"])

    def test_dialend_noanswer(self):
        event = normalize(fixture("dialend_noanswer.txt"), WHEN)
        self.assertEqual("call.ended", event["event_type"])
        self.assertEqual("NOANSWER", event["call"]["dial_status"])

    def test_hangup_uses_the_channel_extension(self):
        event = normalize(fixture("hangup_agent.txt"), WHEN)
        self.assertEqual("call.ended", event["event_type"])
        self.assertEqual("1001", event["call"]["extension"])
        self.assertEqual("SIP/1001-00000002", event["asterisk"]["channel"])
        self.assertEqual("16", event["call"]["dial_status"])

    def test_noise_and_missing_extension_are_dropped(self):
        self.assertIsNone(normalize(fixture("varset_noise.txt"), WHEN))
        self.assertIsNone(normalize(fixture("dialbegin_no_extension.txt"), WHEN))

    def test_pjsip_extension_comes_from_the_channel(self):
        event = normalize(fixture("pjsip_dialbegin.txt"), WHEN)
        self.assertEqual("call.ringing", event["event_type"])
        self.assertEqual("1001", event["call"]["extension"])
        self.assertEqual("PJSIP/1001-0000000a", event["asterisk"]["channel"])

    def test_hangup_legs_are_not_self_calls(self):
        first = (
            "Event: Hangup\nCallerIDNum: 102\nCallerIDName: test 102\n"
            "Uniqueid: 1791224971.52\nLinkedid: 1791224971.52\n"
            "Channel: PJSIP/102-00000007\nCause: 16\nCause-txt: Normal Clearing\n"
        )
        second = (
            "Event: Hangup\nCallerIDNum: 101\nCallerIDName: test 101\n"
            "Uniqueid: 1791224971.54\nLinkedid: 1791224971.52\n"
            "Channel: PJSIP/101-00000008\nCause: 16\nCause-txt: Normal Clearing\n"
        )

        caller_leg = normalize(first, WHEN)
        called_leg = normalize(second, WHEN)

        self.assertEqual("call.ended", caller_leg["event_type"])
        self.assertEqual("102", caller_leg["call"]["extension"])
        self.assertEqual("102", caller_leg["call"]["caller_number"])
        self.assertEqual("16", caller_leg["call"]["dial_status"])
        self.assertEqual("1791224971.52", caller_leg["asterisk"]["linkedid"])
        self.assertEqual("1791224971.52", caller_leg["asterisk"]["uniqueid"])
        self.assertEqual("PJSIP/102-00000007", caller_leg["asterisk"]["channel"])

        self.assertEqual("call.ended", called_leg["event_type"])
        self.assertEqual("101", called_leg["call"]["extension"])
        self.assertEqual("101", called_leg["call"]["caller_number"])
        self.assertEqual("PJSIP/101-00000008", called_leg["asterisk"]["channel"])

    def test_internal_call_keeps_the_caller_and_the_dialed_extension(self):
        ringing = (
            "Event: DialBegin\nCallerIDNum: 101\nCallerIDName: test 101\n"
            "Uniqueid: 1791227125.195\nLinkedid: 1791227125.195\n"
            "Channel: PJSIP/101-00000019\nDestChannel: PJSIP/102-0000001a\n"
            "DestUniqueid: 1791227125.197\n"
            "DialString: 102/sip:102@192.168.37.104:40533;transport=udp\n"
        )
        dial_string_only = (
            "Event: DialBegin\nCallerIDNum: 101\nCallerIDName: test 101\n"
            "Uniqueid: 1791227125.195\nLinkedid: 1791227125.195\n"
            "Channel: PJSIP/101-00000019\nDestUniqueid: 1791227125.197\n"
            "DialString: 102/sip:102@192.168.37.104:40533;transport=udp\n"
        )
        swapped_caller_id = (
            "Event: DialBegin\nCallerIDNum: 102\nCallerIDName: test 102\n"
            "Uniqueid: 1791227125.195\nLinkedid: 1791227125.195\n"
            "Channel: PJSIP/101-00000019\nDestChannel: PJSIP/102-0000001a\n"
            "DestUniqueid: 1791227125.197\n"
            "DialString: 102/sip:102@192.168.37.104:40533;transport=udp\n"
        )

        for raw in (ringing, dial_string_only, swapped_caller_id):
            event = normalize(raw, WHEN)
            self.assertEqual("102", event["call"]["extension"])
            self.assertEqual("101", event["call"]["caller_number"])
            self.assertEqual("inbound", event["call"]["direction"])

    def test_self_call_and_empty_ringing_caller_are_dropped(self):
        self_call = (
            "Event: DialBegin\nCallerIDNum: 1001\nDialString: 1001\n"
            "Uniqueid: 1\nLinkedid: 1\nDestUniqueid: 2\nDestChannel: SIP/1001-a\n"
        )
        empty_caller = (
            "Event: DialBegin\nCallerIDNum: \nDialString: 1001\n"
            "Uniqueid: 1\nLinkedid: 1\nDestUniqueid: 2\nDestChannel: SIP/1001-a\n"
        )
        self.assertIsNone(normalize(self_call, WHEN))
        self.assertIsNone(normalize(empty_caller, WHEN))


if __name__ == "__main__":
    unittest.main()
