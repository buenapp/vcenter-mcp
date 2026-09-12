<?php
/**
 * vCenter MCP Server — Console Screenshot
 *
 * SOAP screenshot pipeline: CreateScreenshot_Task on the VM -> wait ->
 * info.result is a datastore path "[ds] vm/vm-N.png" -> download via
 * /folder (dcPath + dsName from the VM's enclosing Datacenter) ->
 * best-effort DeleteDatastoreFile_Task. Dimensions come from the PNG
 * IHDR — no GD required.
 *
 * @package    VCenterMCP\VCenter
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace VCenter;

class Screenshot
{
	private Instance $instance;

	/** @var callable|null function(string): void — log sink for best-effort cleanup */
	private $log;

	public function __construct(Instance $instance, ?callable $log = null)
	{
		$this->instance = $instance;
		$this->log = $log;
	}

	/**
	 * Capture the VM console as PNG.
	 *
	 * @param  string $vmId VM MoRef id (e.g. "vm-42")
	 * @return array{png:string,width:int,height:int}
	 * @throws VCenterException
	 */
	public function capture(string $vmId): array
	{
		$vm = ['type' => 'VirtualMachine', 'id' => $vmId];
		$soap = $this->instance->soap();

		$task = $soap->createScreenshot($vm);
		$done = $this->instance->tasks()->wait($task);

		$path = (string) ($done['result'] ?? '');
		if ($path === '' || !str_starts_with($path, '[')) {
			throw new VCenterException(
				"Screenshot task returned no datastore path (got: " . json_encode($done['result']) . ')',
				0, 'Screenshot'
			);
		}
		$dsName = substr($path, 1, strpos($path, ']') - 1);

		$dc = $this->instance->properties()->datacenterOf($vm);

		$png = $soap->downloadDatastoreFile($path, $dc['name'], $dsName);

		try {
			$deleteTask = $soap->deleteDatastoreFile($path, $dc);
			$this->instance->tasks()->wait($deleteTask);
		} catch (\Throwable $e) {
			$this->emit("Screenshot cleanup: could not delete {$path}: {$e->getMessage()}");
		}

		[$width, $height] = self::pngSize($png);
		return ['png' => $png, 'width' => $width, 'height' => $height];
	}

	/**
	 * Width/height from a PNG IHDR (bytes 16..24, big-endian).
	 *
	 * @return array{0:int,1:int}
	 * @throws VCenterException When the payload is not a PNG
	 */
	public static function pngSize(string $png): array
	{
		if (strlen($png) < 24 || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n" || substr($png, 12, 4) !== 'IHDR') {
			throw new VCenterException('Screenshot payload is not a PNG', 0, 'Screenshot');
		}
		$dims = unpack('Nwidth/Nheight', substr($png, 16, 8));
		return [$dims['width'], $dims['height']];
	}

	private function emit(string $message): void
	{
		if ($this->log !== null) {
			try {
				($this->log)($message);
			} catch (\Throwable $e) {
				// logging must never break the operation it serves
			}
		}
	}
}
