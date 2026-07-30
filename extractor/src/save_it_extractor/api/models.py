from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field


class ExtractOptions(BaseModel):
    model_config = ConfigDict(extra="forbid")

    metadata_only: Literal[True] = True


class ExtractRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    url: str = Field(min_length=1, max_length=2048)
    request_id: str | None = Field(
        default=None,
        min_length=1,
        max_length=64,
        pattern=r"^[A-Za-z0-9][A-Za-z0-9._:-]*$",
    )
    options: ExtractOptions = Field(default_factory=ExtractOptions)


class ResolveYouTubeRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    url: str = Field(min_length=1, max_length=2048)
    format_id: str = Field(
        min_length=1,
        max_length=100,
        pattern=r"^[A-Za-z0-9._+-]+$",
    )
    request_id: str | None = Field(
        default=None,
        min_length=1,
        max_length=64,
        pattern=r"^[A-Za-z0-9][A-Za-z0-9._:-]*$",
    )


class ErrorBody(BaseModel):
    code: str
    message: str
    request_id: str
    details: dict[str, Any] = Field(default_factory=dict)


class ErrorResponse(BaseModel):
    error: ErrorBody
