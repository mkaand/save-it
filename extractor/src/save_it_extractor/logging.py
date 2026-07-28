import json
import logging
from datetime import UTC, datetime
from typing import Any

from save_it_extractor.config import settings

logger = logging.getLogger(settings.app_name)
logger.setLevel(settings.log_level.upper())
logger.handlers.clear()
handler = logging.StreamHandler()
handler.setFormatter(logging.Formatter("%(message)s"))
logger.addHandler(handler)
logger.propagate = False


def log_event(event: str, level: int = logging.INFO, **fields: Any) -> None:
    record = {
        "timestamp": datetime.now(UTC).isoformat(),
        "level": logging.getLevelName(level).lower(),
        "service": settings.app_name,
        "event": event,
        **fields,
    }
    logger.log(level, json.dumps(record, separators=(",", ":"), default=str))
