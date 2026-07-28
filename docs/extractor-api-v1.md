# Extractor API v1

## Scope

The Save It extractor is an internal Docker-network service. Version 1 establishes
request validation, provider recognition, adapter boundaries, and stable response
shapes. PR #4 does not perform remote metadata extraction, DNS resolution, redirects,
downloads, conversion, or shell execution.

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

## Provider recognition response

Every PR #4 adapter is a controlled stub. A recognized URL therefore returns HTTP
`501` with `provider_not_implemented`:

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

## Future success response

When a later provider PR implements extraction, HTTP `200` will use a versioned
`data` envelope. Fields may be added compatibly, but existing field meaning will not
change within v1:

```json
{
  "data": {
    "request_id": "request-id",
    "provider": "x",
    "provider_label": "X",
    "provider_variant": null,
    "media_type": "video",
    "source_url": "https://x.com/example/status/123",
    "normalized_url": "https://x.com/example/status/123",
    "status": "extracted",
    "metadata": {},
    "assets": [],
    "capabilities": []
  }
}
```

PR #4 never returns this success shape and never invents title, author, duration,
file size, thumbnail, resolution, codec, publication date, or media URLs.

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
| 501 | `provider_not_implemented` | Provider is recognized but its adapter is a stub |
| 500 | `internal_error` | Unexpected internal service failure |
| 429 | `rate_limited` | Reserved for a future internal defense-in-depth limiter |
| 503 | `upstream_unavailable` | Reserved for future provider dependencies |

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

## Security boundary

PR #4 contains no HTTP client, DNS lookup, redirect follower, shell invocation,
yt-dlp, FFmpeg, or file download path. Adapters receive a validated provider context,
not permission to fetch arbitrary URLs. A dedicated outbound policy layer must be
introduced before a future adapter can access remote resources.

Comprehensive DNS-rebinding, redirect-chain, and egress enforcement remain planned
for PR #10 and are not claimed as complete here.

## Versioning policy

Breaking changes require a new path such as `/v2/extract`. Compatible optional fields
may be added to v1. Existing keys, provider identifiers, error codes, and their
semantics remain stable throughout v1.

FastAPI interactive documentation and OpenAPI output are disabled in production.
They are available only when `EXTRACTOR_ENV` is `local`, `development`, or `testing`.
