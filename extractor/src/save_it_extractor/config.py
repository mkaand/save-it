from dataclasses import dataclass
from os import getenv


@dataclass(frozen=True, slots=True)
class Settings:
    app_name: str = getenv("EXTRACTOR_APP_NAME", "save-it-extractor")
    environment: str = getenv("EXTRACTOR_ENV", "production")
    log_level: str = getenv("EXTRACTOR_LOG_LEVEL", "INFO")
    api_version: str = getenv("EXTRACTOR_API_VERSION", "1")
    request_timeout_seconds: float = float(getenv("EXTRACTOR_REQUEST_TIMEOUT_SECONDS", "12"))
    max_body_bytes: int = 8192
    max_url_length: int = 2048
    x_connect_timeout_seconds: float = 3.0
    x_read_timeout_seconds: float = 6.0
    x_max_redirects: int = 3
    x_max_metadata_bytes: int = 3 * 1024 * 1024
    x_max_assets: int = 20
    x_max_variants: int = 12

    @property
    def expose_docs(self) -> bool:
        return self.environment.lower() in {"local", "development", "testing"}


settings = Settings()
