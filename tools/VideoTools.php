<?php
/**
 * vCenter MCP Server — Video Card Tools
 *
 * get_video/set_video for the VM's VirtualMachineVideoCard. The
 * vSphere REST API does not model the video card, so both go over
 * vim25 SOAP (config.hardware.device + ReconfigVM_Task device edit).
 * The REST API is used only for the power pre-check: video card edits
 * require the VM to be powered off.
 *
 * @package    VCenterMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpTool;
use VCenter\InstanceManager;
use VCenter\VCenterException;

class VideoTools
{
	private InstanceManager $manager;

	public function __construct(InstanceManager $manager)
	{
		$this->manager = $manager;
	}

	private function inst(string $instance): \VCenter\Instance
	{
		return $this->manager->instance($instance);
	}

	private function vmId(\VCenter\Instance $inst, string $vm): string
	{
		return $inst->inventory()->resolveVm($vm)['vm'];
	}

	#[McpTool(
		name: 'get_video',
		description: 'Get a VM\'s video card settings (auto-detect, video RAM, displays, 3D support). Read via vim25 SOAP; the REST API does not model the video card.',
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
	public function get_video(string $vm, string $instance = ''): array
	{
		$inst = $this->inst($instance);
		$id = $this->vmId($inst, $vm);
		return ['vm' => $id] + self::cardSummary($this->videoCard($inst, $id));
	}

	#[McpTool(
		name: 'set_video',
		description: 'Change a VM\'s video card settings via ReconfigVM_Task (device edit, vim25 SOAP). The VM must be powered off. use_auto_detect=true makes vCenter ignore video_ram_size_kb and num_displays, so those cannot be combined; returns the resulting settings.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'use_auto_detect' => ['type' => 'boolean', 'description' => 'VRAM/display sizing follows the host (mutually exclusive with video_ram_size_kb and num_displays)'],
				'video_ram_size_kb' => ['type' => 'integer', 'description' => 'Framebuffer size in KiB (requires use_auto_detect=false)'],
				'num_displays' => ['type' => 'integer', 'description' => 'Number of virtual monitors (requires use_auto_detect=false)'],
				'enable_3d_support' => ['type' => 'boolean', 'description' => 'Enable 3D acceleration'],
				'graphics_memory_size_kb' => ['type' => 'integer', 'description' => 'Graphics memory in KiB when 3D support is enabled'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
			'required' => ['vm'],
		]
	)]
	public function set_video(
		string $vm, ?bool $use_auto_detect = null, ?int $video_ram_size_kb = null,
		?int $num_displays = null, ?bool $enable_3d_support = null,
		?int $graphics_memory_size_kb = null, string $instance = ''
	): array {
		$inst = $this->inst($instance);
		$id = $this->vmId($inst, $vm);

		if ($use_auto_detect === null && $video_ram_size_kb === null && $num_displays === null
			&& $enable_3d_support === null && $graphics_memory_size_kb === null) {
			throw new VCenterException(
				'set_video: nothing to change (pass at least one of use_auto_detect, video_ram_size_kb, num_displays, enable_3d_support, graphics_memory_size_kb)',
				0, 'InvalidArgument'
			);
		}
		if ($use_auto_detect === true && ($video_ram_size_kb !== null || $num_displays !== null)) {
			throw new VCenterException(
				'use_auto_detect=true cannot be combined with video_ram_size_kb or num_displays — with auto-detect on, vCenter ignores explicit VRAM and display counts',
				0, 'InvalidArgument'
			);
		}

		// Video card edits require the VM powered off; check up front so
		// the caller gets a clean PowerStateError instead of a raw SOAP
		// InvalidPowerState fault midway through the reconfigure.
		$power = $inst->rest()->get("vcenter/vm/{$id}/power");
		$state = is_array($power) ? (string) ($power['state'] ?? '') : '';
		if ($state !== 'POWERED_OFF') {
			throw new VCenterException(
				"Video card settings can only be changed while the VM is powered off (VM {$id} is {$state})",
				0, 'PowerStateError'
			);
		}

		$card = $this->videoCard($inst, $id);

		// Switching auto-detect off without an explicit framebuffer would
		// leave the card without VRAM.
		if ($use_auto_detect === false && $video_ram_size_kb === null
			&& self::truthy($card['useAutoDetect'] ?? false)
			&& !isset($card['videoRamSizeInKB'])) {
			throw new VCenterException(
				'Video card currently uses auto-detect with no explicit VRAM — pass video_ram_size_kb when switching use_auto_detect=false',
				0, 'InvalidArgument'
			);
		}

		// Property order follows the vim25 VirtualMachineVideoCard
		// sequence: videoRamSizeInKB, numDisplays, useAutoDetect,
		// enable3DSupport, graphicsMemorySizeInKB.
		$fields = '';
		if ($video_ram_size_kb !== null) $fields .= '<videoRamSizeInKB>' . $video_ram_size_kb . '</videoRamSizeInKB>';
		if ($num_displays !== null) $fields .= '<numDisplays>' . $num_displays . '</numDisplays>';
		if ($use_auto_detect !== null) $fields .= '<useAutoDetect>' . ($use_auto_detect ? 'true' : 'false') . '</useAutoDetect>';
		if ($enable_3d_support !== null) $fields .= '<enable3DSupport>' . ($enable_3d_support ? 'true' : 'false') . '</enable3DSupport>';
		if ($graphics_memory_size_kb !== null) $fields .= '<graphicsMemorySizeInKB>' . $graphics_memory_size_kb . '</graphicsMemorySizeInKB>';

		$deviceChange = '<deviceChange><operation>edit</operation>'
			. '<device xsi:type="VirtualMachineVideoCard">'
			. '<key>' . (int) $card['key'] . '</key>'
			. $fields
			. '</device></deviceChange>';

		try {
			$task = $inst->soap()->reconfigVm(['type' => 'VirtualMachine', 'id' => $id], $deviceChange);
			$inst->tasks()->wait($task);
		} catch (VCenterException $e) {
			if (in_array($e->getErrorType(), ['InvalidPowerState', 'InvalidState'], true)) {
				throw new VCenterException(
					"Video card reconfigure of VM {$id} rejected in its current power state: " . $e->getMessage(),
					0, 'PowerStateError', $e
				);
			}
			throw $e;
		}

		return ['vm' => $id, 'updated' => true] + self::cardSummary($this->videoCard($inst, $id));
	}

	/**
	 * The VM's VirtualMachineVideoCard device entry (raw fields from
	 * config.hardware.device via the property collector).
	 *
	 * @throws VCenterException When the VM has no video card
	 */
	private function videoCard(\VCenter\Instance $inst, string $vmId): array
	{
		foreach ($inst->soap()->vmHardwareDevices($vmId) as $device) {
			if (($device['device'] ?? '') === 'VirtualMachineVideoCard') {
				return $device;
			}
		}
		throw new VCenterException("VM {$vmId} has no video card device", 0, 'NotFound');
	}

	/** Shape a raw device entry for tool output. */
	private static function cardSummary(array $card): array
	{
		return [
			'key' => (int) ($card['key'] ?? 0),
			'label' => (string) ($card['deviceInfo']['label'] ?? ''),
			'use_auto_detect' => self::truthy($card['useAutoDetect'] ?? false),
			'video_ram_size_kb' => isset($card['videoRamSizeInKB']) ? (int) $card['videoRamSizeInKB'] : null,
			'num_displays' => (int) ($card['numDisplays'] ?? 1),
			'enable_3d_support' => self::truthy($card['enable3DSupport'] ?? false),
			'graphics_memory_size_kb' => isset($card['graphicsMemorySizeInKB']) ? (int) $card['graphicsMemorySizeInKB'] : null,
		];
	}

	/** vim25 booleans arrive as the strings "true"/"false" when the leaf carries no xsi:type. */
	private static function truthy(mixed $v): bool
	{
		return $v === true || $v === 1 || $v === 'true' || $v === '1';
	}
}
