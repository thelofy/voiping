import logging
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from logger import configure_logging, redact


class LoggerTest(unittest.TestCase):
    def test_redact_removes_bearer_tokens_and_known_secrets(self):
        secret = "super-ami-secret"
        token = "super-api-token"
        text = redact(
            f"Authorization: Bearer {token} Secret: {secret}",
            (secret, token),
        )
        self.assertNotIn(secret, text)
        self.assertNotIn(token, text)

    def test_configured_logger_hides_a_login_secret(self):
        secret = "login-secret-value"
        logger = configure_logging("info", (secret,))
        records = []
        logger.handlers.clear()
        handler = logging.Handler()
        handler.emit = records.append
        from logger import RedactFilter

        handler.addFilter(RedactFilter((secret,)))
        logger.addHandler(handler)

        logger.error("AMI login failed message=Authentication failed secret=%s", secret)
        self.assertNotIn(secret, records[0].getMessage())


if __name__ == "__main__":
    unittest.main()
