import io
import json
import logging
import sys
import unittest
import urllib.error
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from laravel_client import FatalApiError, LaravelClient
from logger import RedactFilter

TOKEN = "laravel-token-do-not-log"


def event(event_id="8f0c1d2e-7b3a-4e1f-9c2d-6a5b4c3d2e1f"):
    return {
        "event_id": event_id,
        "event_type": "call.ringing",
        "occurred_at": "2026-10-05T08:10:00Z",
        "asterisk": {
            "uniqueid": "1728000000.2",
            "linkedid": "1728000000.1",
            "channel": "SIP/1001-00000002",
        },
        "call": {
            "caller_number": "09121234567",
            "caller_name": "",
            "extension": "1001",
        },
    }


class Response:
    def __init__(self, status):
        self.status = status

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, traceback):
        return False


class LaravelClientTest(unittest.TestCase):
    def setUp(self):
        self.requests = []
        self.sleeps = []
        self.log = logging.getLogger("smartpbx.test.laravel")
        self.log.handlers.clear()
        self.log.setLevel(logging.DEBUG)
        self.log.propagate = False
        self.handler = logging.Handler()
        self.records = []
        self.handler.emit = self.records.append
        self.handler.addFilter(RedactFilter((TOKEN,)))
        self.log.addHandler(self.handler)

    def client(self, urlopen):
        return LaravelClient(
            "https://smartpbx.example.com/api/internal/v1/asterisk/events",
            TOKEN,
            3,
            log=self.log,
            sleep=self.sleeps.append,
            urlopen=urlopen,
        )

    def test_publish_sends_a_bearer_token_and_keeps_the_event_id(self):
        timeouts = []

        def urlopen(request, timeout):
            self.requests.append(request)
            timeouts.append(timeout)
            if len(self.requests) == 1:
                raise urllib.error.HTTPError(
                    request.full_url, 503, "unavailable", hdrs=None, fp=io.BytesIO(b"")
                )
            return Response(200)

        sent_id = self.client(urlopen).publish(event())

        self.assertEqual(event()["event_id"], sent_id)
        self.assertEqual(2, len(self.requests))
        self.assertEqual([0.5], self.sleeps)
        self.assertEqual([3, 3], timeouts)
        first = json.loads(self.requests[0].data.decode("utf-8"))
        second = json.loads(self.requests[1].data.decode("utf-8"))
        self.assertEqual(first["event_id"], second["event_id"])
        self.assertEqual(f"Bearer {TOKEN}", self.requests[0].get_header("Authorization"))
        self.assertEqual("application/json", self.requests[0].get_header("Content-type"))
        logged = " ".join(record.getMessage() for record in self.records)
        self.assertNotIn(TOKEN, logged)

    def test_validation_failure_is_not_retried(self):
        def urlopen(request, timeout):
            self.requests.append(request)
            raise urllib.error.HTTPError(
                request.full_url, 422, "invalid", hdrs=None, fp=io.BytesIO(b"{}")
            )

        self.client(urlopen).publish(event())
        self.assertEqual(1, len(self.requests))
        self.assertEqual([], self.sleeps)

    def test_auth_failure_stops_without_retry(self):
        def urlopen(request, timeout):
            self.requests.append(request)
            raise urllib.error.HTTPError(
                request.full_url, 401, "unauthorized", hdrs=None, fp=io.BytesIO(b"")
            )

        with self.assertRaises(FatalApiError):
            self.client(urlopen).publish(event())
        self.assertEqual(1, len(self.requests))
        self.assertEqual([], self.sleeps)


if __name__ == "__main__":
    unittest.main()
