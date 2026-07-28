import ipaddress
import re
from dataclasses import dataclass
from urllib.parse import SplitResult, urlsplit, urlunsplit

from save_it_extractor.domain.models import PROVIDER_LABELS, Provider, ProviderContext


@dataclass(frozen=True, slots=True)
class UrlValidationError(Exception):
    code: str
    message: str


HOST_PROVIDERS: dict[str, Provider] = {
    "x.com": Provider.X,
    "www.x.com": Provider.X,
    "twitter.com": Provider.X,
    "www.twitter.com": Provider.X,
    "mobile.twitter.com": Provider.X,
    "instagram.com": Provider.INSTAGRAM,
    "www.instagram.com": Provider.INSTAGRAM,
    "youtube.com": Provider.YOUTUBE,
    "www.youtube.com": Provider.YOUTUBE,
    "m.youtube.com": Provider.YOUTUBE,
    "music.youtube.com": Provider.YOUTUBE,
    "youtu.be": Provider.YOUTUBE,
    "tiktok.com": Provider.TIKTOK,
    "www.tiktok.com": Provider.TIKTOK,
    "vm.tiktok.com": Provider.TIKTOK,
    "vt.tiktok.com": Provider.TIKTOK,
    "facebook.com": Provider.FACEBOOK,
    "www.facebook.com": Provider.FACEBOOK,
    "m.facebook.com": Provider.FACEBOOK,
    "fb.watch": Provider.FACEBOOK,
    "linkedin.com": Provider.LINKEDIN,
    "www.linkedin.com": Provider.LINKEDIN,
}


def classify_url(raw_url: str, max_length: int = 2048) -> ProviderContext:
    if len(raw_url) > max_length:
        raise UrlValidationError("url_too_long", "The submitted URL is too long.")

    try:
        parsed = urlsplit(raw_url.strip())
    except ValueError as exception:
        raise UrlValidationError("invalid_url", "The submitted URL is invalid.") from exception

    if parsed.scheme.lower() not in {"http", "https"}:
        raise UrlValidationError("unsupported_scheme", "Only HTTP and HTTPS URLs are accepted.")

    if parsed.username is not None or parsed.password is not None:
        raise UrlValidationError(
            "embedded_credentials", "URLs containing credentials are not accepted."
        )

    try:
        port = parsed.port
    except ValueError as exception:
        raise UrlValidationError("disallowed_port", "Custom ports are not accepted.") from exception

    if port is not None:
        raise UrlValidationError("disallowed_port", "Custom ports are not accepted.")

    hostname = _normalize_hostname(parsed)
    _reject_local_or_ip_literal(hostname)

    provider = HOST_PROVIDERS.get(hostname)
    if provider is None:
        raise UrlValidationError("unsupported_host", "The submitted host is not supported.")

    normalized = (
        _normalize_x_url(hostname, parsed)
        if provider is Provider.X
        else (
            _normalize_instagram_url(parsed)
            if provider is Provider.INSTAGRAM
            else urlunsplit((parsed.scheme.lower(), hostname, parsed.path or "/", parsed.query, ""))
        )
    )
    variant = None
    if provider is Provider.YOUTUBE and parsed.path.startswith("/shorts/"):
        variant = "shorts"
    elif provider is Provider.INSTAGRAM:
        variant = "reel" if normalized.startswith("https://www.instagram.com/reel/") else "post"

    return ProviderContext(
        provider=provider,
        provider_label=PROVIDER_LABELS[provider],
        normalized_url=normalized,
        variant=variant,
        source_url=raw_url.strip(),
    )


X_STATUS_PATH = re.compile(
    r"^/([A-Za-z0-9_]{1,15})/status/([0-9]{1,20})(?:/(?:photo|video)/[1-9][0-9]*)?/?$"
)
INSTAGRAM_MEDIA_PATH = re.compile(r"^/(p|reel)/([A-Za-z0-9_-]{5,64})/?$")


def _normalize_x_url(hostname: str, parsed: SplitResult) -> str:
    match = X_STATUS_PATH.fullmatch(parsed.path)
    if match is None:
        raise UrlValidationError(
            "invalid_x_post_url",
            "The submitted URL is not a valid X post URL.",
        )

    username, status_id = match.groups()
    return f"https://x.com/{username}/status/{status_id}"


def _normalize_instagram_url(parsed: SplitResult) -> str:
    match = INSTAGRAM_MEDIA_PATH.fullmatch(parsed.path)
    if match is None:
        raise UrlValidationError(
            "invalid_instagram_media_url",
            "The submitted URL is not a valid Instagram post or reel URL.",
        )

    kind, shortcode = match.groups()
    return f"https://www.instagram.com/{kind}/{shortcode}/"


def _normalize_hostname(parsed: SplitResult) -> str:
    hostname = parsed.hostname
    if hostname is None:
        raise UrlValidationError("invalid_url", "The submitted URL is invalid.")

    hostname = hostname.rstrip(".").lower()
    if not hostname or any(ord(character) > 127 for character in hostname):
        raise UrlValidationError("invalid_url", "The submitted URL is invalid.")

    return hostname


def _reject_local_or_ip_literal(hostname: str) -> None:
    if hostname == "localhost" or hostname.endswith(".localhost"):
        raise UrlValidationError("unsupported_host", "Local and private hosts are not accepted.")

    try:
        address = ipaddress.ip_address(hostname)
    except ValueError:
        return

    if (
        address.is_loopback
        or address.is_private
        or address.is_link_local
        or address.is_reserved
        or address.is_unspecified
        or address.is_multicast
    ):
        raise UrlValidationError("unsupported_host", "Local and private hosts are not accepted.")

    raise UrlValidationError("unsupported_host", "IP literal URLs are not accepted.")
