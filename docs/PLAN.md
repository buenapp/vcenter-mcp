# vCenter MCP Server — Plan

MCP server (stdio) exposing VMware vCenter to agents. Built on the Enchilada
Framework 3.0 (PHP 8.4, FreeBSD), structured exactly like `forgejo-mcp`
(pacyworld.dev/pacyworld/forgejo-mcp): vendored Enchilada libraries, Tortilla
stdio transport with Comal reactor, `#[McpTool]` tool classes in `tools/`,
`config/instances.json`, PHAR build, Forgejo Actions CI + release.

## Acceptance criteria (MVP / v0.1.0)

Using only the MCP tools of this server:

1. Provision a new VM on vcenter.example.com on any host **except**
   `thebe.example.com`, network `Default`, CD-ROM backed by
   `[CDImages] FreeBSD OS/FreeBSD-15.1-RELEASE-amd64-bootonly.iso`.
2. Boot it and drive the interactive `bsdinstall` to completion from the
   console (screenshots to read, keystrokes to type).
3. Reach a login prompt on the console.
4. Log in remotely over SSH as a user created during install.

## Target environment

| Item | Value |
|---|---|
| vCenter | `https://vcenter.example.com` |
| Credentials | user `vcadmin`, password from `~/.netrc` (`machine vcenter.example.com`) |
| Excluded host | `thebe.example.com` — never place VMs there |
| Network | `Default` |
| ISO | `[CDImages] FreeBSD OS/FreeBSD-15.1-RELEASE-amd64-bootonly.iso` |

Status 2026-09-11: the vCenter management network was initially unreachable
from the dev workstation (routing fixed by the owner); until then
development was API-doc driven with unit tests, and the live acceptance
run was the last phase.

## Architecture

```
bin/vcenter-mcp                 composition root (mirrors bin/forgejo-mcp)
classes/VCenter/
  InstanceManager.class.php     instances.json -> Instance objects; extends EnchiladaMCP\InstanceRegistry
  Instance.class.php            url, credentials (inline or ~/.netrc), tls policy, exclude_hosts, defaults
  RestClient.class.php          vSphere Automation REST (/api/...), session token, auto re-login on 401
  SoapClient.class.php          vim25 SOAP (/sdk): hand-built envelopes, cookie session, typed helpers
  PropertyCollector.class.php   RetrievePropertiesEx helpers (props of MoRefs, parent walk to Datacenter)
  Task.class.php                SOAP task wait (RetrievePropertiesEx on Task.info until success/error)
  Inventory.class.php           name->id resolution (host, cluster, datastore, network, folder, vm), host exclusion
  UsbScanCodes.class.php        text / key names -> UsbScanCodeSpec key events (US layout)
  Screenshot.class.php          CreateScreenshot_Task -> /folder download -> DeleteDatastoreFile_Task
  Console/WebMksClient.class.php   AcquireTicket(webmks) -> wss RFB session (Phase 3)
  Console/Rfb.class.php            RFB 3.8 message codec (raw encoding only)
  Console/Png.class.php            raw RGB framebuffer -> PNG (zlib, no GD)
  TlsPolicy.class.php           DANE TLSA first, CA store fallback, optional SHA-256 thumbprint pin
  VCenterException.class.php    error mapping (vapi std errors, SOAP faults)
tools/
  InstanceTools.php  InventoryTools.php  VmTools.php  DeviceTools.php
  PowerTools.php     ConsoleTools.php    GuestTools.php
libraries/            vendored byte-identical (see Vendoring)
tests/                phpunit, no network; fakes injected at the HTTP seam
config/instances.json.sample, config/instructions.txt
docs/PLAN.md docs/ARCHITECTURE.md docs/SETUP.md
```

HTTP goes through the same seam ForgejoMCP uses (`Enchilada\Tortilla\HttpClient`
over `EnchiladaMultiHTTP`, loop-aware when Comal is present) so long vCenter
calls keep the stdio channel alive with progress ticks. Both REST and SOAP
share one HTTP engine per instance; the SOAP session cookie
(`vmware_soap_session`) and the REST token (`vmware-api-session-id`) are held
on the `Instance`.

### TLS policy (mandatory, global rule)

`TlsPolicy::verify(host, port)` order:

1. Look up `_443._tcp.<host>` TLSA via `Enchilada\Dns\Resolver` (type 52).
   If records exist: connect with peer verification disabled but
   `CURLOPT_CERTINFO` on, extract the leaf cert, and require a match against
   any usable record (usage 3 = DANE-EE; selector 0 full cert / 1 SPKI;
   matching 1 = SHA-256, 2 = SHA-512). Usage 2 (DANE-TA) is out of scope for
   v0.1 and documented as such. Mismatch = hard failure.
2. No TLSA: standard CA verification (`ca_cert` override supported).
3. Optional `tls.thumbprint` (SHA-256 of leaf, colon-hex) pins the leaf in
   either mode; it does not disable step 1/2.

If `Enchilada\Dns\Resolver` cannot return raw RDATA for type 52, add that to
Enchilada/Extras upstream (branch + PR), vendor from that branch, and record
the PR in this file. No local forks of library code.

Status: Resolver could not decode type 52 — added upstream via
Enchilada/Extras PR #50 (TLSA decode to usage/selector/matching_type/
cert_data + raw `rdata` for unknown types). Merged; vendored from master.

Status: `StreamTransport` needed caller-provided TLS context options
(verify_peer off + capture_peer_cert for the WebMKS ticket thumbprint) —
added upstream via Enchilada/Extras PRs #51/#53 (constructor/setter
`setContextOptions` + `getPeerCertificate()`, drain-timeout and RFC 6455
GUID fixes). Merged; vendored from master.

### vSphere REST API (Automation API, `/api/...`)

Session: `POST /api/session` (Basic) -> token string; header
`vmware-api-session-id`; `DELETE /api/session` on shutdown; re-login once
on 401 then retry the request.

| Purpose | Endpoint |
|---|---|
| Datacenters | `GET /api/vcenter/datacenter` |
| Clusters | `GET /api/vcenter/cluster?datacenters=` |
| Hosts | `GET /api/vcenter/host?datacenters=&clusters=` (`connection_state`, `power_state`) |
| Datastores | `GET /api/vcenter/datastore?datacenters=&types=`; `GET /api/vcenter/datastore/{id}` (capacity/free) |
| Networks | `GET /api/vcenter/network?datacenters=&types=` |
| Folders | `GET /api/vcenter/folder?type=VIRTUAL_MACHINE&datacenters=` |
| Resource pools | `GET /api/vcenter/resource-pool?clusters=&hosts=` |
| VMs | `GET /api/vcenter/vm?names=&hosts=&power_states=&folders=`; `GET/DELETE /api/vcenter/vm/{vm}` |
| Create VM | `POST /api/vcenter/vm` |
| Power | `GET/POST /api/vcenter/vm/{vm}/power?action=start\|stop\|reset\|suspend` |
| Guest power | `POST /api/vcenter/vm/{vm}/guest/power?action=shutdown\|reboot` |
| CPU / memory | `PATCH /api/vcenter/vm/{vm}/hardware/cpu`, `.../hardware/memory` |
| CD-ROM | `GET/POST .../hardware/cdrom`; `GET/PATCH/DELETE .../hardware/cdrom/{id}`; `POST .../hardware/cdrom/{id}?action=connect\|disconnect` |
| Disk | `GET/POST .../hardware/disk`; `GET/DELETE .../hardware/disk/{id}` |
| NIC | `GET/POST .../hardware/ethernet`; `GET/PATCH/DELETE .../hardware/ethernet/{id}` (`mac_address`) |
| Boot | `GET/PATCH .../hardware/boot` (`type` BIOS/EFI, `enter_setup_mode`); `GET/PUT .../hardware/boot/device` |
| Guest info | `GET .../guest/identity`, `GET .../guest/networking/interfaces` (503 when Tools absent) |
| Version | `GET /api/appliance/system/version` (may be forbidden for `vcadmin`; fall back to SOAP `about`) |

Create body (assembled by `VmTools::create_vm`):

```json
{"name":"...","guest_OS":"FREEBSD_64",
 "placement":{"host":"host-1","datastore":"datastore-1","folder":"group-v1","resource_pool":"resgroup-1"},
 "cpu":{"count":2,"cores_per_socket":1},"memory":{"size_MiB":2048},
 "scsi_adapters":[{"type":"PVSCSI","bus":0}],
 "disks":[{"type":"SCSI","new_vmdk":{"capacity":21474836480}}],
 "nics":[{"type":"VMXNET3","start_connected":true,"backing":{"type":"STANDARD_PORTGROUP","network":"network-1"}}],
 "cdroms":[{"type":"SATA","start_connected":true,"backing":{"type":"ISO_FILE","iso_file":"[CDImages] FreeBSD OS/x.iso"}}],
 "boot":{"type":"EFI"},"boot_devices":[{"type":"CDROM"},{"type":"DISK"}]}
```

`backing.type` is `DISTRIBUTED_PORTGROUP` when the resolved network's type
says so. A SATA adapter is added automatically by vCenter for a SATA CD-ROM
(`sata_adapters` is set explicitly to be safe). `guest_OS` default is
`FREEBSD_64` (accepted by every vCenter that has the REST API); callers may
pass `FREEBSD_14_64` etc.

### vim25 SOAP (`/sdk`)

Envelope: `xmlns:soapenv` + body in `urn:vim25`; header
`SOAPAction: "urn:vim25/8.0.0.0"`; login sets cookie `vmware_soap_session`.

| Method | `_this` | Use |
|---|---|---|
| `RetrieveServiceContent` | `ServiceInstance` | about, sessionManager, propertyCollector, rootFolder, fileManager |
| `Login` | SessionManager | SOAP session (same credentials) |
| `Logout` | SessionManager | on shutdown |
| `RetrievePropertiesEx` | PropertyCollector | properties of any MoRef (`name`, `parent`, `runtime.question`, `info` of Task, `browser`/`datastore`/`network` of HostSystem) |
| `SearchDatastore_Task` | HostDatastoreBrowser | list files under `[ds] path` matching pattern (ISO discovery) |
| `CreateScreenshot_Task` | VirtualMachine | console PNG written next to the VM (`info.result` = `[ds] vm/vm-N.png`) |
| `DeleteDatastoreFile_Task` | FileManager | remove the screenshot file after download |
| `PutUsbScanCodes` | VirtualMachine | keystrokes; `usbHidCode = (hid << 16) \| 0x0007`, modifiers struct; chunk <= 32 events per call |
| `AcquireTicket` (`webmks`) | VirtualMachine | `{host, port, ticket, sslThumbprint}` for the WebMKS console |
| `AnswerVM` | VirtualMachine | answer blocking VM questions (`runtime.question`) |

Screenshot download: `GET https://<vcenter>/folder/<path>?dcPath=<Datacenter
name>&dsName=<datastore name>` with the `vmware_soap_session` cookie. The
datacenter name comes from walking `parent` from the VM up to the
`Datacenter` MoRef.

### WebMKS console (Phase 3)

`AcquireTicket('webmks')` -> `wss://{host}:{port}/ticket/{ticket}` with
`Sec-WebSocket-Protocol: binary` (offer only `binary`, so the server does not
select `vmware-vvc` framing — hypothesis to confirm live). Then plain RFB 3.8:
version, security type 1 (None; the ticket is the auth), `ClientInit`,
`ServerInit`, `SetPixelFormat` (32bpp true-colour), `SetEncodings [0]` (Raw
only), `FramebufferUpdateRequest(incremental=0)`. Assemble rectangles into a
framebuffer and encode PNG. Keys: RFB `KeyEvent` with X11 keysyms. Uses the
vendored `EnchiladaWebSocket` client over `StreamTransport` (TLS to the ESXi
host; TLS policy = TLSA first, then pin to `sslThumbprint` from the ticket,
which is the vSphere-sanctioned trust anchor for this hop).

The ESXi host in the ticket must be reachable from the MCP server host.
`method=auto` on console tools tries WebMKS and falls back to the SOAP
screenshot / `PutUsbScanCodes` path with a warning in the result.

## Tools

All tools accept optional `instance` (default from instances.json). Names
are unprefixed like forgejo-mcp; hosts, clusters, datastores, networks,
folders and VMs accept either a MoRef id or a name.

InstanceTools
- `list_vcenter_instances` (readOnly)
- `get_vcenter_info` — SOAP `about` (fullName, version, build, apiVersion), REST session state

InventoryTools (all readOnly)
- `list_datacenters`, `list_clusters(datacenter?)`, `list_hosts(datacenter?, cluster?)` (marks `excluded: true`), `list_datastores(datacenter?, host?, type?)` (capacity/free), `list_networks(datacenter?, type?)`, `list_folders(datacenter?, type=VIRTUAL_MACHINE)`, `list_resource_pools(cluster?, host?)`
- `browse_datastore(datastore, path='', pattern='*')` — SOAP datastore browser; used to find the ISO

VmTools
- `list_vms(names?, hosts?, power_states?, folders?)`, `get_vm(vm)` (hardware, disks, nics+MACs, cdroms, boot, power)
- `create_vm(name, host?, cluster?, datastore?, network, iso?, folder?, resource_pool?, guest_os='FREEBSD_64', cpu=2, memory_mib=2048, disk_gib=20, firmware='EFI', nic_type='VMXNET3', scsi_type='PVSCSI')`
  - Host exclusion is enforced here: an explicit excluded host is rejected; when `host` is omitted the tool picks a `CONNECTED`/`POWERED_ON` non-excluded host (in the cluster/datacenter if given) and, when `datastore` is omitted, the host-visible datastore with most free space (host `datastore` property via SOAP).
  - With `iso`, boot order is CDROM then DISK.
- `delete_vm(vm, force=false)` — refuses a powered-on VM unless `force` (then powers off first)
- `set_vm_hardware(vm, cpu?, memory_mib?)`

PowerTools
- `vm_power(vm, action: on|off|reset|suspend|shutdown_guest|reboot_guest)`, `get_vm_power(vm)` (readOnly)

DeviceTools
- `list_cdroms(vm)`, `attach_iso(vm, iso, cdrom?)` (PATCH existing / POST new SATA; connects when powered on), `detach_iso(vm, cdrom?)` (disconnect + `CLIENT_DEVICE` backing)
- `list_disks(vm)`, `add_disk(vm, size_gib)`
- `list_nics(vm)`, `add_nic(vm, network, type='VMXNET3')`
- `set_boot(vm, firmware?, order?: [CDROM, DISK, ETHERNET], enter_setup_mode?)`

ConsoleTools
- `vm_screenshot(vm, method='auto|soap|webmks')` -> `ToolResult::image` PNG + text (size, method used)
- `vm_send_keys(vm, text?, keys?, enter=false, delay_ms=20, method='auto|soap|webmks')` — `text` is typed literally (US layout, shift handled); `keys` is a list of names: `enter tab esc space backspace delete up down left right home end pageup pagedown insert f1..f12` and combos `ctrl-c ctrl-alt-del alt-f2 shift-tab ...`; `enter=true` appends Enter after `text`
- `get_vm_question(vm)` (readOnly), `answer_vm_question(vm, choice)`

GuestTools
- `get_guest_info(vm)` (readOnly) — identity + interfaces; clean message when VMware Tools is absent
- `find_vm_ip(vm)` (readOnly) — guest interfaces when available, otherwise the NIC MACs with guidance (DHCP leases / console `ifconfig`)

## Configuration

`config/instances.json` (gitignored; `.sample` committed):

```json
{
  "default": "lab",
  "instances": {
    "lab": {
      "url": "https://vcenter.example.com",
      "description": "Lab vCenter",
      "username": "vcadmin",
      "password": null,
      "netrc": true,
      "tls": {"verify": true, "ca_cert": null, "thumbprint": null},
      "exclude_hosts": ["thebe.example.com"],
      "defaults": {"datacenter": null, "cluster": null, "network": "Default", "datastore": null, "folder": null}
    }
  }
}
```

`password: null` + `netrc: true` reads `~/.netrc` (`machine <url host>`).
Env `VCENTER_MCP_CONFIG`, `VCENTER_MCP_LOG`, `VCENTER_MCP_LOG_LEVEL`,
`VCENTER_MCP_LOG_STDERR`, `VCENTER_MCP_IO_MODE` mirror forgejo-mcp's
`FORGEJO_MCP_*`; CLI flags `--config= --log= --log-level= --io-mode=`.

## Vendoring

Byte-identical from canonical sources, after `git pull` on master:

| Vendored dir | Source |
|---|---|
| `system/` | Enchilada/Framework `system/` (app.conf.php is app-owned) |
| `libraries/EnchiladaMCP/` | Enchilada/Extras `MCP/` |
| `libraries/EnchiladaHTTP/EnchiladaHTTP.class.php`, `libraries/EnchiladaMultiHTTP/EnchiladaMultiHTTP.class.php` | Enchilada/Extras `HTTP/` (eponymous dirs) |
| `libraries/EnchiladaWebSocket/` | Enchilada/Extras `WebSocket/` (check namespace; vendor per README rule) |
| `libraries/Enchilada/Dns/` | Enchilada/Extras `Dns/` |
| `libraries/Enchilada/Tortilla/` | Enchilada/Tortilla `src/` |
| `libraries/Enchilada/Comal/` | Enchilada/Comal |

Never copy from another project's `libraries/`.

## Phases

0. ~~Repo + scaffold (framework, vendoring, bin, config samples, README, CI, PHAR builder, phpunit skeleton).~~ Done.
1. ~~Core + inventory/VM/device/power/guest tools, TlsPolicy, tests.~~ Done.
2. ~~Console: SOAP screenshot, USB scan codes, VM questions. Tests.~~ Done.
3. ~~WebMKS console + `method=auto`. Tests.~~ Done.
4. ~~Register in `~/.config/devin/mcp_config.json`; live acceptance run once vCenter is routable; fix what reality disagrees with.~~ Done — acceptance met 2026-09-11: VM provisioned, FreeBSD 15.1 installed via console tools, login prompt reached, SSH in. Live fixes: SOAP session cookie from Set-Cookie, vim25 element ordering, webmks partial-frame re-requests, WS GUID + drain-timeout upstream fixes.
5. ~~`release: v0.1.0` tag -> CI release.yml builds the PHAR.~~ Done.

## Tests (phpunit, offline)

- RestClient: token header, 401 -> re-login -> retry once, error mapping.
- SoapClient: envelope shape per method, fault parsing, cookie capture; fixtures under `tests/fixtures/soap/`.
- PropertyCollector/Task: parse `RetrievePropertiesEx` results; task success/error/timeout.
- Inventory: name -> id, ambiguity error, excluded-host rejection, auto host/datastore pick.
- VmTools::create_vm body assembly (fixture-driven), DeviceTools method+path assertions.
- UsbScanCodes: `a`->0x04, `A`->0x04+leftShift, `!`->0x1E+leftShift, `enter`->0x28, combos, chunking, hid formula.
- Png: signature, IHDR, CRCs, round-trips through `imagecreatefromstring` when GD is present (skip otherwise).
- Rfb: ServerInit + raw FramebufferUpdate decode from byte fixtures; KeyEvent encoding.
- TlsPolicy: TLSA 3 1 1 / 3 0 1 match and mismatch against a fixture cert.
- Vendored-library layout check (as in forgejo-mcp).

## Open items / deferred

- DANE-TA (usage 2) validation — deferred to v0.2; v0.1 handles DANE-EE only and says so in the log when a usage-2 record is skipped.
- Content Library ISOs — not needed (ISO lives on a datastore).
- HTTP/SSE transport — stdio only for v0.1, same as forgejo-mcp at launch.
- ~~Vendored files pending upstream merges (Extras PRs #50–#53, Tortilla PR #6)~~ — all merged; everything tracks upstream master.
