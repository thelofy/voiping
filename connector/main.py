"""Run the read-only AMI connector."""

import signal
from pathlib import Path

from ami_client import AmiClient
from config import ConfigError, load_config, load_env_file
from event_handler import normalize
from laravel_client import FatalApiError, LaravelClient
from logger import configure_logging


def main() -> int:
    load_env_file(Path(__file__).resolve().parent / ".env")
    try:
        config = load_config()
    except ConfigError as exc:
        print(exc)
        return 1

    log = configure_logging(
        config.log_level,
        (config.ami_secret, config.laravel_api_token),
    )
    laravel = LaravelClient(
        config.laravel_ingest_url,
        config.laravel_api_token,
        config.http_timeout_seconds,
        log,
    )
    client = AmiClient(
        config.ami_host,
        config.ami_port,
        config.ami_username,
        config.ami_secret,
        log,
    )

    def stop(signum, _frame) -> None:
        log.info("Connector stopping signal=%s", signum)
        client.stop()

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)

    fatal = False

    def on_frame(raw: str) -> None:
        nonlocal fatal
        event = normalize(raw)
        if event is None:
            return
        try:
            laravel.publish(event)
        except FatalApiError:
            fatal = True
            log.error("Stopping because Laravel rejected the API token. Update it and restart.")
            client.stop()

    client.run(on_frame)
    return 1 if fatal else 0


if __name__ == "__main__":
    raise SystemExit(main())
