# Changelog

## [0.5.0] - 2026-10-04

### Added

- PCI/DirectPath passthrough management (issue #19):
  - `list_host_pci(host)` — passthrough state per PCI device from
    vim25 (`config.pciPassthruInfo` joined with `hardware.pciDevice`);
    `capable_only=true` by default.
  - `list_passthrough_devices(vm)` — a VM's Dynamic DirectPath
    devices with backing PCI id (`assignedId`), vendor/device ids and
    custom label.
  - `attach_pci(vm, device, host?)` / `detach_pci(vm, device)` via
    ReconfigVM_Task; the device host defaults to the VM's runtime
    host, devices already attached to another VM on the host are
    refused, and both require the VM powered off.
- OVA/OVF deployment (issue #18):
  - `ovf_info(image)` — parses the OVF descriptor of a datastore or
    http(s) package and reports name, networks, vApp properties,
    disks and EULAs.
  - `deploy_ova(image, name, ...)` — imports a tar .ova into a content
    library staging item with a server-side PULL (http(s) URL, or
    `ds:///vmfs/volumes/<uuid>` for datastore paths, so no bulk bytes
    cross this server), deploys it through `vcenter/ovf` with network
    mappings, vApp properties, placement and EULA acceptance, then
    deletes the staged item. `power_on` starts the VM after deploy.
- Instance default `content_library` renames the staging library
  (default `vcenter-mcp-staging`, created on the target datastore and
  reused).
- `SoapClient::downloadDatastoreFileTo()` streams datastore files to
  disk instead of holding them in memory; `RestClient` gained a
  per-call timeout and a legacy-`/rest` endpoint flavor, both needed
  for content-library transfers and the synchronous deploy call.
- Datastore file downloads and OVF deploys verified live against
  vCenter 8.0.3.

## [0.4.0] - 2026-09-27

### Added

- `deploy_vm` MCP prompt: renders the estate FreeBSD service-VM
  playbook (LSI boot volume, PVSCSI independent_persistent ZFS data
  volumes, vRAM auto-detect, EFI, scripted bsdinstall with dist-set
  filtering, provisioning script) from the caller's sizing, with
  argument completion for `datacenter`, `network` and `iso`. The server
  now advertises the `prompts` and `completions` capabilities.

### Changed

- Re-vendored EnchiladaMCP and Tortilla from canonical masters:
  prompts (`prompts/list`, `prompts/get`), `completion/complete`, MRTR
  elicitation for `tools/call`, and `subscriptions/listen` stream
  support (Extras d16a015/599fd03/0220a42, Tortilla 4d48ada/6ebb814).
  `VendoredLibraryLayoutTest` is green again.
- Tool and parameter descriptions compacted to cut `tools/list` size
  (7,135 -> 6,587 Qwen tokens): restating parameter descriptions
  dropped, implementation notes removed from tool descriptions. Each
  tool definition is self-contained: `instance` documents its default,
  and guidance formerly only in the server instructions (ambiguous
  names, detach_iso after installs) lives on the tools. Schemas, names
  and behavior unchanged.

## [0.3.0] - 2026-09-23

### Changed

- TLS certificate verification is now opt-in: `tls.verify` defaults to
  false, so vCenter/ESXi endpoints presenting VMCA-issued or
  self-signed certificates connect without a CA bundle. Set
  `tls.verify: true` to restore the previous DANE-first strict mode
  (`tls.ca_cert` / `tls.thumbprint` still honored). The WebMKS console
  hop is unaffected — it authenticates the ESXi leaf via DANE TLSA or
  the AcquireTicket thumbprints regardless.

## [0.2.0] - 2026-09-15

### Added

- SCSI storage visibility and control (issues #5 and #2):
  - `list_controllers(vm)` — SCSI/SATA/NVMe controllers with type,
    bus, hot-plug/sharing flags and attached unit numbers (vim25).
  - `list_disks(vm)` reworked over vim25: every disk now reports
    controller key + type, unit number, disk mode
    (persistent / independent_persistent / independent_nonpersistent),
    thin/thick provisioning and backing file. `get_vm` exposes the
    same detail.
  - `add_disk` extended with `controller_type`, `disk_mode` and
    `thin`. The extended path goes through ReconfigVM_Task with
    `fileOperation=create` (without it vCenter treats the add as
    attaching an existing file and either fails or creates a phantom
    0 KB disk). A missing controller type is created in the same call
    (requires power-off); attaching to an existing controller works
    hot.
  - `set_disk(vm, disk, disk_mode)` — change an existing disk's mode
    via ReconfigVM_Task (powered off). Provisioning is intentionally
    not editable: vCenter silently ignores thinProvisioned on backing
    edits, so thin/thick is chosen when the VMDK is created.
  - `create_vm` accepts `controllers` (one SCSI adapter type per bus,
    overrides `scsi_type`) and `disk_mode` (post-create vim25 edit of
    the boot disk backing).
  - `SoapClient::deleteVirtualDisk()` — DeleteVirtualDisk_Task for
    removing standalone VMDK files.
- `detach_iso` now auto-answers a blocking VM question (e.g. the
  "CD-ROM door locked" prompt) and retries the reconfigure once.
- Guest operations via VMware Tools (GuestOperationsManager over vim25
  SOAP), closing issue #4:
  - `guest_run` — StartProgramInGuest with a blocking wait on
    ListProcessesInGuest for the exit code. `command` runs a /bin/sh
    command line; `program`+`arguments` runs a binary directly (no
    shell interpretation). With `capture_output` (default) the command
    is wrapped `/bin/sh -c '{ cmd; } > /tmp/<n>.out 2>&1'` and the
    output is fetched back via InitiateFileTransferFromGuest — capture
    is POSIX guests only.
  - `guest_process_status` — ListProcessesInGuest (all or by pid).
  - `guest_upload` / `guest_download` — InitiateFileTransfer{To,From}Guest
    plus PUT/GET on the one-time /guestFile URL, contacted directly at
    the ESXi host through a per-host client under the instance TLS
    policy (VMCA signs host certs); an asterisk hostname falls back to
    the instance's vCenter, which proxies the transfer.
  - `guest_list_files` — ListFilesInGuest with pagination.
- Fault mapping for the common GuestOperations failures:
  GuestOperationsUnavailable ("Tools not running"), InvalidGuestLogin,
  GuestPermissionDenied, TooManyGuestLogons.

## [0.1.4] - 2026-09-13

### Fixed

- WebMKS `vm_screenshot` returned a stale frame on every call after the
  first: the Framebuffer's painted coverage was never cleared, so the
  update loop was skipped despite a fresh non-incremental
  FramebufferUpdateRequest. Framebuffer gains reset(); screenshot()
  resets before its initial request. Verified live against vm-15275
  (baseline -> typed char -> restored, hashes confirm fresh frames).

## [0.1.1] - 2026-09-12

### Added

- `get_video` / `set_video` video card tools over vim25 SOAP
  (config.hardware.device read, ReconfigVM_Task device edit) — the
  vSphere REST API does not model VirtualMachineVideoCard. Video card
  edits require the VM powered off; violations surface as a clean
  `PowerStateError` (REST pre-check plus InvalidPowerState fault
  mapping). `use_auto_detect=true` rejects explicit
  `video_ram_size_kb`/`num_displays` instead of silently dropping them.
- `read_datastore_file`: read a datastore file (e.g. a .vmx) via the
  /folder HTTP download. UTF-8 text verbatim, binary base64; 8 MiB cap.
- `create_vm` accepts `version` (VM hardware version, e.g. VMX_21).

### Fixed

- `Inventory::getDatastore` merges the datastore id into the detail
  response (the real API omits it); create_vm auto datastore selection
  previously produced a null placement and a bare 400.

## [0.1.0] - 2026-09-11

### Added

- 32 tools: instance info (`get_vcenter_info`, `list_vcenter_instances`);
  inventory (`list_datacenters`, `list_clusters`, `list_hosts`,
  `list_datastores`, `list_networks`, `list_folders`,
  `list_resource_pools`, `list_vms`, `browse_datastore`); VM lifecycle
  (`get_vm`, `create_vm`, `delete_vm`, `set_vm_hardware`); devices
  (`list_cdroms`, `attach_iso`, `detach_iso`, `list_disks`, `add_disk`,
  `list_nics`, `add_nic`, `set_boot`); power (`vm_power`,
  `get_vm_power`); guest (`get_guest_info`, `find_vm_ip`); console
  (`vm_screenshot`, `vm_send_keys`, `get_vm_question`,
  `answer_vm_question`, `vm_console_info`).
- Console via two transports: vim25 SOAP (CreateScreenshot_Task ->
  datastore download, PutUsbScanCodes) and WebMKS (AcquireTicket ->
  wss 'binary' -> RFB 3.8 Raw). `method=auto` prefers WebMKS with SOAP
  fallback.
- DANE-first TLS policy (TLSA 3 0/1 1/2, CA fallback, optional SHA-256
  leaf pin); credentials inline or via `~/.netrc`.
- Pending-VM-question detection on reconfigure timeouts
  (attach_iso/detach_iso point at get_vm_question/answer_vm_question).

### Notes

- Verified live against vCenter 8.0.3 (vcenter.example.com): full
  FreeBSD 15.1 VM provisioning and install via the console tools.
- Vendored library files pending upstream merge — see
  `docs/UPGRADING.md`: Enchilada/Extras PRs #50 (TLSA/rdata), #51
  (WebSocket SSL context), #52 (HTTP response headers), #53 (drain
  timeout + RFC 6455 GUID); Enchilada/Tortilla PR #6 (response headers).

