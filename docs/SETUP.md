# vCenter MCP Server — Setup

## Configuration

Copy `config/instances.json.sample` to `config/instances.json`:

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

Fields:

- `url` — vCenter base URL (HTTPS).
- `username` / `password` — SSO credentials. Set `password` to `null`
  and `netrc` to `true` to read it from `~/.netrc` (`machine <host>`).
- `tls.verify` — enable certificate verification (DANE TLSA first, then
  the CA store). `tls.ca_cert` points at a CA bundle override;
  `tls.thumbprint` pins the leaf certificate (SHA-256, colon-hex).
- `exclude_hosts` — hostnames (FQDN, case-insensitive) that must never
  receive new VM placements.
- `defaults` — inventory defaults used when tool parameters are omitted.

## Running

```sh
php bin/vcenter-mcp --config=config/instances.json
```

Environment variables: `VCENTER_MCP_CONFIG`, `VCENTER_MCP_LOG`,
`VCENTER_MCP_LOG_LEVEL`, `VCENTER_MCP_LOG_STDERR`, `VCENTER_MCP_IO_MODE`
(auto|reactor|blocking).

## MCP client registration

Point your MCP host at the binary, e.g.:

```json
{"mcpServers": {"vcenter": {"command": "php", "args": ["/path/to/bin/vcenter-mcp"]}}}
```

## PHAR

```sh
php -d phar.readonly=0 bin/build-phar.php
./vcenter-mcp.phar --config=/path/to/instances.json
```

## Tests

```sh
phpunit
```

All tests are offline; the HTTP layer is faked at the client seam.
