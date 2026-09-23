# Changelog

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

