# Download delivery

## Scope

PR #9 adds delivery for media assets that were produced by a trusted provider
adapter. It does not expose a `download?url=` endpoint and never accepts an upstream
media URL from the browser.

Supported delivery modes:

- Range-aware proxy streaming for validated X, Instagram, LinkedIn, YouTube direct
  formats, M4A, and YouTube thumbnails;
- FFmpeg stream-copy merge for separate YouTube MP4 video and M4A audio;
- FFmpeg MP3 conversion at 128, 192, 256, or 320 kbps;
- fail-closed ZIP preparation for multi-asset X, Instagram, and LinkedIn results.

## Token model

Laravel writes the complete versioned plan to Redis under a one-way-hashed random
identifier. The browser receives only `<random-id>.<HMAC>`. The HMAC uses the
existing untracked Laravel application key. Tokens expire after ten minutes by
default. Tampered, missing, and expired tokens return a safe `410`.

The Redis plan may contain provider, asset/format identifiers, validated upstream
URL, MIME type, filename, expected size, mode, and conversion inputs. Those fields
are not encoded into the browser token. Recent Fetches never store the token, plan,
upstream URL list, signed CDN query, or job payload.

Direct proxy tokens remain reusable during their short lifetime so byte-range resume
works. Job tokens are atomically consumed on the first accepted job creation request
to prevent duplicate conversion or ZIP work.

## Direct streaming

`GET /api/downloads/{token}`:

- accepts no URL parameter;
- revalidates HTTPS, standard port, credentials, provider host allowlist, and all DNS
  answers;
- follows at most the configured number of redirects and revalidates every target;
- disables environment proxy inheritance and does not send cookies or Authorization;
- accepts only one RFC byte range;
- preserves `200`, `206`, `Content-Length`, `Content-Range`, `Accept-Ranges`, and
  safe `416` behavior;
- validates the media MIME, declared or range-total size, and maximum size; sources
  with no safe declared or expected size fail closed;
- streams in 64 KiB chunks without loading the complete body into PHP memory;
- stops reading and closes the upstream stream when the client disconnects;
- emits a sanitized RFC 6266 `Content-Disposition` with ASCII and UTF-8 filenames;
- disables Nginx/FastCGI buffering for download responses.

## Preparation jobs

`POST /api/download-jobs` accepts one issued job token. A Redis queue worker creates
an unpredictable `0700` job directory below `storage/app/private/downloads`.
`GET /api/download-jobs/{id}` returns only bounded status, stage, progress, a safe
error, and eventually a Save It download URL.

FFmpeg is invoked with a fixed binary path and argument array through `proc_open`
with shell bypass enabled. User commands and flags are not accepted. Jobs have one
attempt and a hard timeout. Inputs and outputs are size bounded, the output writer is
given an explicit byte ceiling, and FFmpeg is limited to two threads.

ZIP uses `ZipArchive`, safe basename-only names, deterministic duplicate-resistant
ordering, no symlinks, a maximum asset count, and an aggregate byte limit. The
policy is fail-closed: one failed asset fails the entire archive rather than
silently returning an incomplete set.

Prepared files receive a new short-lived token, are deleted after successful
delivery, and are also covered by a scheduled stale-directory cleanup.

## YouTube source resolution

Analysis exposes format identifiers but no direct Google media URLs. At delivery
time Laravel calls internal `POST /v1/youtube/resolve` with the already canonical
video URL and selected format ID. The extractor reruns pinned yt-dlp metadata-only
resolution and accepts only an HTTPS `*.googlevideo.com` source with no custom port
or credentials.

Direct formats and M4A stream immediately. Separate MP4/M4A sources are downloaded
to the isolated job directory and merged with stream copy. MP3 uses the selected
audio source and a fixed libmp3lame bitrate.

## Limits and PR #10 boundary

Defaults are documented in `.env.example`. Operators should size file, ZIP, timeout,
worker, disk, and retention limits for their host.

PR #9 includes mandatory provider allowlists, public-IP checks, TLS verification,
redirect validation, bounded requests, token integrity, filename/header
sanitization, and resource limits. PR #10 still owns centralized cross-provider
egress enforcement, connection-level DNS pinning and rebinding defense, advanced
abuse detection, Turnstile preparation, and distributed rate-limit policy.
