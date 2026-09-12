# Changelog

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

