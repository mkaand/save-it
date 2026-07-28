from dataclasses import dataclass, field
from enum import StrEnum
from typing import Any


class Provider(StrEnum):
    X = "x"
    INSTAGRAM = "instagram"
    YOUTUBE = "youtube"
    TIKTOK = "tiktok"
    FACEBOOK = "facebook"
    LINKEDIN = "linkedin"


PROVIDER_LABELS: dict[Provider, str] = {
    Provider.X: "X",
    Provider.INSTAGRAM: "Instagram",
    Provider.YOUTUBE: "YouTube",
    Provider.TIKTOK: "TikTok",
    Provider.FACEBOOK: "Facebook",
    Provider.LINKEDIN: "LinkedIn",
}


@dataclass(frozen=True, slots=True)
class ProviderContext:
    provider: Provider
    provider_label: str
    normalized_url: str
    variant: str | None = None


@dataclass(frozen=True, slots=True)
class ExtractResult:
    request_id: str
    provider: Provider
    provider_label: str
    normalized_url: str
    variant: str | None
    status: str = "not_implemented"
    media_type: str = "unknown"
    metadata: dict[str, Any] | None = None
    assets: list[dict[str, Any]] = field(default_factory=list)
    capabilities: list[str] = field(default_factory=list)
