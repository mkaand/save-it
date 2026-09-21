# Provider regression matrix

Deterministic fixture and parser tests are the primary CI contract. Live provider
checks are optional and must never be required for a reliable CI run.

| Provider | Normalization | Fixture/parser coverage | Safe error | Output contract |
| --- | --- | --- | --- | --- |
| YouTube | watch, short link, Shorts | pinned yt-dlp metadata fixtures | invalid/private/live handling | ordered direct/job outputs |
| YouTube Shorts | canonical Shorts path | pinned yt-dlp metadata fixtures | unsupported/live handling | ordered direct/job outputs |
| X | canonical status path | structured metadata fixtures | upstream/contract error mapping | ordered image/video assets |
| Instagram | post/reel canonical paths | embed and canonical structured fixtures | provider response mapping | ordered assets and durable previews |
| LinkedIn | public post/activity paths | Open Graph/JSON-LD fixtures | authwall/upstream mapping | progressive image/video assets |

Optional live tests are explicitly marked and disabled in normal CI. They use only
public, anonymous access and do not use cookies, accounts, or private content. The
production release smoke includes the documented Instagram portrait regression and
checks the delivered JPEG dimensions without exposing upstream media URLs.
