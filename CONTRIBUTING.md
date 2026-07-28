# Contributing to Save It

Thank you for considering a contribution to Save It.

## Development workflow

1. Open an issue or discussion for material behavior changes.
2. Create a focused feature branch from an up-to-date `main`.
3. Keep user-facing copy, code, tests, documentation, commits, and pull requests in
   English.
4. Add deterministic tests. Provider fixture tests must not require live network
   access; optional live checks must be explicitly enabled.
5. Do not commit credentials, cookies, tokens, `.env`, runtime databases, media
   binaries, or temporary downloads.
6. Open a pull request and wait for all PHP, frontend, Python, and Docker checks.

Run the validation commands documented in `README.md` before requesting review.

## Security boundaries

Provider code must retain exact hostname allowlists, verified TLS, bounded redirects
and responses, private-address rejection, disabled proxy-environment inheritance,
sanitized logging, and no shell/subprocess execution. Real download delivery is not
implemented yet.

Please report security issues privately using the repository security advisory
workflow rather than a public issue.
