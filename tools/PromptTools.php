<?php
/**
 * vCenter MCP Server — Prompt Tools
 *
 * MCP prompt templates for repeat deployments. `deploy_vm` renders the
 * estate's standard FreeBSD service-VM playbook (see Heliofane entity
 * "FreeBSD Service VM Standard Spec (morante estate)") with the caller's
 * sizing, so any MCP client can run the same provisioning flow verbatim.
 *
 * @package    VCenterMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpComplete;
use EnchiladaMCP\McpPrompt;
use VCenter\InstanceManager;

class PromptTools
{
	private InstanceManager $manager;

	public function __construct(InstanceManager $manager)
	{
		$this->manager = $manager;
	}

	#[McpPrompt(
		name: 'deploy_vm',
		title: 'Deploy FreeBSD service VM',
		description: 'Provision a VM to the estate FreeBSD service-VM spec: LSI boot volume, PVSCSI independent_persistent ZFS data volumes, vRAM auto-detect, EFI, FreeBSD install + provisioning script.',
		arguments: [
			['name' => 'name', 'description' => 'VM name and hostname', 'required' => true],
			['name' => 'cpu', 'description' => 'vCPU count (default 2)'],
			['name' => 'memory_gib', 'description' => 'Memory in GiB (default 4)'],
			['name' => 'zfs_volumes', 'description' => 'Number of ZFS data volumes (default 2)'],
			['name' => 'zfs_gib', 'description' => 'Size of each ZFS volume in GiB (default 40)'],
			['name' => 'network', 'description' => 'Portgroup (default: instance default network)'],
			['name' => 'datacenter', 'description' => 'Datacenter name or id'],
			['name' => 'iso', 'description' => 'Install ISO datastore path (default: FreeBSD 15.1 bootonly on CDImages)'],
		]
	)]
	public function deployVm(
		string $name,
		string $cpu = '2',
		string $memory_gib = '4',
		string $zfs_volumes = '2',
		string $zfs_gib = '40',
		string $network = '',
		string $datacenter = '',
		string $iso = "'[CDImages] FreeBSD OS/FreeBSD-15.1-RELEASE-amd64-bootonly.iso'"
	): array {
		$memoryMib = max(1, (int) $memory_gib) * 1024;
		$netClause = $network !== '' ? ", network='{$network}'" : '';
		$dcClause = $datacenter !== '' ? ", datacenter='{$datacenter}'" : '';
		$diskCalls = '';
		for ($i = 1; $i <= max(1, (int) $zfs_volumes); $i++) {
			$diskCalls .= "\n   - add_disk(vm='{$name}', size_gib={$zfs_gib}, controller_type='paravirtual', thin=true, disk_mode='independent_persistent')";
		}

		$text = <<<EOF
Deploy a new estate FreeBSD service VM named '{$name}'.

VIRTUAL HARDWARE (vcenter-mcp tools):
1. create_vm(name='{$name}', cpu={$cpu}, memory_mib={$memoryMib}, disk_gib=10, guest_os='FREEBSD_14_64', firmware='EFI' (the default), nic_type='VMXNET3' (the default), version='VMX_21', controllers=['LSILOGICSAS', 'PVSCSI']{$netClause}{$dcClause})
   - controllers uses vSphere type names (LSILOGICSAS, PVSCSI) — unlike add_disk's controller_type vocabulary ('lsilogic-sas', 'paravirtual').
   - Afterwards verify with list_disks that the 10 GiB boot disk hangs off the LSILOGICSAS adapter (bus 0). If vCenter bound it to PVSCSI instead, that is a create_vm gap — file/extend issue #6 rather than working around it.
2. While still powered off: set_video(vm='{$name}', use_auto_detect=true) — vRAM auto-detection is required by the spec.
3. ZFS data volumes, one call per volume:{$diskCalls}
   - ZFS data rides ONLY the PVSCSI adapter, disk_mode='independent_persistent' (keeps the pool out of vCenter snapshots — quiesced snapshots of a live ZFS pool are inconsistent), always thin.
4. attach_iso(vm='{$name}', iso={$iso}) then vm_power(vm='{$name}', action='on').
   - If attach_iso fails with a 500, the VM has no SATA adapter (create_vm with the controllers list skips the default AHCI/CD-ROM; issue #9). Fix once via REST: POST /api/vcenter/vm/<id>/hardware/adapter/sata {} then retry attach_iso.
   - Power on immediately after attach_iso: the tool sets start_connected, and the install boots from the ISO.

OS INSTALL (vm_screenshot/vm_send_keys through the bsdinstall console):
- PREFERRED: scripted bsdinstall. From the installer's Shell dialog, write /tmp/installerconfig (must NOT start with #! — the first #! line starts the post-install script; see FreeBSD 15.1 scripted-install gotchas in memory):
      DISTRIBUTIONS="base.txz kernel.txz"
      BSDINSTALL_DISTSITE="http://download.morante.org/releases/amd64/amd64/15.1-RELEASE"
      PARTITIONS="da0"
      #!/bin/sh
      sysrc hostname="{$name}"; sysrc ifconfig_vmx0="DHCP"; sysrc sshd_enable="YES"
      sysrc moused_enable="YES"; sysrc local_unbound_enable="NO"
      echo <rootpw-from-multipass> | pw usermod root -h 0
      pw useradd -n admin -s /bin/sh -m -c 'Admin'; echo <same pw> | pw usermod admin -h 0; pw usermod admin -G 'wheel operator'
  then `bsdinstall script /tmp/installerconfig`. Never redirect bsdinstall output; dialogs render to stdout.
- INTERACTIVE alternative: kmap Enter, distribution type = Distribution Sets — note this path has NO component chooser in 15.1 and fetches base+kernel+kernel-dbg+lib32; strip debug/lib32 afterwards and do not present them as installed. Partitioning: Auto (UFS), GPT, Entire Disk on the LSI disk (da0). If the fetch stalls on resolver errors, the bsdinstall startup wiped /tmp/bsdinstall_etc/resolv.conf (symlinked from /etc) — restart via Shell: dhclient vmx0; then resume.
- Networking resolves to DHCP IPv4 (192.168.1.0/24, resolver 192.168.1.10); never IPv6.
- Pwr: on 'Installation Complete' reboot FIRST, then detach_iso. vm_power on, then find_vm_ip.
- Post: ssh admin@<ip> is password-only; root password login is disabled by sshd — use su -, then run the provisioning fetch line below.

POST-PROVISION:
- ssh in and become root; run: fetch -o - http://download.morante.net/unibia/freebsd/vmware/autoprovision_server.sh | sh
  It chains minimal-server-setup.sh (root mail alias -> daniel@morante.net, estate root pubkey, observability auto-config, PTI/IBRS/MDS mitigations off) and reboots. Do NOT hand-edit sshd_config — the script handles root login.
- Afterwards append your own SSH public key to /root/.ssh/authorized_keys.

If any step exceeds what the vcenter-mcp tools can express, STOP and file a feature request (https://pacyworld.dev/buenapp/vcenter-mcp/issues) instead of working around it. Known gaps: create_vm lacks vram_auto_detect and a thin flag for the boot disk (issue #6).
EOF;

		return [[
			'role' => 'user',
			'content' => ['type' => 'text', 'text' => $text],
		]];
	}

	#[McpComplete(refType: 'ref/prompt', refName: 'deploy_vm', argument: 'datacenter')]
	public function completeDatacenter(string $value, array $context): array
	{
		return $this->completeNames($value, true);
	}

	#[McpComplete(refType: 'ref/prompt', refName: 'deploy_vm', argument: 'network')]
	public function completeNetwork(string $value, array $context): array
	{
		return $this->completeNames($value, false);
	}

	/**
	 * Datacenter/portgroup name completion from live inventory. Never
	 * errors — clients call completion on every keystroke, so an
	 * unreachable vCenter just means no suggestions.
	 */
	private function completeNames(string $value, bool $datacenter): array
	{
		try {
			$inventory = $this->manager->instance('')->inventory();
			$rows = $datacenter ? $inventory->listDatacenters() : $inventory->listNetworks();
			$names = array_values(array_map(
				fn(array $row): string => (string) ($row['name'] ?? ''),
				array_filter($rows, 'is_array')
			));
			return array_values(array_filter(
				$names,
				fn(string $n): bool => $n !== '' && stripos($n, $value) !== false
			));
		} catch (\Throwable $e) {
			return [];
		}
	}

	#[McpComplete(refType: 'ref/prompt', refName: 'deploy_vm', argument: 'iso')]
	public function completeIso(string $value, array $context): array
	{
		$candidates = [
			'[CDImages] FreeBSD OS/FreeBSD-15.1-RELEASE-amd64-bootonly.iso',
			'[CDImages] FreeBSD OS/FreeBSD-15.1-RELEASE-amd64-disc1.iso',
			'[CDImages] FreeBSD OS/FreeBSD-15.1-RELEASE-amd64-dvd1.iso',
		];
		return array_values(array_filter($candidates, fn(string $c) => $value === '' || str_contains($c, $value)));
	}
}
