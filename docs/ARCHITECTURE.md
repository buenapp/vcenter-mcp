# vCenter MCP Server — Architecture

## Stack

Three independently vendored libraries plus the framework core:

| Path | Source | Role |
|---|---|---|
| `system/` | Enchilada/Framework | bootstrap, autoloader (app.conf.php is app-owned) |
| `libraries/EnchiladaMCP/` | Enchilada/Extras `MCP/` | MCP protocol core: McpServer, McpTool, ToolRegistry, ToolResult, Logger, InstanceRegistry |
| `libraries/EnchiladaHTTP/`, `libraries/EnchiladaMultiHTTP/` | Enchilada/Extras `HTTP/` | blocking + curl_multi HTTP engines (eponymous dirs) |
| `libraries/Enchilada/Tortilla/` | Enchilada/Tortilla `src/` | wire transports (StdioTransport), EventLoop port, loop-aware HttpClient |
| `libraries/Enchilada/Comal/` | Enchilada/Comal `src/` | event reactors (kqueue/select/libev) behind Tortilla's EventLoop |
| `libraries/Enchilada/Dns/` | Enchilada/Extras `Dns/` | DNS resolver with TLSA (type 52) decode |
| `libraries/EnchiladaWebSocket/` | Enchilada/Extras `WebSocket/` | WebSocket client for the WebMKS console |

All vendored code is byte-identical to its canonical source (after
`git pull` on master). Never copy from another project's `libraries/`.

## Layout

```
bin/vcenter-mcp        composition root: config -> InstanceManager -> McpServer -> StdioTransport
classes/VCenter/       application classes (namespace VCenter)
  InstanceManager      extends EnchiladaMCP\InstanceRegistry; builds Instance objects
  Instance             one vCenter: credentials, TLS policy, exclusions; owns the HTTP engine
  RestClient           vSphere Automation API (/api/...), session token, re-login on 401
  SoapClient           vim25 SOAP (/sdk), hand-built envelopes, vmware_soap_session cookie
  PropertyCollector    RetrievePropertiesEx helpers, parent walk to Datacenter
  Task                 SOAP task wait on Task.info
  Inventory            name->MoRef resolution, host exclusion, auto placement
  TlsPolicy            TLSA (DANE-EE) first, CA store fallback, optional leaf pin
  Netrc                ~/.netrc parser (machine/default entries)
  UsbScanCodes         text/key names -> UsbScanCodeSpec events (US layout)
  KeyNames             shared key-name/combo parser (UsbScanCodes + Keysyms)
  Screenshot           CreateScreenshot_Task -> /folder download -> DeleteDatastoreFile_Task
  Console/WebMksClient AcquireTicket -> wss -> RFB session on the ESXi host
  Console/Rfb          RFB 3.8 codec (Raw encoding only), incremental decode
  Console/Framebuffer  32bpp framebuffer + coverage tracking
  Console/Keysyms      text/key names -> X11 keysym strokes
  Console/Png          RGB -> PNG; hand-rolled zlib stored blocks (no ext-zlib needed)
  VCenterException     vapi error / SOAP fault mapping
tools/                 #[McpTool] classes, constructed with the InstanceManager
tests/                 phpunit, offline; HTTP seam faked via injected callables
config/                instances.json (gitignored), instances.json.sample, instructions.txt
```

## HTTP seam

Both REST and SOAP share one `Enchilada\Tortilla\HttpClient`
(over `EnchiladaMultiHTTP`) per instance. The composition root injects
the shared Comal event loop and the server's progress emitter via
`InstanceManager::setHttpTransport()`, so long vCenter calls park the
dispatch fiber (reactor mode) or emit progress ticks (blocking mode)
and the stdio channel stays alive. Tests inject a plain
`callable(method, url, headers, body): {code, body, headers}` per
instance — no network.

## TLS policy

`TlsPolicy::verify()` order (per docs/PLAN.md):

1. `_443._tcp.<host>` TLSA lookup via `Enchilada\Dns\Resolver`. Records
   present: probe the peer with verification off and `CURLOPT_CERTINFO`,
   hash the leaf (selector 0 full cert / 1 SPKI; matching 1 SHA-256 /
   2 SHA-512), require a match. Usage 2 (DANE-TA) is out of scope for
   v0.1 and logged as skipped.
2. No TLSA: standard CA verification (`tls.ca_cert` override supported).
3. `tls.thumbprint` (SHA-256 of leaf, colon-hex) additionally pins the
   leaf in either mode.

## Credentials

`username` + `password` inline, or `password: null` + `netrc: true` to
read `~/.netrc` (`machine <url host>`, `default` fallback). Passwords
are never logged — request bodies reduce to length + SHA-256 digest.

## Console paths

Console tools take `method=auto|soap|webmks`:

- **SOAP** (always available): `vm_screenshot` runs
  CreateScreenshot_Task -> datastore `/folder` download ->
  DeleteDatastoreFile_Task; `vm_send_keys` uses PutUsbScanCodes (USB HID,
  <=32 events per call). Both go through vCenter only.
- **WebMKS**: AcquireTicket('webmks') returns a single-use ticket for
  `wss://<esxi-host>:<port>/ticket/<ticket>`; the ESXi host must be
  reachable from the MCP host. TLS peer verification is off, but the
  leaf is authenticated: DANE TLSA for `_<port>._tcp.<host>` first,
  otherwise the ticket's `sslThumbprint` (SHA-1) or any
  `certThumbprintList` entry (SHA-256 on vSphere 8). The WebSocket
  handshake offers only the `binary` subprotocol; inside it is plain
  RFB 3.8 (security None, Raw encoding, 32bpp). Keystrokes go as RFB
  KeyEvents with X11 keysyms. webmks answers each
  FramebufferUpdateRequest with only the dirty region, so
  `screenshot()` re-requests until the framebuffer is complete.
  Wire reads are single blocking `fread()` calls with a stream timeout
  (a `drain()` loop on a blocking socket would burn the deadline), and
  a timed-out `fread` on a TLS stream returns `false`, not `''`.
- **auto** tries WebMKS once (plus one retry with a fresh ticket after
  an I/O error) and falls back to SOAP with a note in the result.
- Open WebMKS sessions are cached per VM on the `Instance` (tickets
  last ~30 s) and closed by the shutdown hook in `bin/vcenter-mcp`
  alongside REST/SOAP logout.

## Error model

REST errors map to `VCenterException` carrying the vapi error type and
first message; HTTP 401 triggers one re-login + retry. SOAP faults map
to `VCenterException` with faultcode + faultstring. Tool classes return
plain arrays; `McpServer` serializes results and converts thrown
exceptions into MCP error results.
