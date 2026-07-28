FROM python:3.12.12-slim-bookworm AS builder

ENV PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PIP_NO_CACHE_DIR=1 \
    PYTHONDONTWRITEBYTECODE=1

WORKDIR /build

RUN python -m venv /opt/venv

COPY requirements.lock ./

RUN /opt/venv/bin/pip install --requirement requirements.lock

FROM python:3.12.12-slim-bookworm AS runtime

ENV PATH="/opt/venv/bin:${PATH}" \
    PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONPATH=/app/src

RUN groupadd --gid 10001 extractor \
    && useradd --uid 10001 --gid extractor --home-dir /nonexistent --shell /usr/sbin/nologin extractor

WORKDIR /app

COPY --from=builder /opt/venv /opt/venv
COPY --chown=10001:10001 src ./src

RUN chmod -R a+rX /app/src

USER 10001:10001

EXPOSE 8000

HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=3 \
    CMD ["python", "-c", "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8000/health', timeout=2).read()"]

CMD ["uvicorn", "save_it_extractor.main:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "1", "--limit-concurrency", "32", "--no-access-log"]
