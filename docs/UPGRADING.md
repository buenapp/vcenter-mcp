# UPGRADING — vCenter MCP Server

Developer/agent-facing. Read this **before** touching `libraries/` or
any network I/O. Architecture details: `docs/ARCHITECTURE.md`.

## Vendored libraries

All of `libraries/` is vendored byte-identical from canonical upstream
checkouts (pull first, then copy — never from another consumer's tree).
`tests/VendoredLibraryLayoutTest.php` asserts both placement and
byte-identity; for files pending upstream merges it compares against
`git show <branch>:<path>` in the canonical checkout.

| Vendored dir | Source | Ref |
|---|---|---|
| `libraries/EnchiladaMCP/` | `Enchilada/Extras` MCP/ | master |
| `libraries/Enchilada/Tortilla/` | `Enchilada/Tortilla` src/ | master |
| `libraries/Enchilada/Comal/` | `Enchilada/Comal` src/ | master |
| `libraries/EnchiladaHTTP/` | Extras `HTTP/EnchiladaHTTP.class.php` | master |
| `libraries/EnchiladaMultiHTTP/` | Extras `HTTP/EnchiladaMultiHTTP.class.php` | master |
| `libraries/Enchilada/Dns/` | Extras `Dns/` | master |
| `libraries/EnchiladaWebSocket/` | Extras `WebSocket/` | master |

All vendored files track upstream master; there are no pending
branch vendors. If upstream work is needed again, land it on an
upstream branch/PR, vendor from that branch, and point the layout
test at `git show <branch>:<path>` until it merges.

## Non-negotiables

1. **Eponymous vendoring, no guards.** Legacy global classes live in
   `libraries/<Class>/<Class>.class.php`. The framework autoloader is
   golden; a miss means wrong placement or namespace — fix that.
2. **All HTTP goes through `Tortilla\HttpClient`** (one engine per
   instance, shared by `RestClient` and `SoapClient`). Tests inject a
   callable via `InstanceManager`; nothing in `tools/` may instantiate
   raw HTTP.
3. **The SOAP session is the `vmware_soap_session` Set-Cookie value** —
   never `UserSession.key` (verified on 8.0.3: they differ).
4. **vim25 element order is strict** (WSDL/alphabetical order inside
   DataObjects; e.g. `SearchSpec` wants `details` before `matchPattern`,
   `FileQueryFlags` wants `fileType, fileSize, modification, fileOwner`).
5. **WebMKS wire reads are single blocking `fread`s** with a stream
   timeout; never `drain()` a blocking socket (it loops until empty and
   burns the deadline). A timed-out TLS `fread` returns `false`.
6. **webmks answers a FramebufferUpdateRequest with only the dirty
   region** — `WebMksClient::screenshot()` re-requests until the
   framebuffer is complete.
