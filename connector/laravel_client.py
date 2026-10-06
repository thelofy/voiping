"""POST canonical events to Laravel. Retries keep the same event id."""

import json
import logging
import urllib.error
import urllib.request
import uuid
from collections.abc import Callable

_RETRY_STATUSES = {429, 500, 502, 503, 504}
_RETRY_DELAYS = (0.5, 1.0, 2.0)


class FatalApiError(Exception):
    """Laravel rejected the token. The process must stop until it is restarted."""

    def __init__(self, status: int) -> None:
        super().__init__(f"Laravel ingest returned {status}")
        self.status = status


class LaravelClient:
    def __init__(
        self,
        url: str,
        token: str,
        timeout: float,
        log: logging.Logger | None = None,
        sleep: Callable[[float], None] | None = None,
        urlopen: Callable | None = None,
    ) -> None:
        self._url = url
        self._token = token
        self._timeout = timeout
        self._log = log or logging.getLogger("smartpbx.connector")
        self._sleep = sleep or __import__("time").sleep
        self._urlopen = urlopen or urllib.request.urlopen

    def publish(self, event: dict) -> str:
        body = dict(event)
        body["event_id"] = str(body.get("event_id") or uuid.uuid4())
        attempt = 0

        while True:
            status = self._post(body)
            if status in (200, 202):
                self._log.info(
                    "Ingest accepted event_id=%s event_type=%s linkedid=%s extension=%s http_status=%s",
                    body["event_id"],
                    body.get("event_type"),
                    _linkedid(body),
                    _extension(body),
                    status,
                )
                return body["event_id"]

            if status in (401, 403):
                self._log.error(
                    "Laravel rejected the connector token event_id=%s http_status=%s",
                    body["event_id"],
                    status,
                )
                raise FatalApiError(status)

            if status in _RETRY_STATUSES or status is None:
                if attempt >= len(_RETRY_DELAYS):
                    self._log.error(
                        "Dropped event after retries event_id=%s event_type=%s linkedid=%s extension=%s http_status=%s",
                        body["event_id"],
                        body.get("event_type"),
                        _linkedid(body),
                        _extension(body),
                        status if status is not None else "connection_error",
                    )
                    return body["event_id"]
                self._log.warning(
                    "Retrying ingest event_id=%s http_status=%s",
                    body["event_id"],
                    status if status is not None else "connection_error",
                )
                self._sleep(_RETRY_DELAYS[attempt])
                attempt += 1
                continue

            self._log.warning(
                "Ingest rejected event_id=%s event_type=%s linkedid=%s extension=%s http_status=%s",
                body["event_id"],
                body.get("event_type"),
                _linkedid(body),
                _extension(body),
                status,
            )
            return body["event_id"]

    def _post(self, body: dict) -> int | None:
        request = urllib.request.Request(
            self._url,
            data=json.dumps(body).encode("utf-8"),
            headers={
                "Authorization": f"Bearer {self._token}",
                "Accept": "application/json",
                "Content-Type": "application/json",
            },
            method="POST",
        )
        try:
            with self._urlopen(request, timeout=self._timeout) as response:
                return int(response.status)
        except urllib.error.HTTPError as exc:
            status = int(exc.code)
            exc.close()
            return status
        except (urllib.error.URLError, TimeoutError, OSError):
            return None


def _linkedid(body: dict) -> str:
    asterisk = body.get("asterisk")
    if isinstance(asterisk, dict):
        return str(asterisk.get("linkedid", ""))
    return ""


def _extension(body: dict) -> str:
    call = body.get("call")
    if isinstance(call, dict):
        return str(call.get("extension", ""))
    return ""
