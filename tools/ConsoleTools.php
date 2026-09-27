<?php
/**
 * vCenter MCP Server — Console Tools
 *
 * Console interaction over two transports: vim25 SOAP (CreateScreenshot_Task
 * + datastore download, PutUsbScanCodes) and WebMKS (AcquireTicket ->
 * wss -> RFB on the ESXi host). method='auto' tries WebMKS and falls
 * back to SOAP with a warning in the result.
 *
 * @package    VCenterMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpTool;
use EnchiladaMCP\ToolResult;
use VCenter\Console\Keysyms;
use VCenter\Console\WebMksClient;
use VCenter\Instance;
use VCenter\InstanceManager;
use VCenter\UsbScanCodes;
use VCenter\VCenterException;

class ConsoleTools
{
	private InstanceManager $manager;

	public function __construct(InstanceManager $manager)
	{
		$this->manager = $manager;
	}

	#[McpTool(
		name: 'vm_screenshot',
		description: 'Capture the VM console as PNG. webmks needs the ESXi host reachable; soap goes through vCenter; auto (default) tries webmks then soap.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'method' => ['type' => 'string', 'enum' => ['auto', 'soap', 'webmks']],
				'instance' => ['type' => 'string'],
			],
			'required' => ['vm'],
		]
	)]
	public function vm_screenshot(string $vm, string $method = 'auto', string $instance = ''): ToolResult
	{
		$inst = $this->manager->instance($instance);
		$vmId = $inst->inventory()->resolveVm($vm)['vm'];

		$fallback = null;
		if ($method === 'auto' || $method === 'webmks') {
			// A cached session can die mid-call: drop it and retry once
			// with a fresh ticket before giving up (auto falls back to SOAP).
			for ($attempt = 0; $attempt < 2; $attempt++) {
				try {
					$shot = $this->webMks($inst, $vmId)->screenshot();
					return ToolResult::mixed([
						['type' => 'image', 'data' => base64_encode($shot['png']), 'mimeType' => 'image/png'],
						['type' => 'text', 'text' => "{$shot['width']}x{$shot['height']} via webmks"],
					]);
				} catch (VCenterException $e) {
					$inst->dropWebMks($vmId);
					if ($attempt === 1) {
						if ($method === 'webmks') {
							throw $e;
						}
						$fallback = $e->getMessage();
					}
				}
			}
		}

		$shot = (new \VCenter\Screenshot($inst))->capture($vmId);
		$blocks = [
			['type' => 'image', 'data' => base64_encode($shot['png']), 'mimeType' => 'image/png'],
			['type' => 'text', 'text' => "{$shot['width']}x{$shot['height']} via soap"],
		];
		if ($fallback !== null) {
			$blocks[] = ['type' => 'text', 'text' => "webmks unavailable ({$fallback}); used soap"];
		}
		return ToolResult::mixed($blocks);
	}

	#[McpTool(
		name: 'vm_send_keys',
		description: 'Type text and/or press keys on the console of a powered-on VM (US layout). Sent in order: text, keys, then Enter if enter=true. method auto (default) tries webmks then soap (USB HID).',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'text' => ['type' => 'string', 'description' => 'Typed literally; \n = Enter, \t = Tab'],
				'keys' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Names (enter tab esc space backspace delete up down left right home end pageup pagedown insert f1..f12) or combos (ctrl-c, ctrl-alt-del, alt-f2, shift-tab)'],
				'enter' => ['type' => 'boolean', 'description' => 'Press Enter last'],
				'delay_ms' => ['type' => 'integer', 'description' => 'Between strokes/chunks (default 20)'],
				'method' => ['type' => 'string', 'enum' => ['auto', 'soap', 'webmks']],
				'instance' => ['type' => 'string'],
			],
			'required' => ['vm'],
		]
	)]
	public function vm_send_keys(
		string $vm,
		?string $text = null,
		?array $keys = null,
		bool $enter = false,
		int $delay_ms = 20,
		string $method = 'auto',
		string $instance = ''
	): array {
		if ($text === null && $keys === null && !$enter) {
			throw new VCenterException("Nothing to send: pass 'text' and/or 'keys' (or enter=true)", 0, 'MissingParam');
		}

		$inst = $this->manager->instance($instance);
		$vmId = $inst->inventory()->resolveVm($vm)['vm'];

		$power = $inst->rest()->get("vcenter/vm/{$vmId}/power");
		$state = is_array($power) ? ($power['state'] ?? null) : $power;
		if ($state !== 'POWERED_ON') {
			throw new VCenterException(
				"VM '{$vm}' is {$state}; console input requires POWERED_ON",
				0, 'VmPoweredOff'
			);
		}

		if ($method === 'auto' || $method === 'webmks') {
			$strokes = $this->keysymStrokes($text, $keys, $enter);
			for ($attempt = 0; $attempt < 2; $attempt++) {
				try {
					$sent = $this->webMks($inst, $vmId)->sendKeys($strokes, $delay_ms);
					return ['vm' => $vmId, 'sent' => $sent, 'chunks' => 1, 'method' => 'webmks'];
				} catch (VCenterException $e) {
					$inst->dropWebMks($vmId);
					if ($attempt === 1) {
						if ($method === 'webmks') {
							throw $e;
						}
						// fall through to SOAP for method=auto
					}
				}
			}
		}

		$events = $this->usbEvents($text, $keys, $enter);
		$chunks = array_chunk($events, 32);
		$sent = 0;
		foreach ($chunks as $i => $chunk) {
			if ($i > 0 && $delay_ms > 0) {
				usleep($delay_ms * 1000);
			}
			$sent += $inst->soap()->putUsbScanCodes(['type' => 'VirtualMachine', 'id' => $vmId], $chunk);
		}

		return ['vm' => $vmId, 'sent' => $sent, 'chunks' => count($chunks), 'method' => 'soap'];
	}

	#[McpTool(
		name: 'vm_console_info',
		description: 'Diagnose WebMKS console access: reports ESXi host/port, WebSocket subprotocol, TLS trust basis (DANE TLSA or ticket thumbprint) and framebuffer size.',
		readOnlyHint: true,
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'instance' => ['type' => 'string'],
			],
			'required' => ['vm'],
		]
	)]
	public function vm_console_info(string $vm, string $instance = ''): array
	{
		$inst = $this->manager->instance($instance);
		$vmId = $inst->inventory()->resolveVm($vm)['vm'];

		$client = $this->webMks($inst, $vmId);
		$info = ['vm' => $vmId] + $client->info();
		$inst->dropWebMks($vmId);
		return $info;
	}

	#[McpTool(
		name: 'get_vm_question',
		description: 'Get the VM\'s pending blocking question, if any: id, text and answer choices.',
		readOnlyHint: true,
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'instance' => ['type' => 'string'],
			],
			'required' => ['vm'],
		]
	)]
	public function get_vm_question(string $vm, string $instance = ''): array
	{
		$inst = $this->manager->instance($instance);
		$vmId = $inst->inventory()->resolveVm($vm)['vm'];

		$props = $inst->properties()->get('VirtualMachine', $vmId, ['runtime.question']);
		$question = $props['runtime.question'] ?? null;
		if (!is_array($question) || empty($question['id'])) {
			return ['vm' => $vmId, 'question' => null];
		}

		$choices = [];
		foreach ((array) ($question['choice']['choiceInfo'] ?? []) as $c) {
			if (is_array($c)) {
				$choices[] = ['key' => $c['key'] ?? '', 'label' => $c['label'] ?? ''];
			}
		}
		return [
			'vm' => $vmId,
			'question' => [
				'id' => $question['id'],
				'text' => $question['text'] ?? '',
				'choices' => $choices,
			],
		];
	}

	#[McpTool(
		name: 'answer_vm_question',
		description: 'Answer the VM\'s blocking question.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'choice' => ['type' => 'string', 'description' => 'Choice key or label (case-insensitive) from get_vm_question'],
				'instance' => ['type' => 'string'],
			],
			'required' => ['vm', 'choice'],
		]
	)]
	public function answer_vm_question(string $vm, string $choice, string $instance = ''): array
	{
		$inst = $this->manager->instance($instance);
		$vmId = $inst->inventory()->resolveVm($vm)['vm'];

		$q = $this->get_vm_question($vm, $instance);
		if ($q['question'] === null) {
			throw new VCenterException("VM '{$vm}' has no pending question", 0, 'NoQuestion');
		}

		$key = null;
		foreach ($q['question']['choices'] as $c) {
			if (strcasecmp((string) $c['key'], $choice) === 0 || strcasecmp((string) $c['label'], $choice) === 0) {
				$key = (string) $c['key'];
				break;
			}
		}
		if ($key === null) {
			$valid = implode(', ', array_map(fn($c) => "{$c['key']} ({$c['label']})", $q['question']['choices']));
			throw new VCenterException("Invalid choice '{$choice}'. Valid: {$valid}", 0, 'InvalidArgument');
		}

		$inst->soap()->answerVm(
			['type' => 'VirtualMachine', 'id' => $vmId],
			(string) $q['question']['id'],
			$key
		);
		return ['vm' => $vmId, 'answered' => $key];
	}

	// ── Internals ──────────────────────────────────────────────────

	/**
	 * Open (or reuse) the WebMKS session for a VM. Callers handle
	 * drop+retry — see the attempt loops above.
	 *
	 * @throws VCenterException
	 */
	private function webMks(Instance $inst, string $vmId): WebMksClient
	{
		return $inst->webMksSession($vmId);
	}

	/** Ordered USB HID events for the SOAP path. */
	private function usbEvents(?string $text, ?array $keys, bool $enter): array
	{
		$events = [];
		if ($text !== null && $text !== '') {
			$events = array_merge($events, UsbScanCodes::fromText($text));
		}
		if ($keys !== null) {
			$events = array_merge($events, UsbScanCodes::fromKeys($keys));
		}
		if ($enter) {
			$events = array_merge($events, UsbScanCodes::fromKeys(['enter']));
		}
		return $events;
	}

	/** Ordered key strokes (X11 keysyms) for the WebMKS path. */
	private function keysymStrokes(?string $text, ?array $keys, bool $enter): array
	{
		$strokes = [];
		if ($text !== null && $text !== '') {
			$strokes = array_merge($strokes, Keysyms::fromText($text));
		}
		if ($keys !== null) {
			$strokes = array_merge($strokes, Keysyms::fromKeys($keys));
		}
		if ($enter) {
			$strokes = array_merge($strokes, Keysyms::fromKeys(['enter']));
		}
		return $strokes;
	}
}
