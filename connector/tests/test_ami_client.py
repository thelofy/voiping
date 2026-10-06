import logging
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from ami_client import AmiClient
from logger import configure_logging

SECRET = "ami-secret-do-not-log"


class ScriptedSocket:
    def __init__(self, steps):
        self.steps = list(steps)
        self.sent = bytearray()
        self.closed = False

    def settimeout(self, value):
        self.timeout = value

    def sendall(self, data):
        self.sent.extend(data)

    def recv(self, size):
        if not self.steps:
            return b""
        step = self.steps.pop(0)
        if step is TimeoutError:
            raise TimeoutError
        return step

    def close(self):
        self.closed = True


def frame(text):
    body = text.strip().replace("\n", "\r\n")
    return (body + "\r\n\r\n").encode("utf-8")


class AmiClientTest(unittest.TestCase):
    def setUp(self):
        self.log = configure_logging("info", (SECRET, "laravel-token"))
        self.records = []
        self.log.handlers.clear()
        handler = logging.Handler()
        handler.emit = self.records.append
        from logger import RedactFilter

        handler.addFilter(RedactFilter((SECRET, "laravel-token")))
        self.log.addHandler(handler)
        self.log.setLevel(logging.INFO)

    def test_login_reads_events_and_does_not_log_the_secret(self):
        sock = ScriptedSocket(
            [
                b"Asterisk Call Manager/2.10.0\r\n",
                frame("Response: Success\nMessage: Authentication accepted"),
                frame(
                    "Event: DialBegin\nCallerIDNum: 09121234567\nDialString: 1001\n"
                    "Uniqueid: 1\nLinkedid: 1\nDestUniqueid: 2\nDestChannel: SIP/1001-a"
                ),
            ]
        )
        seen = []
        client = AmiClient("127.0.0.1", 5038, "smartpbx", SECRET, log=self.log, socket_factory=lambda *args, **kwargs: sock)

        with self.assertRaises(ConnectionError):
            client._session(seen.append)

        sent = sock.sent.decode("utf-8")
        self.assertIn("Action: Login", sent)
        self.assertIn("Username: smartpbx", sent)
        self.assertIn("Events: on", sent)
        self.assertNotIn("Originate", sent)
        self.assertEqual(1, len(seen))
        self.assertIn("Event: DialBegin", seen[0])
        logged = " ".join(record.getMessage() for record in self.records)
        self.assertNotIn(SECRET, logged)
        self.assertTrue(sock.closed)

    def test_login_failure_redacts_the_secret(self):
        sock = ScriptedSocket(
            [
                b"Asterisk Call Manager/2.10.0\r\n",
                frame(f"Response: Error\nMessage: Authentication failed secret={SECRET}"),
            ]
        )
        client = AmiClient("127.0.0.1", 5038, "smartpbx", SECRET, log=self.log, socket_factory=lambda *args, **kwargs: sock)

        with self.assertRaises(ConnectionError):
            client._session(lambda raw: None)

        logged = " ".join(record.getMessage() for record in self.records)
        self.assertNotIn(SECRET, logged)
        self.assertIn("AMI login failed", logged)

    def test_missed_traffic_sends_ping_and_accepts_pong(self):
        sock = ScriptedSocket(
            [
                b"Asterisk Call Manager/2.10.0\r\n",
                frame("Response: Success\nMessage: Authentication accepted"),
                TimeoutError,
                frame("Response: Success\nPing: Pong"),
            ]
        )
        client = AmiClient("127.0.0.1", 5038, "smartpbx", SECRET, log=self.log, socket_factory=lambda *args, **kwargs: sock)

        with self.assertRaises(ConnectionError):
            client._session(lambda raw: None)

        self.assertIn(b"Action: Ping", sock.sent)

    def test_permission_denied_disables_further_pings(self):
        sock = ScriptedSocket(
            [
                b"Asterisk Call Manager/2.10.0\r\n",
                frame("Response: Success\nMessage: Authentication accepted"),
                TimeoutError,
                frame("Response: Error\nMessage: Permission denied"),
                frame("Event: FullyBooted\nPrivilege: system,all"),
            ]
        )
        seen = []
        client = AmiClient("127.0.0.1", 5038, "smartpbx", SECRET, log=self.log, socket_factory=lambda *args, **kwargs: sock)

        with self.assertRaises(ConnectionError):
            client._session(seen.append)

        self.assertEqual(1, sock.sent.decode("utf-8").count("Action: Ping"))
        self.assertEqual(1, len(seen))

    def test_reconnect_uses_backoff_after_a_failed_login(self):
        sleeps = []

        def factory(*args, **kwargs):
            return ScriptedSocket(
                [
                    b"Asterisk Call Manager/2.10.0\r\n",
                    frame("Response: Error\nMessage: Authentication failed"),
                ]
            )

        client = AmiClient(
            "pbx.internal",
            5038,
            "smartpbx",
            SECRET,
            log=self.log,
            socket_factory=factory,
            sleep=sleeps.append,
        )

        def stop_after_two(delay):
            sleeps.append(delay)
            if len(sleeps) == 2:
                client.stop()

        client._sleep = stop_after_two
        client.run(lambda raw: None)
        self.assertEqual([1, 2], sleeps)


if __name__ == "__main__":
    unittest.main()
