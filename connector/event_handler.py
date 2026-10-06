"""Turn AMI frames into the canonical Laravel event."""

import logging
import re
import uuid
from datetime import datetime, timezone

_LOG = logging.getLogger("smartpbx.connector")
_DIAGNOSTIC_FIELDS = (
    "CallerIDNum",
    "CallerIDName",
    "Uniqueid",
    "Linkedid",
    "Channel",
    "DestChannel",
    "DestUniqueid",
    "DialString",
    "DialStatus",
    "Cause",
    "Cause-txt",
)

_DIAL_EVENTS = {"DialBegin", "DialEnd"}
_CHANNEL = re.compile(r"^(?:SIP|PJSIP)/(\d{2,6})(?:-|$)", re.IGNORECASE)
_LOCAL = re.compile(r"^Local/(\d{2,6})(?:@|-|$)", re.IGNORECASE)
_EXTENSION = re.compile(r"^\d{2,6}$")
_DIAL_PREFIX = re.compile(r"^(\d{2,6})(?:/|$)")


def parse_frame(raw: str) -> dict[str, str]:
    fields: dict[str, str] = {}
    for line in raw.splitlines():
        if ":" not in line:
            continue
        key, value = line.split(":", 1)
        key = key.strip()
        if key and key not in fields:
            fields[key] = value.strip()
    return fields


def normalize(raw: str, occurred_at: datetime | None = None) -> dict | None:
    """Return one canonical event, or None when the frame should be dropped."""

    frame = parse_frame(raw)
    event_name = frame.get("Event", "")
    if event_name not in {"DialBegin", "DialEnd", "Hangup"}:
        return None

    extension = _extension(frame)
    caller = _caller(frame, extension)
    if extension is None:
        _diagnostic(frame, "missing extension")
        return None
    # Hangup CallerIDNum is the channel's own extension, so this is not a self-call.
    if event_name != "Hangup" and extension == caller:
        _diagnostic(frame, "self-call filter")
        return None
    if event_name == "DialBegin" and caller == "":
        _diagnostic(frame, "missing caller")
        return None

    if event_name == "DialBegin":
        event_type = "call.ringing"
        dial_status = frame.get("DialStatus") or None
    elif event_name == "DialEnd":
        dial_status = frame.get("DialStatus") or None
        event_type = "call.answered" if (dial_status or "").upper() == "ANSWER" else "call.ended"
    else:
        event_type = "call.ended"
        dial_status = frame.get("Cause") or frame.get("DialStatus") or None

    linkedid = frame.get("Linkedid") or frame.get("Uniqueid") or ""
    if event_name in _DIAL_EVENTS:
        uniqueid = frame.get("DestUniqueid") or frame.get("Uniqueid") or ""
        channel = frame.get("DestChannel") or frame.get("Channel") or ""
    else:
        uniqueid = frame.get("Uniqueid") or ""
        channel = frame.get("Channel") or ""

    if not linkedid or not uniqueid or not channel:
        if not linkedid:
            reason = "missing linkedid"
        elif not uniqueid:
            reason = "missing uniqueid"
        else:
            reason = "missing channel"
        _diagnostic(frame, reason)
        return None

    moment = occurred_at or datetime.now(timezone.utc)
    if moment.tzinfo is None:
        moment = moment.replace(tzinfo=timezone.utc)

    event = {
        "event_id": str(uuid.uuid4()),
        "event_type": event_type,
        "occurred_at": moment.astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "asterisk": {
            "uniqueid": uniqueid[:64],
            "linkedid": linkedid[:64],
            "channel": channel[:120],
        },
        "call": {
            "caller_number": caller[:40],
            "caller_name": frame.get("CallerIDName", "").strip()[:120],
            "extension": extension[:20],
            "direction": _direction(frame, extension, caller),
        },
    }
    if dial_status:
        event["call"]["dial_status"] = dial_status[:32]
    _diagnostic(frame, None)
    return event


def _diagnostic(frame: dict[str, str], drop_reason: str | None) -> None:
    """Log one dial or hangup frame. Values are the named AMI fields only."""

    event_name = frame.get("Event", "")
    lines = ["===== AMI DIAGNOSTIC EVENT =====", f"event={event_name}"]
    for key in _DIAGNOSTIC_FIELDS:
        lines.append(f"{key}={frame.get(key, '')}")
    lines.append("result=dropped" if drop_reason else "result=accepted")
    if drop_reason:
        lines.append(f"drop_reason={drop_reason}")
    _LOG.info("\n".join(lines))
    if drop_reason:
        _LOG.warning("DROPPED AMI EVENT reason=%s event=%s", drop_reason, event_name)


def _extension(frame: dict[str, str]) -> str | None:
    """Called party on dial events; the channel's own extension on hangup."""

    dialed = _dialed_extension(frame)
    if frame.get("Event") in _DIAL_EVENTS and dialed is not None:
        return dialed

    for key in ("DestChannel", "Channel"):
        parsed = _channel_extension(frame.get(key, ""))
        if parsed is not None:
            return parsed
    return dialed


def _caller(frame: dict[str, str], extension: str | None) -> str:
    """CallerIDNum, unless Issabel has replaced it with the number being dialed."""

    caller = frame.get("CallerIDNum", "").strip()
    source = _channel_extension(frame.get("Channel", ""))
    if extension and caller == extension and source and source != extension:
        return source
    return caller


def _direction(frame: dict[str, str], extension: str | None, caller: str) -> str:
    """Inbound means this extension was dialed. Outbound means it placed the call."""

    source = _channel_extension(frame.get("Channel", ""))
    dialed = _dialed_extension(frame)
    if dialed and extension == dialed and caller != dialed:
        return "inbound"
    if source and extension == source and caller != source:
        return "outbound"
    return "inbound"


def _dialed_extension(frame: dict[str, str]) -> str | None:
    dial_string = frame.get("DialString", "").strip()
    if _EXTENSION.fullmatch(dial_string):
        return dial_string

    prefix = _DIAL_PREFIX.match(dial_string)
    if prefix:
        return prefix.group(1)

    return _channel_extension(frame.get("DestChannel", ""))


def _channel_extension(value: str) -> str | None:
    match = _CHANNEL.match(value.strip()) or _LOCAL.match(value.strip())
    return match.group(1) if match else None
