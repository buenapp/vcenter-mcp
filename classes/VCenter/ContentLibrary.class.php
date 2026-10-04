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

	/**
	 * Create an empty OVF library item; returns the item id. The content
	 * service on loaded vCenters can outlive the instance timeout while
	 * still creating the item; on a transport timeout, adopt the item
	 * when it exists by then (this name is unique per deploy).
	 */
	public function createItem(string $libraryId, string $name): string
	{
		try {
			$id = $this->instance->rest()->post('content/library/item', [
				'library_id' => $libraryId,
				'name' => $name,
				'type' => 'ovf',
			], [], 600);
		} catch (VCenterException $e) {
			if ($e->getErrorType() !== 'Transport') {
				throw $e;
			}
			$id = $this->adoptItem($libraryId, $name);
			if ($id === null) {
				throw $e;
			}
			return $id;
		}
		if (!is_string($id)) {
			throw new VCenterException('Library item create returned no id', 0, 'LibraryError');
		}
		return $id;
	}

	/**
	 * Adopt an item by name after a timed-out create. Polls a few
	 * times; the service can take a minute to list a freshly
	 * created item.
	 */
	private function adoptItem(string $libraryId, string $name): ?string
	{
		for ($attempt = 0; $attempt < 12; $attempt++) {
			foreach ($this->instance->rest()->get('content/library/item',
					['library_id' => $libraryId], 300) ?? [] as $itemId) {
				$item = $this->instance->rest()->get('content/library/item/' . rawurlencode((string) $itemId), [], 300);
				if (is_array($item) && ($item['name'] ?? null) === $name) {
					return (string) ($item['id'] ?? $itemId);
				}
			}
			usleep(5000000);
		}
		return null;
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

	/**
	 * Open an update session on a library item; returns the session id.
	 * vCenter allows one ACTIVE session per item and create can be slow
	 * under load; a surviving ACTIVE session from a timed-out earlier
	 * run is adopted.
	 */
	public function openUpdateSession(string $itemId): string
	{
		try {
			$id = $this->instance->rest()->post('content/library/item/update-session', [
				'library_item_id' => $itemId,
			], [], 600);
		} catch (VCenterException $e) {
			$adopted = $this->adoptActiveSession($itemId);
			if ($adopted === null) {
				throw $e;
			}
			return $adopted;
		}
		if (!is_string($id)) {
			throw new VCenterException('Update session create returned no id', 0, 'LibraryError');
		}
		return $id;
	}

	/** An ACTIVE session for the item, when one exists. */
	private function adoptActiveSession(string $itemId): ?string
	{
		try {
			foreach ($this->instance->rest()->get('content/library/item/update-session') ?? [] as $sessionId) {
				$session = $this->instance->rest()->get(
					'content/library/item/update-session/' . rawurlencode((string) $sessionId), [], 300);
				if (is_array($session)
					&& ($session['library_item_id'] ?? null) === $itemId
					&& ($session['state'] ?? null) === 'ACTIVE') {
					return (string) $sessionId;
				}
			}
		} catch (VCenterException $e) {
			// list shape changed; nothing to adopt
		}
		return null;
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
	 * /rest flavor on 8.0 (verified against 8.0.3). $size is mandatory
	 * in practice: without it the server registers zero bytes and the
	 * item completes empty.
	 *
	 * @throws VCenterException
	 */
	public function addPullFile(string $sessionId, string $fileName, string $uri, ?string $thumbprint = null, ?int $size = null): void
	{
		$endpoint = ['uri' => $uri];
		if ($thumbprint !== null) {
			$endpoint['ssl_certificate_thumbprint'] = $thumbprint;
		}
		$spec = [
			'name' => $fileName,
			'source_type' => 'PULL',
			'source_endpoint' => $endpoint,
		];
		if ($size !== null) {
			$spec['size'] = $size;
		}
		$body = ['file_spec' => $spec];
		$path = 'rest/com/vmware/content/library/item/updatesession/file/id:' . $sessionId . '?~action=add';
		// Items created (or adopted) moments earlier may lack their
		// storage backing yet; 'Cannot find library item' resolves on
		// its own within a minute or two.
		for ($attempt = 0; ; $attempt++) {
			try {
				$this->legacy($path, $body);
				return;
			} catch (VCenterException $e) {
				if ($attempt >= 24
					|| !str_contains($e->getMessage(), 'Cannot find library item')) {
					throw $e;
				}
				usleep(5000000);
			}
		}
	}

	/**
	 * PULL transfers run during and after complete; completion means the
	 * SESSION leaves ACTIVE (file-level status is not reliable: vCenter
	 * reports READY before pulling). ERROR carries the failure detail.
	 *
	 * @throws VCenterException On session ERROR or timeout
	 */
	public function waitSessionDone(string $sessionId, int $timeoutSec = 3600): void
	{
		$deadline = microtime(true) + $timeoutSec;
		while (true) {
			$session = $this->instance->rest()->get(
				'content/library/item/update-session/' . rawurlencode($sessionId), [], 300);
			$state = (string) (is_array($session) ? ($session['state'] ?? '') : '');
			if ($state === 'ERROR') {
				$message = is_array($session['error_message'] ?? null)
					? (string) ($session['error_message']['default_message'] ?? 'transfer error')
					: 'transfer error';
				throw new VCenterException("Library import failed: {$message}", 0, 'Transfer');
			}
			if ($state !== '' && $state !== 'ACTIVE') {
				return;
			}
			if (microtime(true) >= $deadline) {
				throw new VCenterException(
					"Timed out waiting {$timeoutSec}s for library session {$sessionId} (last state: {$state})",
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
		$path = 'vcenter/ovf/library-item/' . rawurlencode($itemId);
		$body = ['target' => $target, 'deployment_spec' => $spec];
		// After the update session completes, vCenter still unpacks the
		// OVF asynchronously; deploys issued too early get 'not an OVF'.
		for ($attempt = 0; ; $attempt++) {
			$result = null;
			try {
				$result = $this->instance->rest()->post($path, $body, ['action' => 'deploy'], $timeoutSec);
			} catch (VCenterException $e) {
				if ($attempt < 24 && str_contains($e->getMessage(), 'not an OVF')) {
					usleep(5000000);
					continue;
				}
				throw $e;
			}
			break;
		}
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
