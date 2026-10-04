# vCenter MCP Server

An MCP (Model Context Protocol) server that exposes VMware vCenter to AI
agents over stdio. Built on the [Enchilada Framework](https://buenapp.org)
3.0 — PHP 8.4, no Composer.

## Features

- Multi-instance configuration (`config/instances.json`)
- Inventory browsing: datacenters, clusters, hosts, datastores, networks,
  folders, resource pools, datastore file listing
- VM lifecycle: list, inspect, create (with automatic host/datastore
  placement, host exclusion, and optional hardware version), delete,
  CPU/memory resize
- Device management: CD-ROM ISO attach/detach, disks, NICs, boot order,
  video card (VRAM auto-detect, displays, 3D support)
- Power control and guest OS information
- Console interaction over two transports: WebMKS (AcquireTicket ->
  wss -> RFB on the ESXi host) or vim25 SOAP — screenshots (PNG),
  keystrokes (X11 keysyms or USB HID), blocking VM questions;
  `method=auto` tries WebMKS and falls back to SOAP
- Relaxed TLS by default (vCenter certs are VMCA-issued/self-signed);
  opt-in DANE-first validation (TLSA records, CA fallback, optional
  SHA-256 certificate pin) via `tls.verify`
- Credentials inline or via `~/.netrc`

## Requirements

- PHP 8.4+ CLI with `curl` and `openssl` extensions
- A vCenter 7+/8 instance reachable over HTTPS

## Install

Download the self-contained `vcenter-mcp.phar` from the
[latest release](https://github.com/buenapp/vcenter-mcp/releases/latest)
(no Composer, no dependencies):

```sh
mkdir -p ~/.local/bin
curl -L -o ~/.local/bin/vcenter-mcp.phar \
  https://github.com/buenapp/vcenter-mcp/releases/latest/download/vcenter-mcp.phar
chmod +x ~/.local/bin/vcenter-mcp.phar
```

To run from source instead:

```sh
git clone https://pacyworld.dev/buenapp/vcenter-mcp.git
cd vcenter-mcp
php bin/vcenter-mcp --config=config/instances.json
```

### Quick start

`~/.config/vcenter-mcp/instances.json`:

```json
{
    "default": "main",
    "instances": {
        "main": {
            "url": "https://<vcenter>",
            "username": "user@vsphere.local",
            "password": "…"
        }
    }
}
```

Certificate verification is off by default — vCenter certs are
VMCA-issued or self-signed and never chain to a standard trust store.
To verify the peer, set `"tls": {"verify": true}`: the policy is
DANE-first (TLSA records), falls back to the CA store, and supports
`"ca_cert"` (path to a CA bundle, e.g. the one under
`https://<vcenter>/certs/download.zip`) and `"thumbprint"`
(SHA-256 leaf pin).

REST/SSO usernames may need the `@vsphere.local` suffix. `password` may
be `null` with `"netrc": true` to read it from `~/.netrc` (`machine
<vcenter-host>`).

Register with an MCP host (Devin Desktop `~/.config/devin/mcp_config.json`):

```json
{"mcpServers": {"vcenter": {"command": "php", "args": ["/home/<you>/.local/bin/vcenter-mcp.phar", "--config=/home/<you>/.config/vcenter-mcp/instances.json"]}}}
```

### Tools (48)

| Area | Tools |
|---|---|
| Instance | `get_vcenter_info`, `list_vcenter_instances` |
| Inventory | `list_datacenters`, `list_clusters`, `list_hosts`, `list_datastores`, `list_networks`, `list_folders`, `list_resource_pools`, `list_vms`, `browse_datastore`, `read_datastore_file` |
| VM | `get_vm`, `create_vm`, `delete_vm`, `set_vm_hardware` |
| Devices | `list_cdroms`, `attach_iso`, `detach_iso`, `list_disks`, `add_disk`, `set_disk`, `list_controllers`, `list_nics`, `add_nic`, `set_boot` |
| Passthrough | `list_host_pci`, `list_passthrough_devices`, `attach_pci`, `detach_pci` |
| Images | `ovf_info`, `deploy_ova` |
| Video | `get_video`, `set_video` |
| Power | `vm_power`, `get_vm_power` |
| Guest | `get_guest_info`, `find_vm_ip`, `guest_run`, `guest_process_status`, `guest_upload`, `guest_download`, `guest_list_files` |
| Console | `vm_screenshot`, `vm_send_keys`, `get_vm_question`, `answer_vm_question`, `vm_console_info` |

Install workflow notes: names like `Default`/`CDImages` can exist per
datacenter — pass `datacenter` or MoRef ids when ambiguous. After an OS
install, pick **Reboot in the installer first**, then `detach_iso` —
detaching while the live installer runs raises the "guest has locked
the CD-ROM door" question (answer with `answer_vm_question
choice=button.yes` — `detach_iso` already auto-answers and retries
once) and then page-faults in init.

Storage notes: `list_disks`/`list_controllers` report SCSI/SATA/NVMe
controller types, unit numbers, disk mode and thin/thick via vim25
(REST does not model these). `add_disk` with `controller_type` /
`disk_mode` / `thin` goes through ReconfigVM_Task with
`fileOperation=create` (hot-plug capable; a new controller can be
created in the same call but requires the VM powered off). `set_disk`
changes only the disk mode and also requires power-off — vCenter
silently ignores `thinProvisioned` on backing edits, so thin/thick is
chosen when the VMDK is created. `create_vm` accepts `controllers`
(one adapter type per bus, overrides `scsi_type`) and `disk_mode`
(post-create vim25 edit of the boot disk).

Guest operations (`guest_run`, `guest_upload`, ...) bypass the
**network**, not **authentication** — they still need valid credentials
inside the guest, and VMware Tools must already be running
(`open-vm-tools` on FreeBSD). Guest credential checks go through PAM
under the `vmtoolsd` service name, so FreeBSD guests need a matching
service file (e.g. `/etc/pam.d/vmtoolsd` with `auth include system` /
`account include system`) or logins fail with `InvalidGuestLogin`.
They do not replace the console for the earliest bootstrap cases (no
known local password, boot loader, single-user mode): Tools is not
running that early, so `vm_send_keys` remains the only tool there.
`guest_run` output capture wraps the command in `/bin/sh`, so capture
is POSIX guests only; use `capture_output=false` with an explicit
`program` elsewhere.

Passthrough: `list_host_pci` reads DirectPath state per host
(`config.pciPassthruInfo` + `hardware.pciDevice`); pass
`capable_only=false` for the full PCI table. `attach_pci` /
`detach_pci` go through ReconfigVM_Task with a
`VirtualPCIPassthroughDynamicBackingInfo`, default the device host to
the VM's runtime host, refuse devices already attached to another VM
on that host, and require the VM powered off (no hot-plug for
DirectPath). Attaching locks all guest memory
(`memoryReservationLockedToMax`), as DirectPath requires. Host
exclusions guard VM placement only, so GPU hosts kept out of placement
can still be listed and targeted.

OVA deploy: `ovf_info` parses the descriptor of a datastore or
http(s) `.ova`/`.ovf` and reports networks (the `network_mappings`
keys), vApp properties, disks and EULAs. `deploy_ova` imports the
package into the content library (`vcenter-mcp-staging`, created on
the target datastore, persisted between runs; an instance default
`content_library` renames it) with a server-side PULL: from an http(s)
URL, or from a datastore via its `ds:///vmfs/volumes/<uuid>` URI —
no bulk bytes cross this server. Only tar `.ova` packages can be
imported (a bare `.ovf` references siblings the library cannot pull);
bare `.ovf` sources work for `ovf_info` only. The deploy call itself
is synchronous and blocks until the VM's disks are copied; the staged
library item is deleted afterwards, failed transfers also cancel their
update session and delete their item.

See `docs/SETUP.md` for configuration, `docs/ARCHITECTURE.md` for
internals and `docs/UPGRADING.md` for vendored-library provenance. The
design plan is `docs/PLAN.md`.

## Roadmap

- **MCP prompt templates** — the highest-value next step. Ship
  ready-made provisioning workflows, starting with a FreeBSD guest
  preset: EFI boot, automatic video RAM, 10 GB root volume, 2 vCPUs,
  4 GB RAM — create, boot the ISO, and hand back console access in one
  shot.
- **MCP resources** — expose vCenter objects as resource URIs so an
  agent can read state without tool calls. Still deciding which
  surfaces make sense (VM inventory and power state are the obvious
  candidates; console frames and datastore listings are open
  questions).
- **DANE-TA (usage 2) TLSA validation** — v0.1 handles DANE-EE only;
  usage-2 records are logged and skipped.
- **HTTP/SSE transport** — stdio only today, same as the other
  Enchilada MCP servers at launch.
- **Under consideration** — VM snapshots, clone/template deploy,
  datastore file upload.

## License

BSD-2-Clause — Copyright (c) 2026, The Daniel Morante Company, Inc.
