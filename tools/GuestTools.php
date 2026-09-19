<?php
/**
 * vCenter MCP Server — Guest Tools
 *
 * @package    VCenterMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpTool;
use VCenter\GuestOperations;
use VCenter\InstanceManager;
use VCenter\VCenterException;

class GuestTools
{
	private InstanceManager $manager;

	public function __construct(InstanceManager $manager)
	{
		$this->manager = $manager;
	}

	/**
	 * Run a guest-ops callable, rephrasing the common GuestOperations
	 * faults into actionable messages (Tools not running, bad guest
	 * login, guest-side permission, logon rate limit).
	 */
	private function guestCall(callable $fn, string $guestUser): mixed
	{
		try {
			return $fn();
		} catch (VCenterException $e) {
			$type = $e->getErrorType();
			$map = [
				'GuestOperationsUnavailable' => 'VMware Tools is not running (or guest operations are unavailable) on this VM',
				'InvalidGuestLogin' => "guest authentication failed for '{$guestUser}' (InvalidGuestLogin)",
				'GuestPermissionDenied' => "guest user '{$guestUser}' lacks permission for this operation (GuestPermissionDenied)",
				'TooManyGuestLogons' => "too many concurrent guest logons for '{$guestUser}' (TooManyGuestLogons); retry shortly",
			];
			if (isset($map[$type])) {
				throw new VCenterException($map[$type] . ' — ' . $e->getMessage(), 0, $type, $e);
			}
			throw $e;
		}
	}

	/** Resolve the VM and build the common guest-ops call context. */
	private function guestContext(string $vm, string $instance, string $guestUser, string $guestPassword, bool $interactiveSession): array
	{
		$inst = $this->manager->instance($instance);
		$id = $inst->inventory()->resolveVm($vm)['vm'];
		return [
			$inst,
			['type' => 'VirtualMachine', 'id' => $id],
			GuestOperations::authXml($guestUser, $guestPassword, $interactiveSession),
			$id,
		];
	}

	#[McpTool(
		name: 'get_guest_info',
		description: 'Guest OS identity and network interfaces (requires VMware Tools; a clean message is returned when Tools is absent).',
		readOnlyHint: true,
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
			'required' => ['vm'],
		]
	)]
	public function get_guest_info(string $vm, string $instance = ''): array
	{
		$inst = $this->manager->instance($instance);
		$id = $inst->inventory()->resolveVm($vm)['vm'];

		try {
			$identity = $inst->rest()->get("vcenter/vm/{$id}/guest/identity");
		} catch (VCenterException $e) {
			if ($e->getCode() === 503 || str_contains($e->getMessage(), 'tools')) {
				return ['vm' => $id, 'tools_running' => false,
					'message' => 'VMware Tools is not running in the guest; identity and network info are unavailable.'];
			}
			throw $e;
		}

		$interfaces = null;
		try {
			$interfaces = $inst->rest()->get("vcenter/vm/{$id}/guest/networking/interfaces");
		} catch (VCenterException $e) {
			// interfaces are best-effort once identity succeeded
		}

		return ['vm' => $id, 'tools_running' => true, 'identity' => $identity, 'interfaces' => $interfaces];
	}

	#[McpTool(
		name: 'find_vm_ip',
		description: 'Guest IP addresses when VMware Tools reports them; otherwise the NIC MACs with guidance for finding IPs via DHCP leases or the console.',
		readOnlyHint: true,
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
			'required' => ['vm'],
		]
	)]
	public function find_vm_ip(string $vm, string $instance = ''): array
	{
		$inst = $this->manager->instance($instance);
		$id = $inst->inventory()->resolveVm($vm)['vm'];

		$addresses = [];
		$toolsOk = false;
		try {
			$interfaces = $inst->rest()->get("vcenter/vm/{$id}/guest/networking/interfaces");
			$toolsOk = true;
			foreach ((array) $interfaces as $iface) {
				foreach ((array) ($iface['ip']['ip_addresses'] ?? []) as $addr) {
					$addresses[] = [
						'nic' => $iface['nic'] ?? null,
						'mac' => $iface['mac_address'] ?? null,
						'address' => $addr['ip_address'] ?? $addr,
						'prefix' => $addr['prefix_length'] ?? null,
					];
				}
			}
		} catch (VCenterException $e) {
			$toolsOk = false;
		}

		if (!$toolsOk) {
			$macs = [];
			foreach ($inst->inventory()->vmDevices($id, 'ethernet') as $nic) {
				$macs[] = ['nic' => $nic['nic'] ?? null, 'mac' => $nic['mac_address'] ?? null];
			}
			return [
				'vm' => $id,
				'tools_running' => false,
				'macs' => $macs,
				'message' => 'VMware Tools is not reporting guest IPs. Match the NIC MACs against DHCP leases, or read addresses from the console (e.g. ifconfig).',
			];
		}

		return ['vm' => $id, 'tools_running' => true, 'addresses' => $addresses];
	}

	#[McpTool(
		name: 'guest_run',
		description: 'Run a program inside a guest via VMware Tools (vim25 guest operations) and wait for it to exit. Pass `command` for a /bin/sh command line (pipes/redirection allowed), or `program` + `arguments` for a direct binary (no shell interpretation). With capture_output (default) stdout+stderr are redirected in the guest and returned — capture needs /bin/sh, so it is POSIX guests only. Requires VMware Tools running and guest credentials.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'guest_username' => ['type' => 'string', 'description' => 'Guest OS username'],
				'guest_password' => ['type' => 'string', 'description' => 'Guest OS password'],
				'command' => ['type' => 'string', 'description' => 'Shell command line run via /bin/sh -c (mutually exclusive with program)'],
				'program' => ['type' => 'string', 'description' => 'Absolute path to a binary in the guest (mutually exclusive with command)'],
				'arguments' => ['type' => 'string', 'description' => 'Arguments for program (ignored when command is given)'],
				'working_directory' => ['type' => 'string', 'description' => 'Working directory inside the guest'],
				'env' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'Extra environment variables'],
				'capture_output' => ['type' => 'boolean', 'description' => 'Capture stdout+stderr (default true; POSIX guests only)'],
				'timeout' => ['type' => 'integer', 'description' => 'Seconds to wait for the process to exit (default 120)'],
				'interactive_session' => ['type' => 'boolean', 'description' => 'Run in an interactive guest session (Windows desktop)'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
			'required' => ['vm', 'guest_username', 'guest_password'],
		]
	)]
	public function guest_run(
		string $vm, string $guest_username, string $guest_password,
		?string $command = null, ?string $program = null, string $arguments = '',
		?string $working_directory = null, array $env = [],
		bool $capture_output = true, int $timeout = 120,
		bool $interactive_session = false, string $instance = ''
	): array {
		if (($command === null) === ($program === null)) {
			throw new VCenterException(
				'guest_run: pass exactly one of command (shell line) or program (binary path)',
				0, 'InvalidArgument'
			);
		}
		[$inst, $vmRef, $auth, $id] = $this->guestContext($vm, $instance, $guest_username, $guest_password, $interactive_session);
		$timeout = max(1, min($timeout, 3600));

		$cmdline = $command ?? $program . ($arguments !== '' ? ' ' . $arguments : '');
		$capturePath = $capture_output ? GuestOperations::capturePath() : null;
		if ($command !== null || $capture_output) {
			// Output capture (and any shell command line) needs a guest
			// shell: /bin/sh -c '{ cmd; } > /tmp/<n>.out 2>&1'. The brace
			// group keeps the redirect off the last &&/|| operand only.
			$shell = '{ ' . rtrim($cmdline, "; \t\n") . '; }'
				. ($capturePath !== null ? ' > ' . GuestOperations::shQuote($capturePath) . ' 2>&1' : '');
			$programPath = '/bin/sh';
			$arguments = '-c ' . GuestOperations::shQuote($shell);
		} else {
			$programPath = $program;
		}

		$result = $this->guestCall(
			fn() => $inst->guestOps()->run($vmRef, $auth, $programPath, $arguments, $capturePath, $timeout, $working_directory, $env),
			$guest_username
		);
		if (!$result['completed']) {
			$result['note'] = "process still running after {$timeout}s; check with guest_process_status (pid {$result['pid']})";
		}
		return ['vm' => $id] + $result;
	}

	#[McpTool(
		name: 'guest_process_status',
		description: 'List processes inside a guest via VMware Tools (optionally filtered to specific pids). Exited processes report exit_code and end_time.',
		readOnlyHint: true,
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'guest_username' => ['type' => 'string', 'description' => 'Guest OS username'],
				'guest_password' => ['type' => 'string', 'description' => 'Guest OS password'],
				'pids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Limit to these guest PIDs (default: all processes Tools reports)'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
			'required' => ['vm', 'guest_username', 'guest_password'],
		]
	)]
	public function guest_process_status(string $vm, string $guest_username, string $guest_password, array $pids = [], string $instance = ''): array
	{
		[$inst, $vmRef, $auth, $id] = $this->guestContext($vm, $instance, $guest_username, $guest_password, false);
		$processes = $this->guestCall(fn() => $inst->guestOps()->listProcesses($vmRef, $auth, $pids), $guest_username);
		return ['vm' => $id, 'processes' => $processes];
	}

	#[McpTool(
		name: 'guest_upload',
		description: 'Upload a file into a guest via VMware Tools. Content comes from exactly one of content (text), content_base64, or local_path (file on the MCP server). permissions sets POSIX mode bits as an integer (e.g. 420 for 0644, 384 for 0600).',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'guest_username' => ['type' => 'string', 'description' => 'Guest OS username'],
				'guest_password' => ['type' => 'string', 'description' => 'Guest OS password'],
				'guest_path' => ['type' => 'string', 'description' => 'Destination path inside the guest'],
				'content' => ['type' => 'string', 'description' => 'Text content to upload'],
				'content_base64' => ['type' => 'string', 'description' => 'Base64-encoded content to upload'],
				'local_path' => ['type' => 'string', 'description' => 'Path on the MCP server to read the content from'],
				'overwrite' => ['type' => 'boolean', 'description' => 'Overwrite an existing file (default true)'],
				'permissions' => ['type' => 'integer', 'description' => 'POSIX mode bits as an integer (e.g. 420 for 0644)'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
			'required' => ['vm', 'guest_username', 'guest_password', 'guest_path'],
		]
	)]
	public function guest_upload(
		string $vm, string $guest_username, string $guest_password, string $guest_path,
		?string $content = null, ?string $content_base64 = null, ?string $local_path = null,
		bool $overwrite = true, ?int $permissions = null, string $instance = ''
	): array {
		$sources = array_filter([$content, $content_base64, $local_path], fn($v) => $v !== null);
		if (count($sources) !== 1) {
			throw new VCenterException(
				'guest_upload: pass exactly one of content, content_base64, or local_path',
				0, 'InvalidArgument'
			);
		}
		if ($local_path !== null) {
			$data = @file_get_contents($local_path);
			if ($data === false) {
				throw new VCenterException("guest_upload: cannot read local file {$local_path}", 0, 'InvalidArgument');
			}
		} elseif ($content_base64 !== null) {
			$data = base64_decode($content_base64, true);
			if ($data === false) {
				throw new VCenterException('guest_upload: content_base64 is not valid base64', 0, 'InvalidArgument');
			}
		} else {
			$data = $content;
		}

		[$inst, $vmRef, $auth, $id] = $this->guestContext($vm, $instance, $guest_username, $guest_password, false);
		$written = $this->guestCall(
			fn() => $inst->guestOps()->uploadFile($vmRef, $auth, $guest_path, $data, $overwrite, $permissions),
			$guest_username
		);
		return ['vm' => $id, 'guest_path' => $guest_path, 'bytes_written' => $written];
	}

	#[McpTool(
		name: 'guest_download',
		description: 'Download a file from a guest via VMware Tools. Returns the content as text (or base64 when binary); with local_path the file is written on the MCP server instead.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'guest_username' => ['type' => 'string', 'description' => 'Guest OS username'],
				'guest_password' => ['type' => 'string', 'description' => 'Guest OS password'],
				'guest_path' => ['type' => 'string', 'description' => 'Path inside the guest to download'],
				'local_path' => ['type' => 'string', 'description' => 'Write the file to this path on the MCP server instead of returning it'],
				'max_bytes' => ['type' => 'integer', 'description' => 'Cap on returned content bytes (default 262144; ignored with local_path)'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
			'required' => ['vm', 'guest_username', 'guest_password', 'guest_path'],
		]
	)]
	public function guest_download(
		string $vm, string $guest_username, string $guest_password, string $guest_path,
		?string $local_path = null, int $max_bytes = GuestOperations::STDOUT_LIMIT, string $instance = ''
	): array {
		[$inst, $vmRef, $auth, $id] = $this->guestContext($vm, $instance, $guest_username, $guest_password, false);
		$file = $this->guestCall(fn() => $inst->guestOps()->downloadFile($vmRef, $auth, $guest_path), $guest_username);

		if ($local_path !== null) {
			if (@file_put_contents($local_path, $file['content']) === false) {
				throw new VCenterException("guest_download: cannot write local file {$local_path}", 0, 'WriteError');
			}
			return ['vm' => $id, 'guest_path' => $guest_path, 'local_path' => $local_path,
				'size' => $file['size'], 'bytes_written' => strlen($file['content'])];
		}

		$content = strlen($file['content']) > $max_bytes ? substr($file['content'], 0, $max_bytes) : $file['content'];
		// Valid UTF-8 alone is not enough — control bytes (NUL et al.) pass
		// that check but are never meaningful in a text payload.
		$isText = preg_match('//u', $content) === 1
			&& !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $content);
		return [
			'vm' => $id,
			'guest_path' => $guest_path,
			'size' => $file['size'],
			'encoding' => $isText ? 'text' : 'base64',
			'truncated' => strlen($file['content']) > $max_bytes,
			'content' => $isText ? $content : base64_encode($content),
		];
	}

	#[McpTool(
		name: 'guest_list_files',
		description: 'List files in a guest directory via VMware Tools. Directories report type=directory; large listings page via index/new_index.',
		readOnlyHint: true,
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'guest_username' => ['type' => 'string', 'description' => 'Guest OS username'],
				'guest_password' => ['type' => 'string', 'description' => 'Guest OS password'],
				'path' => ['type' => 'string', 'description' => 'Directory path inside the guest'],
				'match_pattern' => ['type' => 'string', 'description' => 'Filter (e.g. *.log)'],
				'index' => ['type' => 'integer', 'description' => 'Resume index from a previous call\'s new_index'],
				'max_results' => ['type' => 'integer', 'description' => 'Maximum entries to return'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
			'required' => ['vm', 'guest_username', 'guest_password', 'path'],
		]
	)]
	public function guest_list_files(
		string $vm, string $guest_username, string $guest_password, string $path,
		?string $match_pattern = null, ?int $index = null, ?int $max_results = null, string $instance = ''
	): array {
		[$inst, $vmRef, $auth, $id] = $this->guestContext($vm, $instance, $guest_username, $guest_password, false);
		$result = $this->guestCall(
			fn() => $inst->guestOps()->listFiles($vmRef, $auth, $path, $match_pattern, $index, $max_results),
			$guest_username
		);
		return ['vm' => $id, 'path' => $path] + $result;
	}
}
