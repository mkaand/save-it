import asyncio
import logging
import time
from contextlib import asynccontextmanager
from uuid import uuid4

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse

from save_it_extractor.api.errors import ContractError, error_response
from save_it_extractor.api.models import ExtractRequest
from save_it_extractor.config import settings
from save_it_extractor.domain.urls import UrlValidationError
from save_it_extractor.logging import log_event
from save_it_extractor.providers.errors import ProviderError
from save_it_extractor.services.extractor import ExtractionService


@asynccontextmanager
async def lifespan(_: FastAPI):
    log_event("service_started", version=settings.api_version)
    yield
    log_event("service_stopped", version=settings.api_version)


app = FastAPI(
    title="Save It Extractor",
    version=settings.api_version,
    docs_url="/docs" if settings.expose_docs else None,
    redoc_url="/redoc" if settings.expose_docs else None,
    openapi_url="/openapi.json" if settings.expose_docs else None,
    lifespan=lifespan,
)
service = ExtractionService()


@app.middleware("http")
async def request_policy(request: Request, call_next):
    request_id = str(uuid4())
    request.state.request_id = request_id
    started = time.monotonic()
    provider = None

    content_length = request.headers.get("content-length")
    if content_length is not None:
        try:
            if int(content_length) > settings.max_body_bytes:
                return error_response(
                    ContractError(
                        "request_too_large",
                        "The request body is too large.",
                        413,
                        request_id,
                    )
                )
        except ValueError:
            return error_response(
                ContractError(
                    "validation_error",
                    "The Content-Length header is invalid.",
                    400,
                    request_id,
                )
            )

    if request.method in {"POST", "PUT", "PATCH"}:
        body = await request.body()
        if len(body) > settings.max_body_bytes:
            return error_response(
                ContractError(
                    "request_too_large",
                    "The request body is too large.",
                    413,
                    request_id,
                )
            )

    response = await call_next(request)
    provider = getattr(request.state, "provider", None)
    response.headers["X-Request-ID"] = request.state.request_id
    log_event(
        "request_completed",
        request_id=request.state.request_id,
        provider=provider,
        status=response.status_code,
        duration_ms=round((time.monotonic() - started) * 1000, 2),
    )
    return response


@app.exception_handler(RequestValidationError)
async def validation_exception_handler(
    request: Request, exception: RequestValidationError
) -> JSONResponse:
    errors = exception.errors()
    code = "validation_error"
    status_code = 400
    if any(
        error.get("type") == "string_too_long" and error.get("loc") and error["loc"][-1] == "url"
        for error in errors
    ):
        code = "url_too_long"
        status_code = 422

    return error_response(
        ContractError(
            code,
            "The request payload is invalid.",
            status_code,
            request.state.request_id,
        )
    )


@app.exception_handler(Exception)
async def internal_exception_handler(request: Request, exception: Exception) -> JSONResponse:
    log_event(
        "internal_error",
        level=logging.ERROR,
        request_id=request.state.request_id,
        provider=getattr(request.state, "provider", None),
        status=500,
        duration_ms=0,
        error_type=type(exception).__name__,
    )
    return error_response(
        ContractError(
            "internal_error",
            "The extractor service encountered an internal error.",
            500,
            request.state.request_id,
        )
    )


@app.get("/health")
async def health() -> dict[str, str]:
    return {
        "status": "ok",
        "service": settings.app_name,
        "version": settings.api_version,
    }


@app.get("/ready")
async def ready() -> dict[str, str]:
    return {
        "status": "ready",
        "service": settings.app_name,
        "version": settings.api_version,
    }


@app.post("/v1/extract")
async def extract(payload: ExtractRequest, request: Request) -> JSONResponse:
    request_id = payload.request_id or request.state.request_id
    request.state.request_id = request_id

    try:
        async with asyncio.timeout(settings.request_timeout_seconds):
            result = await service.extract(payload.url, request_id)
    except UrlValidationError as exception:
        return error_response(
            ContractError(
                exception.code,
                exception.message,
                422,
                request_id,
            )
        )
    except TimeoutError:
        return error_response(
            ContractError(
                "upstream_unavailable",
                "The extraction request exceeded its processing deadline.",
                503,
                request_id,
            )
        )
    except ProviderError as exception:
        provider = exception.details.get("provider")
        request.state.provider = provider if isinstance(provider, str) else None
        return error_response(
            ContractError(
                exception.code,
                exception.message,
                exception.status_code,
                request_id,
                exception.details,
            )
        )

    request.state.provider = result.provider.value
    if result.status == "ready":
        return JSONResponse(
            status_code=200,
            content={
                "data": {
                    "request_id": result.request_id,
                    "provider": result.provider.value,
                    "provider_label": result.provider_label,
                    "provider_variant": result.variant,
                    "media_type": result.media_type,
                    "source_url": payload.url,
                    "normalized_url": result.normalized_url,
                    "status": result.status,
                    "metadata": result.metadata,
                    "assets": result.assets,
                    "capabilities": result.capabilities,
                    "provider_maturity": result.maturity,
                    "warnings": result.warnings,
                }
            },
            headers={"X-Request-ID": request_id},
        )

    return error_response(
        ContractError(
            "provider_not_implemented",
            "The provider is recognized, but extraction is not implemented yet.",
            501,
            request_id,
            {
                "provider": result.provider.value,
                "provider_label": result.provider_label,
                "provider_variant": result.variant,
                "media_type": result.media_type,
                "source_url": payload.url,
                "normalized_url": result.normalized_url,
                "status": result.status,
                "metadata": result.metadata,
                "assets": result.assets,
                "capabilities": result.capabilities,
                "provider_maturity": result.maturity,
                "warnings": result.warnings,
            },
        )
    )
