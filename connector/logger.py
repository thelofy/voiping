"""Logging that cannot print AMI secrets or the Laravel token."""

import logging
import re

_BEARER = re.compile(r"Bearer\s+\S+", re.IGNORECASE)
_ASSIGNMENT = re.compile(
    r"(?i)\b(secret|password|token|authorization)\b\s*[:=]\s*\S+"
)


def redact(text: str, secrets: tuple[str, ...] = ()) -> str:
    redacted = text
    for secret in secrets:
        if secret:
            redacted = redacted.replace(secret, "[redacted]")
    redacted = _BEARER.sub("Bearer [redacted]", redacted)
    redacted = _ASSIGNMENT.sub(r"\1=[redacted]", redacted)
    return redacted


class RedactFilter(logging.Filter):
    def __init__(self, secrets: tuple[str, ...]) -> None:
        super().__init__()
        self._secrets = tuple(secret for secret in secrets if secret)

    def filter(self, record: logging.LogRecord) -> bool:
        if record.args:
            try:
                message = str(record.msg) % record.args
            except (TypeError, ValueError):
                message = str(record.msg)
            record.args = ()
        else:
            message = str(record.msg)
        record.msg = redact(message, self._secrets)
        return True


def configure_logging(level: str, secrets: tuple[str, ...]) -> logging.Logger:
    logger = logging.getLogger("smartpbx.connector")
    logger.handlers.clear()
    logger.setLevel(_level(level))
    logger.propagate = False

    handler = logging.StreamHandler()
    handler.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(message)s"))
    handler.addFilter(RedactFilter(secrets))
    logger.addHandler(handler)
    return logger


def _level(name: str) -> int:
    value = logging.getLevelName(name.upper())
    if isinstance(value, int):
        return value
    return logging.INFO
