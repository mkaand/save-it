import ipaddress
import re
from dataclasses import dataclass
from urllib.parse import SplitResult, parse_qs, quote, unquote, urlsplit, urlunsplit

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
    "m.linkedin.com": Provider.LINKEDIN,
    "lnkd.in": Provider.LINKEDIN,
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
            else (
                _normalize_youtube_url(hostname, parsed)
                if provider is Provider.YOUTUBE
                else (
                    _normalize_linkedin_url(hostname, parsed)
                    if provider is Provider.LINKEDIN
                    else urlunsplit(
                        (parsed.scheme.lower(), hostname, parsed.path or "/", parsed.query, "")
                    )
                )
            )
        )
    )
    variant = None
    if provider is Provider.YOUTUBE:
        variant = "shorts" if "/shorts/" in normalized else "video"
    elif provider is Provider.INSTAGRAM:
        variant = "reel" if normalized.startswith("https://www.instagram.com/reel/") else "post"
    elif provider is Provider.LINKEDIN:
        if hostname == "lnkd.in":
            variant = "short"
        elif ":ugcPost:" in normalized:
            variant = "ugc_post"
        else:
            variant = "activity" if ":activity:" in normalized else "post"

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
YOUTUBE_VIDEO_ID = re.compile(r"^[A-Za-z0-9_-]{11}$")
YOUTUBE_SHORTS_PATH = re.compile(r"^/shorts/([A-Za-z0-9_-]{11})/?$")
YOUTUBE_SHORT_URL_PATH = re.compile(r"^/([A-Za-z0-9_-]{11})/?$")
LINKEDIN_POST_PATH = re.compile(r"^/posts/([^/]{3,600})/?$")
LINKEDIN_URN_PATH = re.compile(r"^/feed/update/urn:li:(activity|ugcPost):([0-9]{6,30})/?$", re.IGNORECASE)
LINKEDIN_URN_IN_SLUG = re.compile(r"(?:^|[-_])(activity|ugcPost)[-_:]([0-9]{6,30})(?:[-_]|$)", re.IGNORECASE)
LINKEDIN_SHORT_PATH = re.compile(r"^/(?:p/)?([A-Za-z0-9_-]{4,128})/?$")
INVALID_PERCENT_ENCODING = re.compile(r"%(?![A-Fa-f0-9]{2})")


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


def _normalize_youtube_url(hostname: str, parsed: SplitResult) -> str:
    if parsed.path == "/playlist":
        raise UrlValidationError(
            "playlist_not_supported",
            "YouTube playlists are not supported. Submit a single video URL.",
        )

    if parsed.path.startswith(("/live/", "/embed/live_stream")):
        raise UrlValidationError(
            "live_not_supported",
            "YouTube live and scheduled live videos are not supported.",
        )

    video_id: str | None = None
    variant = "video"
    if hostname == "youtu.be":
        match = YOUTUBE_SHORT_URL_PATH.fullmatch(parsed.path)
        video_id = match.group(1) if match else None
    elif parsed.path == "/watch":
        parameters = parse_qs(parsed.query, keep_blank_values=True)
        candidates = parameters.get("v", [])
        video_id = candidates[0] if len(candidates) == 1 else None
        if video_id is None and "list" in parameters:
            raise UrlValidationError(
                "playlist_not_supported",
                "YouTube playlists are not supported. Submit a single video URL.",
            )
    else:
        match = YOUTUBE_SHORTS_PATH.fullmatch(parsed.path)
        if match:
            video_id = match.group(1)
            variant = "shorts"

    if video_id is None or YOUTUBE_VIDEO_ID.fullmatch(video_id) is None:
        raise UrlValidationError(
            "invalid_youtube_video_url",
            "Enter a valid YouTube video or Shorts URL.",
        )

    if variant == "shorts":
        return f"https://www.youtube.com/shorts/{video_id}"
    return f"https://www.youtube.com/watch?v={video_id}"


def _normalize_linkedin_url(hostname: str, parsed: SplitResult) -> str:
    if hostname == "lnkd.in":
        match = LINKEDIN_SHORT_PATH.fullmatch(parsed.path)
        if match is None:
            raise UrlValidationError("invalid_linkedin_post_url", "LinkedIn supports public post URLs only.")
        prefix = "p/" if parsed.path.startswith("/p/") else ""
        return f"https://lnkd.in/{prefix}{match.group(1)}"

    urn = LINKEDIN_URN_PATH.fullmatch(parsed.path)
    if urn is not None:
        kind, identifier = urn.groups()
        canonical_kind = "ugcPost" if kind.lower() == "ugcpost" else "activity"
        return f"https://www.linkedin.com/feed/update/urn:li:{canonical_kind}:{identifier}/"

    post = LINKEDIN_POST_PATH.fullmatch(parsed.path)
    if post is not None:
        raw_slug = post.group(1)
        if INVALID_PERCENT_ENCODING.search(raw_slug):
            raise UrlValidationError(
                "invalid_linkedin_post_url",
                "LinkedIn supports public post URLs only.",
            )
        try:
            decoded_slug = unquote(raw_slug, errors="strict")
        except UnicodeDecodeError as exception:
            raise UrlValidationError(
                "invalid_linkedin_post_url",
                "LinkedIn supports public post URLs only.",
            ) from exception
        if (
            not decoded_slug
            or len(decoded_slug) > 300
            or any(ord(character) < 32 for character in decoded_slug)
            or LINKEDIN_URN_IN_SLUG.search(decoded_slug) is None
        ):
            raise UrlValidationError(
                "invalid_linkedin_post_url",
                "LinkedIn supports public post URLs only.",
            )
        canonical_slug = quote(decoded_slug, safe="-._~")
        return f"https://www.linkedin.com/posts/{canonical_slug}/"

    raise UrlValidationError(
        "invalid_linkedin_post_url",
        "LinkedIn supports public post URLs only.",
    )


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
