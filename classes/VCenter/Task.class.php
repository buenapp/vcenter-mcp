<?php
/**
 * vCenter MCP Server — Task Wait
 *
 * Polls Task.info via RetrievePropertiesEx until the task reaches
 * success or error (or the wait budget expires).
 *
 * @package    VCenterMCP\VCenter
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace VCenter;

class Task
{
	private PropertyCollector $props;

	/** Poll interval in microseconds */
	private int $pollUs;

	public function __construct(PropertyCollector $props, int $pollUs = 250000)
	{
		$this->props = $props;
		$this->pollUs = $pollUs;
	}

	/**
	 * Wait for a vim25 task to finish.
	 *
	 * @param  array{type:string,id:string} $task       Task MoRef
	 * @param  int                          $timeoutSec Wait budget
	 * @param  callable|null                $sleep      Test seam: fn(int $us)
	 * @return array{state:string,result:mixed,progress:?int}
	 * @throws VCenterException On task error state or timeout
	 */
	public function wait(array $task, int $timeoutSec = 120, ?callable $sleep = null): array
	{
		$sleep = $sleep ?? fn(int $us) => usleep($us);
		$deadline = microtime(true) + $timeoutSec;

		while (true) {
			$info = $this->props->get('Task', $task['id'], ['info']);
			$info = $info['info'] ?? [];
			$state = (string) (is_array($info) ? ($info['state'] ?? '') : '');

			if ($state === 'success') {
				return [
					'state' => 'success',
					'result' => is_array($info) ? ($info['result'] ?? null) : null,
					'progress' => is_array($info) ? ($info['progress'] ?? null) : null,
				];
			}
			if ($state === 'error') {
				$error = is_array($info) ? ($info['error'] ?? null) : null;
				$message = 'Task failed';
				$faultType = null;
				if (is_array($error)) {
					$message = (string) ($error['localizedMessage'] ?? ($error['faultMessage'][0]['message'] ?? $message));
					$faultType = isset($error['fault']['type']) ? (string) $error['fault']['type'] : null;
				}
				throw new VCenterException("vCenter task {$task['id']} failed: {$message}", 0, $faultType ?? 'TaskError');
			}
			if (microtime(true) >= $deadline) {
				throw new VCenterException(
					"Timed out waiting {$timeoutSec}s for vCenter task {$task['id']} (last state: {$state})",
					0, 'TaskTimeout'
				);
			}
			$sleep($this->pollUs);
		}
	}
}
