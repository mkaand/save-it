from dataclasses import dataclass
from os import getenv


@dataclass(frozen=True, slots=True)
class Settings:
    app_name: str = getenv("EXTRACTOR_APP_NAME", "save-it-extractor")
    environment: str = getenv("EXTRACTOR_ENV", "production")
    log_level: str = getenv("EXTRACTOR_LOG_LEVEL", "INFO")
    api_version: str = getenv("EXTRACTOR_API_VERSION", "1")
    request_timeout_seconds: float = float(getenv("EXTRACTOR_REQUEST_TIMEOUT_SECONDS", "10"))
    max_body_bytes: int = 8192
    max_url_length: int = 2048

    @property
    def expose_docs(self) -> bool:
        return self.environment.lower() in {"local", "development", "testing"}


settings = Settings()
