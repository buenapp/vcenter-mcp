# vCenter MCP Server

An MCP (Model Context Protocol) server that exposes VMware vCenter to AI
agents over stdio. Built on the [Enchilada Framework](https://buenapp.org)
3.0 — PHP 8.4, no Composer.

## Features

- Multi-instance configuration (`config/instances.json`)
- Inventory browsing: datacenters, clusters, hosts, datastores, networks,
  folders, resource pools, datastore file listing
- VM lifecycle: list, inspect, create (with automatic host/datastore
  placement and host exclusion), delete, CPU/memory resize
- Device management: CD-ROM ISO attach/detach, disks, NICs, boot order
- Power control and guest OS information
- Console interaction over two transports: WebMKS (AcquireTicket ->
  wss -> RFB on the ESXi host) or vim25 SOAP — screenshots (PNG),
  keystrokes (X11 keysyms or USB HID), blocking VM questions;
  `method=auto` tries WebMKS and falls back to SOAP
- DANE-first TLS validation (TLSA records, CA fallback, optional
  SHA-256 certificate pin)
- Credentials inline or via `~/.netrc`

## Requirements

- PHP 8.4+ CLI with `curl` and `openssl` extensions
- A vCenter 7+/8 instance reachable over HTTPS

## Install

```sh
git clone https://pacyworld.dev/buenapp/vcenter-mcp.git
cd vcenter-mcp
cp config/instances.json.sample config/instances.json
# edit config/instances.json
php bin/vcenter-mcp --config=config/instances.json
```

Or download the self-contained `vcenter-mcp.phar` from a release.

### Quick start

Grab the vCenter CA bundle and configure an instance:

```sh
mkdir -p ~/.config/vcenter-mcp
curl -sko /tmp/vc-certs.zip 'https://<vcenter>/certs/download.zip'
unzip -o /tmp/vc-certs.zip -d /tmp/vc-certs
cat /tmp/vc-certs/certs/lin/*.0 /tmp/vc-certs/certs/lin/*.r0.crt > ~/.config/vcenter-mcp/ca-bundle.pem 2>/dev/null || cat /tmp/vc-certs/certs/lin/* > ~/.config/vcenter-mcp/ca-bundle.pem
```

`~/.config/vcenter-mcp/instances.json`:

```json
{
    "default": "main",
    "instances": {
        "main": {
            "url": "https://<vcenter>",
            "username": "user@vsphere.local",
            "password": "…",
            "tls": {"verify": true, "ca_cert": "~/.config/vcenter-mcp/ca-bundle.pem"}
        }
    }
}
```

REST/SSO usernames may need the `@vsphere.local` suffix. `password` may
be `null` with `"netrc": true` to read it from `~/.netrc` (`machine
<vcenter-host>`).

Register with an MCP host (Devin Desktop `~/.config/devin/mcp_config.json`):

```json
{"mcpServers": {"vcenter": {"command": "php", "args": ["/path/to/vcenter-mcp/bin/vcenter-mcp"], "env": {"VCENTER_MCP_CONFIG": "/home/<you>/.config/vcenter-mcp/instances.json"}}}}
```

### Tools (32)

| Area | Tools |
|---|---|
| Instance | `get_vcenter_info`, `list_vcenter_instances` |
| Inventory | `list_datacenters`, `list_clusters`, `list_hosts`, `list_datastores`, `list_networks`, `list_folders`, `list_resource_pools`, `list_vms`, `browse_datastore` |
| VM | `get_vm`, `create_vm`, `delete_vm`, `set_vm_hardware` |
| Devices | `list_cdroms`, `attach_iso`, `detach_iso`, `list_disks`, `add_disk`, `list_nics`, `add_nic`, `set_boot` |
| Power | `vm_power`, `get_vm_power` |
| Guest | `get_guest_info`, `find_vm_ip` |
| Console | `vm_screenshot`, `vm_send_keys`, `get_vm_question`, `answer_vm_question`, `vm_console_info` |

Install workflow notes: names like `Default`/`CDImages` can exist per
datacenter — pass `datacenter` or MoRef ids when ambiguous. After an OS
install, pick **Reboot in the installer first**, then `detach_iso` —
detaching while the live installer runs raises the "guest has locked
the CD-ROM door" question (answer with `answer_vm_question
choice=button.yes`) and then page-faults in init.

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
  datastore file upload, OVF import.

## License

BSD-2-Clause — Copyright (c) 2026, The Daniel Morante Company, Inc.
