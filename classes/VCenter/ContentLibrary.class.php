<?php
/**
 * vCenter MCP Server -- Content Library Facade
 *
 * Minimal wrapper over the /api/content REST surface needed to stage an
 * OVA into a local library item and deploy it: library find-or-create,
 * item create/delete, update sessions with PULL file transfer (vCenter
 * fetches ds:/// or http(s):// sources server-side, so no bulk bytes
 * cross this server), and the synchronous ovf/library-item deploy.
 *
 * @package    VCenterMCP\VCenter
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace VCenter;

class ContentLibrary
{
	/** File states an update session reports while a transfer is open. */
	private const FILE_PENDING_STATES = ['UNSET', 'WAITING_FOR_TRANSFER', 'TRANSFERRING', 'VALIDATING'];

	private Instance $instance;

	/** Poll interval in microseconds for transfer waits */
	private int $pollUs = 500000;

	public function __construct(Instance $instance)
	{
		$this->instance = $instance;
	}

	/**
	 * Id of the staging library holding imported OVAs. Created on the
	 * given datastore when missing. Name comes from the instance
	 * defaults ('content_library'), defaulting to 'vcenter-mcp-staging'.
	 */
	public function stagingLibrary(string $datastoreId): string
	{
		$name = (string) ($this->instance->defaults()['content_library'] ?? 'vcenter-mcp-staging');
		foreach ($this->instance->rest()->get('content/library') ?? [] as $libId) {
			$lib = $this->instance->rest()->get('content/library/' . rawurlencode((string) $libId));
			if (is_array($lib) && ($lib['name'] ?? null) === $name) {
				return (string) ($lib['id'] ?? $libId);
			}
		}
		// 8.0.3 takes the model directly; a create_spec wrapper is rejected
		$created = $this->instance->rest()->post('content/local-library', [
			'name' => $name,
			'description' => 'Staging area for vcenter-mcp OVA deploys; items are deleted after each deploy.',
			'storage_backings' => [
				['datastore_id' => $datastoreId, 'type' => 'DATASTORE'],
			],
		]);
		if (!is_string($created)) {
			throw new VCenterException('Local content library create returned no id', 0, 'LibraryError');
		}
		return $created;
	}

	/** Create an empty OVF library item; returns the item id. */
	public function createItem(string $libraryId, string $name): string
	{
		$id = $this->instance->rest()->post('content/library/item', [
			'library_id' => $libraryId,
			'name' => $name,
			'type' => 'ovf',
		]);
		if (!is_string($id)) {
			throw new VCenterException('Library item create returned no id', 0, 'LibraryError');
		}
		return $id;
	}

	/** Best-effort item cleanup after a deploy. */
	public function deleteItem(string $itemId): void
	{
		try {
			$this->instance->rest()->delete('content/library/item/' . rawurlencode($itemId));
		} catch (VCenterException $e) {
			// best-effort: the staged item stays browseable in the library
		}
	}

	/** Open an update session on a library item; returns the session id. */
	public function openUpdateSession(string $itemId): string
	{
		$id = $this->instance->rest()->post('content/library/item/update-session', [
			'library_item_id' => $itemId,
		]);
		if (!is_string($id)) {
			throw new VCenterException('Update session create returned no id', 0, 'LibraryError');
		}
		return $id;
	}

	/** Best-effort session cancel (failure paths before complete). */
	public function cancelSession(string $sessionId): void
	{
		try {
			$this->instance->rest()->post(
				'content/library/item/update-session/' . rawurlencode($sessionId), null, ['action' => 'cancel']);
		} catch (VCenterException $e) {
			// best-effort
		}
	}

	/**
	 * Have vCenter pull a file into the session. Handles both a remote
	 * URL and a ds:/// datastore URI, so the OVA never crosses this
	 * server. The updatesession/file resource only exists in the legacy
	 * /rest flavor on 8.0 (verified against 8.0.3).
	 *
	 * @throws VCenterException
	 */
	public function addPullFile(string $sessionId, string $fileName, string $uri, ?string $thumbprint = null): void
	{
		$endpoint = ['uri' => $uri];
		if ($thumbprint !== null) {
			$endpoint['ssl_certificate_thumbprint'] = $thumbprint;
		}
		$this->legacy(
			'rest/com/vmware/content/library/item/updatesession/file/id:' . $sessionId . '?~action=add', [
				'file_spec' => [
					'name' => $fileName,
					'source_type' => 'PULL',
					'source_endpoint' => $endpoint,
				],
			]);
	}

	/**
	 * Wait until every file in the session is READY.
	 *
	 * @return array<int,array> UpdateSession file entries
	 * @throws VCenterException On a terminal file error or timeout
	 */
	public function waitFilesReady(string $sessionId, int $timeoutSec = 3600): array
	{
		$deadline = microtime(true) + $timeoutSec;
		while (true) {
			$files = $this->legacy(
				'rest/com/vmware/content/library/item/updatesession/file?~action=list',
				['update_session_id' => $sessionId]) ?? [];
			if (!is_array($files)) {
				$files = [];
			}
			$done = count($files) > 0;
			foreach ($files as $file) {
				$status = (string) (is_array($file) ? ($file['status'] ?? '') : '');
				if ($status === 'ERROR') {
					$message = is_array($file['error_message'] ?? null)
						? (string) ($file['error_message']['default_message'] ?? 'transfer error')
						: 'transfer error';
					throw new VCenterException(
						"Library pull of " . (string) ($file['name'] ?? '?') . " failed: {$message}",
						0, 'Transfer'
					);
				}
				if (in_array($status, self::FILE_PENDING_STATES, true)) {
					$done = false;
				}
			}
			if ($done) {
				return $files;
			}
			if (microtime(true) >= $deadline) {
				throw new VCenterException(
					"Timed out waiting {$timeoutSec}s for library transfer of session {$sessionId}",
					0, 'TaskTimeout'
				);
			}
			usleep($this->pollUs);
		}
	}

	/**
	 * Legacy /rest/com/vmware/... call: the payload is wrapped in a
	 * "value" field, which this unwraps.
	 */
	private function legacy(string $endpoint, ?array $body): mixed
	{
		$response = $this->instance->rest()->post($endpoint, $body);
		if (is_array($response) && array_key_exists('value', $response)
			&& (count($response) === 1 || !array_key_exists('type', $response))) {
			return $response['value'];
		}
		return $response;
	}

	/** Apply the session (imports the pulled files into the item). */
	public function completeSession(string $sessionId): void
	{
		$this->instance->rest()->post(
			'content/library/item/update-session/' . rawurlencode($sessionId), null, ['action' => 'complete']);
	}

	/**
	 * Filter/validate an OVF library item against a target; returns the
	 * raw filter response (networks, errors, warnings, information).
	 */
	public function filterOvf(string $itemId, array $target): array
	{
		$result = $this->instance->rest()->post(
			'vcenter/ovf/library-item/' . rawurlencode($itemId),
			['target' => $target],
			['action' => 'filter']
		);
		return is_array($result) ? $result : [];
	}

	/**
	 * Deploy an OVF library item. The deploy call is synchronous and
	 * blocks for the whole disk copy, hence the long default timeout.
	 *
	 * @param  array{resource_pool_id:string,host_id?:string,folder_id?:string} $target
	 * @param  array<string,mixed> $spec  deployment_spec payload
	 * @return array<string,mixed> Deploy response ('succeeded', 'resource_id', ...)
	 * @throws VCenterException When vCenter reports a failed deployment
	 */
	public function deployOvf(string $itemId, array $target, array $spec, int $timeoutSec = 3600): array
	{
		$result = $this->instance->rest()->post(
			'vcenter/ovf/library-item/' . rawurlencode($itemId),
			['target' => $target, 'deployment_spec' => $spec],
			['action' => 'deploy'],
			$timeoutSec
		);
		if (!is_array($result)) {
			throw new VCenterException('OVF deploy returned no result', 0, 'Deploy');
		}
		if (!($result['succeeded'] ?? false)) {
			$errors = [];
			foreach ((array) ($result['error']['errors'] ?? []) as $e) {
				if (!is_array($e)) {
					continue;
				}
				if (is_array($e['message'] ?? null) && isset($e['message']['default_message'])) {
					$errors[] = (string) $e['message']['default_message'];
				}
				foreach ((array) ($e['error']['messages'] ?? []) as $m) {
					$errors[] = is_array($m) ? (string) ($m['default_message'] ?? json_encode($m)) : (string) $m;
				}
			}
			throw new VCenterException(
				'OVF deploy failed' . ($errors !== [] ? ': ' . implode('; ', $errors) : ': ' . json_encode($result)),
				0, 'Deploy'
			);
		}
		return $result;
	}
}
