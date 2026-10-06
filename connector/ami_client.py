"""Read-only Asterisk AMI session. Login and Ping are the only actions sent."""

import logging
import socket
from collections.abc import Callable

_BACKOFF = (1, 2, 4, 8, 30)
_PING = b"Action: Ping\r\n\r\n"


class AmiClient:
    def __init__(
        self,
        host: str,
        port: int,
        username: str,
        secret: str,
        log: logging.Logger | None = None,
        socket_factory: Callable[..., socket.socket] | None = None,
        sleep: Callable[[float], None] | None = None,
    ) -> None:
        self._host = host
        self._port = port
        self._username = username
        self._secret = secret
        self._log = log or logging.getLogger("smartpbx.connector")
        self._socket_factory = socket_factory or socket.create_connection
        self._sleep = sleep or __import__("time").sleep
        self._stop = False
        self._delay_index = 0

    def stop(self) -> None:
        self._stop = True

    def run(self, on_frame: Callable[[str], None]) -> None:
        while not self._stop:
            try:
                self._session(on_frame)
                self._delay_index = 0
            except Exception as exc:
                if self._stop:
                    return
                self._log.warning(
                    "AMI session ended host=%s port=%s error=%s",
                    self._host,
                    self._port,
                    type(exc).__name__,
                )
                delay = _BACKOFF[min(self._delay_index, len(_BACKOFF) - 1)]
                if self._delay_index < len(_BACKOFF) - 1:
                    self._delay_index += 1
                self._sleep(delay)

    def _session(self, on_frame: Callable[[str], None]) -> None:
        sock = self._socket_factory((self._host, self._port), timeout=10)
        try:
            sock.settimeout(10)
            reader = _FrameReader(sock)
            banner = reader.read_line()
            if "Asterisk Call Manager" not in banner:
                raise ConnectionError("unexpected AMI banner")

            sock.sendall(_login(self._username, self._secret))
            login = reader.read_frame()
            fields = _fields(login)
            if fields.get("Response") != "Success":
                message = fields.get("Message", "AMI login failed")
                self._log.error("AMI login failed host=%s port=%s message=%s", self._host, self._port, message)
                raise ConnectionError("AMI login failed")

            self._delay_index = 0
            self._log.info(
                "AMI login succeeded host=%s port=%s username=%s",
                self._host,
                self._port,
                self._username,
            )
            self._read_loop(sock, reader, on_frame)
        finally:
            try:
                sock.close()
            except OSError:
                pass

    def _read_loop(self, sock: socket.socket, reader: "_FrameReader", on_frame: Callable[[str], None]) -> None:
        ping_enabled = True
        idle_timeout = 30.0
        while not self._stop:
            sock.settimeout(idle_timeout)
            try:
                frame = reader.read_frame()
            except TimeoutError:
                if not ping_enabled:
                    raise ConnectionError("AMI read timeout")
                outcome = self._ping(sock, reader, on_frame)
                if outcome == "failed":
                    raise ConnectionError("AMI ping failed")
                if outcome == "disabled":
                    ping_enabled = False
                    idle_timeout = 90.0
                continue
            except OSError as exc:
                raise ConnectionError("AMI socket error") from exc

            on_frame(frame)

    def _ping(self, sock: socket.socket, reader: "_FrameReader", on_frame: Callable[[str], None]) -> str:
        try:
            sock.sendall(_PING)
            sock.settimeout(10)
            frame = reader.read_frame()
        except (TimeoutError, OSError):
            return "failed"

        fields = _fields(frame)
        if _permission_denied(fields):
            self._log.info("AMI ping is not permitted. Using the read timeout.")
            return "disabled"
        if _is_pong(fields):
            return "ok"
        on_frame(frame)
        return "ok"


class _FrameReader:
    def __init__(self, sock: socket.socket) -> None:
        self._sock = sock
        self._buffer = b""

    def read_line(self) -> str:
        while b"\n" not in self._buffer:
            self._buffer += self._recv()
        line, _, rest = self._buffer.partition(b"\n")
        self._buffer = rest
        return line.decode("utf-8", errors="replace").strip()

    def read_frame(self) -> str:
        while True:
            for separator in (b"\r\n\r\n", b"\n\n"):
                index = self._buffer.find(separator)
                if index != -1:
                    raw = self._buffer[:index]
                    self._buffer = self._buffer[index + len(separator) :]
                    return raw.decode("utf-8", errors="replace")
            self._buffer += self._recv()

    def _recv(self) -> bytes:
        chunk = self._sock.recv(4096)
        if not chunk:
            raise ConnectionError("AMI connection closed")
        return chunk


def _login(username: str, secret: str) -> bytes:
    return (
        "Action: Login\r\n"
        f"Username: {username}\r\n"
        f"Secret: {secret}\r\n"
        "Events: on\r\n"
        "\r\n"
    ).encode("utf-8")


def _fields(raw: str) -> dict[str, str]:
    fields: dict[str, str] = {}
    for line in raw.splitlines():
        if ":" not in line:
            continue
        key, value = line.split(":", 1)
        fields.setdefault(key.strip(), value.strip())
    return fields


def _is_pong(fields: dict[str, str]) -> bool:
    return fields.get("Ping") == "Pong" or fields.get("Event") == "Pong"


def _permission_denied(fields: dict[str, str]) -> bool:
    if fields.get("Response") != "Error":
        return False
    message = fields.get("Message", "").lower()
    return "permission" in message or "write" in message
