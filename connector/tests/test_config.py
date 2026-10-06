import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from config import ConfigError, load_config


class ConfigTest(unittest.TestCase):
    def test_missing_secret_names_the_setting_and_not_a_value(self):
        with self.assertRaises(ConfigError) as raised:
            load_config(
                {
                    "AMI_HOST": "127.0.0.1",
                    "AMI_USERNAME": "smartpbx",
                    "AMI_SECRET": "",
                    "LARAVEL_INGEST_URL": "https://example.test/api/internal/v1/asterisk/events",
                    "LARAVEL_API_TOKEN": "token-value",
                }
            )
        self.assertIn("AMI_SECRET", str(raised.exception))
        self.assertNotIn("token-value", str(raised.exception))

    def test_defaults_port_and_timeout(self):
        config = load_config(
            {
                "AMI_HOST": "pbx.internal",
                "AMI_USERNAME": "smartpbx",
                "AMI_SECRET": "secret",
                "LARAVEL_INGEST_URL": "https://example.test/api/internal/v1/asterisk/events",
                "LARAVEL_API_TOKEN": "token",
            }
        )
        self.assertEqual(5038, config.ami_port)
        self.assertEqual(3, config.http_timeout_seconds)


if __name__ == "__main__":
    unittest.main()
