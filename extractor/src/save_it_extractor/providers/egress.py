"""Shared, pinned egress policy for HTTPX-based provider metadata requests."""

import asyncio
import ipaddress
import socket
from collections.abc import Iterable
from dataclasses import dataclass
from urllib.parse import urlsplit

import httpcore
import httpx


class EgressPolicyError(Exception):
    def __init__(self, reason: str) -> None:
        super().__init__(reason)
        self.reason = reason


class EgressResponseTooLarge(Exception):
    pass


@dataclass(frozen=True, slots=True)
class ResolvedRemote:
    host: str
    addresses: tuple[str, ...]


def is_allowed_url(raw_url: str, allowed_hosts: Iterable[str]) -> bool:
    try:
        parsed = urlsplit(raw_url)
        port = parsed.port
    except ValueError:
        return False
    host = (parsed.hostname or "").rstrip(".").lower()
    return (
        parsed.scheme == "https"
        and host_allowed(host, allowed_hosts)
        and parsed.username is None
        and parsed.password is None
        and port is None
    )


def host_allowed(host: str, allowed_hosts: Iterable[str]) -> bool:
    for allowed in allowed_hosts:
        if allowed.startswith("."):
            suffix = allowed[1:]
            if host != suffix and host.endswith(allowed):
                return True
        elif host == allowed:
            return True
    return False


async def resolve_allowed_url(raw_url: str, allowed_hosts: Iterable[str]) -> ResolvedRemote:
    if not is_allowed_url(raw_url, allowed_hosts):
        raise EgressPolicyError("unsafe_url")
    host = (urlsplit(raw_url).hostname or "").rstrip(".").lower()
    try:
        records = await asyncio.to_thread(socket.getaddrinfo, host, 443, type=socket.SOCK_STREAM)
    except socket.gaierror as exception:
        raise EgressPolicyError("dns_unavailable") from exception

    addresses = tuple(dict.fromkeys(record[4][0] for record in records))
    if not addresses:
        raise EgressPolicyError("dns_unavailable")
    if any(not is_public_address(address) for address in addresses):
        raise EgressPolicyError("unsafe_address")
    return ResolvedRemote(host, addresses)


def is_public_address(raw_address: str) -> bool:
    try:
        address = ipaddress.ip_address(raw_address)
    except ValueError:
        return False
    return (
        address.is_global
        and not address.is_multicast
        and not address.is_unspecified
        and not address.is_reserved
        and (not isinstance(address, ipaddress.IPv6Address) or address.ipv4_mapped is None)
    )


def content_type(raw_value: str) -> str:
    return raw_value.split(";", 1)[0].strip().lower()


async def read_limited(response: httpx.Response, maximum: int) -> bytes:
    body = bytearray()
    async for chunk in response.aiter_bytes():
        body.extend(chunk)
        if len(body) > maximum:
            raise EgressResponseTooLarge
    return bytes(body)


class PinnedAsyncNetworkBackend(httpcore.AsyncNetworkBackend):
    """Pins TCP connects to validated addresses while HTTPX retains URL host for TLS/SNI."""

    def __init__(self) -> None:
        self._backend = httpcore._backends.auto.AutoBackend()
        self._pins: dict[tuple[str, int], tuple[str, ...]] = {}

    def pin(self, remote: ResolvedRemote, port: int = 443) -> None:
        self._pins[(remote.host, port)] = remote.addresses

    async def connect_tcp(
        self,
        host: str,
        port: int,
        timeout: float | None = None,
        local_address: str | None = None,
        socket_options: Iterable[tuple[int, int, int]] | None = None,
    ) -> httpcore.AsyncNetworkStream:
        addresses = self._pins.get((host.rstrip(".").lower(), port))
        if not addresses:
            raise httpcore.ConnectError("unvalidated upstream host")
        last_error: Exception | None = None
        for address in addresses:
            try:
                return await self._backend.connect_tcp(
                    address,
                    port,
                    timeout=timeout,
                    local_address=local_address,
                    socket_options=socket_options,
                )
            except (httpcore.ConnectError, httpcore.ConnectTimeout) as exception:
                last_error = exception
        raise httpcore.ConnectError("validated upstream host was unavailable") from last_error

    async def connect_unix_socket(self, *args, **kwargs):  # type: ignore[no-untyped-def]
        return await self._backend.connect_unix_socket(*args, **kwargs)

    async def sleep(self, seconds: float) -> None:
        await self._backend.sleep(seconds)


class PinnedAsyncHTTPTransport(httpx.AsyncHTTPTransport):
    def __init__(self) -> None:
        super().__init__(retries=0)
        self.backend = PinnedAsyncNetworkBackend()
        self._pool = httpcore.AsyncConnectionPool(network_backend=self.backend)

    def pin(self, remote: ResolvedRemote) -> None:
        self.backend.pin(remote)
