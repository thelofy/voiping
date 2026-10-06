"""Environment for the Asterisk connector. Secrets stay in the process environment."""

import os
from dataclasses import dataclass
from pathlib import Path


class ConfigError(Exception):
    """A required setting is missing or invalid."""


@dataclass(frozen=True)
class Config:
    ami_host: str
    ami_port: int
    ami_username: str
    ami_secret: str
    laravel_ingest_url: str
    laravel_api_token: str
    log_level: str
    http_timeout_seconds: float


_REQUIRED = (
    "AMI_HOST",
    "AMI_USERNAME",
    "AMI_SECRET",
    "LARAVEL_INGEST_URL",
    "LARAVEL_API_TOKEN",
)


def load_env_file(path: Path) -> None:
    """Fill unset variables from a local env file. Existing variables win."""

    if not path.is_file():
        return

    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip().strip('"').strip("'")
        if key:
            os.environ.setdefault(key, value)


def load_config(environ: dict[str, str] | None = None) -> Config:
    source = os.environ if environ is None else environ
    missing = [name for name in _REQUIRED if not source.get(name, "").strip()]
    if missing:
        raise ConfigError("Missing required settings: " + ", ".join(missing))

    port_text = source.get("AMI_PORT", "5038").strip() or "5038"
    timeout_text = source.get("HTTP_TIMEOUT_SECONDS", "3").strip() or "3"

    try:
        port = int(port_text)
    except ValueError as exc:
        raise ConfigError("AMI_PORT must be an integer.") from exc

    try:
        timeout = float(timeout_text)
    except ValueError as exc:
        raise ConfigError("HTTP_TIMEOUT_SECONDS must be a number.") from exc

    if port < 1 or port > 65535:
        raise ConfigError("AMI_PORT must be between 1 and 65535.")
    if timeout <= 0:
        raise ConfigError("HTTP_TIMEOUT_SECONDS must be greater than zero.")

    level = source.get("LOG_LEVEL", "info").strip().lower() or "info"

    return Config(
        ami_host=source["AMI_HOST"].strip(),
        ami_port=port,
        ami_username=source["AMI_USERNAME"].strip(),
        ami_secret=source["AMI_SECRET"].strip(),
        laravel_ingest_url=source["LARAVEL_INGEST_URL"].strip(),
        laravel_api_token=source["LARAVEL_API_TOKEN"].strip(),
        log_level=level,
        http_timeout_seconds=timeout,
    )
