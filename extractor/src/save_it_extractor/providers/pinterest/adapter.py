from dataclasses import dataclass, field

from save_it_extractor.domain.models import ExtractResult, Provider, ProviderContext
from save_it_extractor.domain.urls import UrlValidationError, classify_url
from save_it_extractor.providers.errors import ProviderError
from save_it_extractor.providers.pinterest.network import PinterestMetadataClient
from save_it_extractor.providers.pinterest.parser import parse_pinterest_pin


@dataclass(frozen=True, slots=True)
class PinterestProviderAdapter:
    provider: Provider = Provider.PINTEREST
    client: PinterestMetadataClient = field(default_factory=PinterestMetadataClient)

    async def extract(self, context: ProviderContext, request_id: str) -> ExtractResult:
        resolved = context
        if context.variant == "short":
            try:
                short_target = await self.client.resolve_short_link(context.normalized_url)
                resolved = classify_url(short_target)
            except UrlValidationError as exc:
                raise ProviderError(
                    "unsupported_url",
                    "Pinterest short links must resolve to a public Pin.",
                    422,
                    {"provider": "pinterest"},
                ) from exc

        if resolved.provider is not Provider.PINTEREST or resolved.variant != "pin":
            raise ProviderError(
                "unsupported_url",
                "Pinterest supports public Pin URLs only.",
                422,
                {"provider": "pinterest"},
            )

        pin_id = resolved.normalized_url.rstrip("/").split("/")[-1]
        payload = await self.client.fetch_pin(pin_id)
        metadata, assets, media_type = parse_pinterest_pin(payload, pin_id)
        capabilities = ["metadata", "media_assets"]
        if assets[0]["type"] == "video":
            capabilities.append("video_variants")

        return ExtractResult(
            request_id=request_id,
            provider=Provider.PINTEREST,
            provider_label="Pinterest",
            normalized_url=resolved.normalized_url,
            variant="pin",
            status="ready",
            media_type=media_type,
            metadata=metadata,
            assets=assets,
            capabilities=capabilities,
            maturity="available",
            warnings=["Public availability depends on Pinterest's anonymous metadata response."],
        )
