from dataclasses import dataclass, field
from typing import Any

from fastapi.responses import JSONResponse


@dataclass(frozen=True, slots=True)
class ContractError(Exception):
    code: str
    message: str
    status_code: int
    request_id: str
    details: dict[str, Any] = field(default_factory=dict)


def error_response(error: ContractError) -> JSONResponse:
    return JSONResponse(
        status_code=error.status_code,
        content={
            "error": {
                "code": error.code,
                "message": error.message,
                "request_id": error.request_id,
                "details": error.details,
            }
        },
        headers={"X-Request-ID": error.request_id},
    )
