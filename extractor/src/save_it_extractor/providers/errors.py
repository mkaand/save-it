from dataclasses import dataclass, field
from typing import Any


@dataclass(slots=True)
class ProviderError(Exception):
    code: str
    message: str
    status_code: int
    details: dict[str, Any] = field(default_factory=dict)
