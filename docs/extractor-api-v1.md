# Extractor API v1

## Scope

The Save It extractor is an internal Docker-network service. Version 1 establishes
request validation, provider recognition, adapter boundaries, and stable response
shapes. PR #5 adds bounded public X metadata extraction. It does not download media,
perform conversion, or execute shell commands.

The Laravel application is the only intended client. The extractor has no published
host port.

## Endpoints

### `GET /health`

Liveness only:

```json
{
  "status": "ok",
  "service": "save-it-extractor",
  "version": "1"
}
```

### `GET /ready`

Returns the same safe service identity with a `ready` status. Provider availability is
not implied.

### `POST /v1/extract`

Request:

```json
{
  "url": "https://x.com/example/status/123",
  "request_id": "optional-client-request-id",
  "options": {
    "metadata_only": true
  }
}
```

Rules:

- `url` is required and limited to 2,048 characters.
- The whole request body is limited to 8,192 bytes.
- Only `http` and `https` schemes are accepted.
- Embedded credentials, custom ports, IP literals, localhost, private addresses, and
  unrecognized exact hostnames are rejected.
- URL fragments are removed; query strings are preserved.
- `request_id` is optional, 1–64 characters, and restricted to letters, numbers,
  `.`, `_`, `:`, and `-`.
- `metadata_only` must be `true`; download and conversion options are not accepted.
- Unknown JSON fields are rejected.

The service does not trust a client-provided provider name. Provider and subtype are
derived from the normalized hostname and path.

For X, only status paths are valid. `x.com`, `www.x.com`, `twitter.com`,
`www.twitter.com`, and `mobile.twitter.com` normalize to
`https://x.com/<username>/status/<numeric-id>`. Share queries, fragments, and
`/photo/N` or `/video/N` display suffixes are removed.

## X success response

A public X status containing directly attached media returns HTTP `200` with a `data`
envelope. `media_type` is `image`, `video`, `animated_gif`, `carousel`, or
`mixed_media`; `status` is `ready`.

```json
{
  "data": {
    "request_id": "request-id",
    "provider": "x",
    "provider_label": "X",
    "provider_variant": null,
    "media_type": "video",
    "source_url": "https://twitter.com/example/status/123?ref=share",
    "normalized_url": "https://x.com/example/status/123",
    "status": "ready",
    "metadata": {
      "post_id": "123",
      "text": "Public post text",
      "author_name": "Example",
      "author_handle": "example",
      "published_at": "2026-07-28T12:00:00Z",
      "thumbnail_url": "https://pbs.twimg.com/media/example.jpg",
      "media_count": 1
    },
    "assets": [],
    "capabilities": ["metadata", "media_assets", "video_variants"]
  }
}
```

Metadata fields are nullable when X does not supply them. Values are truncated to
documented limits and HTML entities are decoded; raw HTML is not returned. Save It
does not invent titles, authors, dates, duration, bitrate, dimensions, codec, or size.

### Assets and variants

Assets retain upstream order and use types `image`, `video`, or `animated_gif`.
Multiple same-type assets produce `carousel`; mixed types produce `mixed_media`.
Each asset contains an ID, one-based order, role, allowlisted HTTPS URL, nullable
thumbnail/MIME/dimensions/duration/alt text, and a variants array.

Video variants contain real upstream MP4 or HLS URLs, MIME type, protocol, nullable
bitrate and dimensions, a dimension-based quality label, and one deterministic
preferred variant. Animated GIF posts retain `animated_gif` semantics even when X
uses MP4 transport. No GIF file is invented.

Quoted-post media is not merged into the submitted post. A valid post without
directly attached media returns `422 no_media`. Asset references can expire and are
not download links; delivery remains PR #9 scope.

## Provider recognition response

Instagram, YouTube, TikTok, Facebook, and LinkedIn remain controlled stubs. A
recognized URL for one of them returns HTTP `501` with
`provider_not_implemented`:

```json
{
  "error": {
    "code": "provider_not_implemented",
    "message": "The provider is recognized, but extraction is not implemented yet.",
    "request_id": "optional-client-request-id",
    "details": {
      "provider": "x",
      "provider_label": "X",
      "provider_variant": null,
      "media_type": "unknown",
      "source_url": "https://x.com/example/status/123",
      "normalized_url": "https://x.com/example/status/123",
      "status": "not_implemented",
      "metadata": null,
      "assets": [],
      "capabilities": []
    }
  }
}
```

Laravel validates this shape and maps it to the existing public preview response. It
does not expose the internal service address or Python error internals.

## Error response

All errors use one machine-readable envelope:

```json
{
  "error": {
    "code": "unsupported_host",
    "message": "The submitted host is not supported.",
    "request_id": "request-id",
    "details": {}
  }
}
```

| HTTP | Code | Meaning |
| --- | --- | --- |
| 400 | `validation_error` | Malformed JSON, missing fields, invalid request ID, or unknown fields |
| 413 | `request_too_large` | Request body exceeds 8,192 bytes |
| 422 | `invalid_url` | URL parsing failed |
| 422 | `url_too_long` | URL exceeds 2,048 characters |
| 422 | `unsupported_scheme` | Scheme is not HTTP or HTTPS |
| 422 | `unsupported_host` | Host is local, an IP literal, unsafe, or not recognized |
| 422 | `embedded_credentials` | URL contains user information |
| 422 | `disallowed_port` | URL contains an explicit port |
| 422 | `invalid_x_post_url` | X URL is not a canonicalizable status URL |
| 422 | `no_media` | Public X post has no directly attached media |
| 422 | `post_unavailable` | Post is unavailable, private, removed, or not public |
| 501 | `provider_not_implemented` | Provider is recognized but its adapter is a stub |
| 502 | `provider_response_changed` | X metadata shape or content type is invalid |
| 502 | `provider_response_too_large` | X metadata exceeds the 3 MiB limit |
| 502 | `disallowed_redirect` | Redirect or DNS result violates the X egress policy |
| 500 | `internal_error` | Unexpected internal service failure |
| 503 | `provider_timeout` | X exceeded the provider timeout |
| 503 | `rate_limited_upstream` | X is rate limiting metadata requests |
| 503 | `provider_blocked` | X denied the public metadata request |
| 503 | `upstream_unavailable` | Provider dependency is temporarily unavailable |

Stack traces, environment values, internal paths, headers, cookies, and secrets are
never included in error bodies.

## Providers

Stable provider identifiers are:

- `x`
- `instagram`
- `youtube`
- `tiktok`
- `facebook`
- `linkedin`

YouTube Shorts uses provider `youtube` with `provider_variant` set to `shorts`.
Aliases such as `twitter.com`, `youtu.be`, and `fb.watch` map to their canonical
provider. Matching is exact; suffix lookalikes are rejected.

## Request IDs

Laravel supplies a generated correlation ID. If no valid ID is supplied, the extractor
generates one. The value is returned in the response body and `X-Request-ID` header.
Request IDs are correlation values, not authentication credentials.

## Timeout and availability

Laravel uses an explicit two-second connect timeout and five-second total timeout by
default, with redirects disabled. Extractor connection failures map to a safe public
`503`; invalid extractor responses map to a safe `502`. The landing page and Laravel
`/health` endpoint do not depend on extractor readiness.

## Logging and privacy

Extractor logs are structured JSON containing timestamp, level, service, event,
request ID, provider, status, and duration. Full submitted URLs, query strings,
request bodies, cookies, and headers are not logged. Uvicorn access logging is
disabled in production.

## X network, privacy, and cache policy

Only the X adapter has outbound access. It constructs a metadata request from the
validated numeric status ID and permits `cdn.syndication.twimg.com` as the metadata
host. Returned assets must use `pbs.twimg.com` or `video.twimg.com`. TLS verification
is mandatory, proxy environment variables are ignored, every redirect target is
revalidated, DNS answers must be globally routable, redirects are capped at three,
the connect timeout is three seconds, the provider deadline is twelve seconds, and
decompressed metadata is capped at 3 MiB. Media asset bodies are never requested.

There is no extraction cache in PR #5. This avoids retaining expiring media URLs and
keeps invalidation explicit. Full URLs, queries, bodies, headers, cookies, and
upstream payloads are not logged.

The metadata host is fixed rather than user-controlled. DNS preflight narrows the
baseline risk; connection pinning, centralized egress enforcement, DNS-rebinding
defense, and cross-provider redirect policy remain PR #10 scope and are not claimed
complete.

## Versioning policy

Breaking changes require a new path such as `/v2/extract`. Compatible optional fields
may be added to v1. Existing keys, provider identifiers, error codes, and their
semantics remain stable throughout v1.

FastAPI interactive documentation and OpenAPI output are disabled in production.
They are available only when `EXTRACTOR_ENV` is `local`, `development`, or `testing`.
