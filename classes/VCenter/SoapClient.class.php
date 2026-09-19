<?php
/**
 * vCenter MCP Server — vim25 SOAP Client
 *
 * Minimal hand-built SOAP client for the /sdk endpoint. Envelopes use
 * xmlns:soapenv + a body in urn:vim25 with SOAPAction
 * "urn:vim25/8.0.0.0". Login authenticates with the same credentials as
 * REST; the server's `Set-Cookie: vmware_soap_session="..."` is
 * captured from the response headers and replayed on later calls
 * (UserSession.key is NOT the cookie value — verified against 8.0.3).
 *
 * One generic invoke() builds/parses envelopes; each vim25 method is a
 * small typed helper, so adding one is a few lines.
 *
 * @package    VCenterMCP\VCenter
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace VCenter;

class SoapClient
{
	public const VIM25 = 'urn:vim25';
	public const SOAP_ACTION = 'urn:vim25/8.0.0.0';
	public const SOAP_ENV = 'http://schemas.xmlsoap.org/soap/envelope/';

	/** @var Instance Owning instance */
	private Instance $instance;

	/** @var string|null vmware_soap_session cookie value from Login's Set-Cookie */
	private ?string $sessionKey = null;

	/** @var array Response headers of the most recent send() (lowercase name => list) */
	private array $lastResponseHeaders = [];

	/** @var array|null Cached RetrieveServiceContent result */
	private ?array $serviceContent = null;

	/** @var array|null Cached GuestOperationsManager sub-manager MoRefs */
	private ?array $guestManagers = null;

	/** @var array<string,\Enchilada\Tortilla\HttpClient> Per-host clients for guest file transfers */
	private array $transferClients = [];

	/** @var int HTTP status of the most recent call */
	private int $lastHttpCode = 0;

	public function __construct(Instance $instance)
	{
		$this->instance = $instance;
	}

	/**
	 * Invoke a vim25 method.
	 *
	 * @param  string $method   vim25 method name (e.g. "RetrievePropertiesEx")
	 * @param  string $thisType Managed-object type of _this (e.g. "PropertyCollector")
	 * @param  string $thisId   MoRef id of _this (e.g. "propertyCollector")
	 * @param  string $innerXml XML for the method's parameters (already escaped)
	 * @return \SimpleXMLElement The <returnval> element (or method response
	 *                          element when there is no returnval)
	 * @throws VCenterException On SOAP faults or transport errors
	 */
	public function invoke(string $method, string $thisType, string $thisId, string $innerXml = ''): \SimpleXMLElement
	{
		$body = '<' . $method . ' xmlns="' . self::VIM25 . '">'
			. '<_this type="' . $thisType . '">' . self::esc($thisId) . '</_this>'
			. $innerXml
			. '</' . $method . '>';

		$xml = $this->roundTrip($body);

		// Return the <returnval> child of the method response, or the
		// response element itself for void methods.
		$response = null;
		foreach ($xml->children(self::VIM25) as $child) {
			$response = $child;
			break;
		}
		if ($response === null) {
			// Some serializers omit the vim25 namespace on the response
			foreach ($xml->children() as $child) {
				$response = $child;
				break;
			}
		}
		if ($response === null) {
			throw new VCenterException("Empty SOAP response for {$method}", 0, 'ParseError');
		}
		$returnval = $response->xpath('.//*[local-name()="returnval"]');
		if (is_array($returnval) && count($returnval) === 1) {
			return $returnval[0];
		}
		return $response;
	}

	/**
	 * Raw envelope round-trip. Public so tests and Phase-2 helpers can
	 * send arbitrary method bodies.
	 *
	 * @throws VCenterException On SOAP fault or transport error
	 */
	public function roundTrip(string $bodyXml): \SimpleXMLElement
	{
		$envelope = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<soapenv:Envelope xmlns:soapenv="' . self::SOAP_ENV . '">'
			. '<soapenv:Body>' . $bodyXml . '</soapenv:Body>'
			. '</soapenv:Envelope>';

		$headers = [
			'Content-Type: text/xml; charset=utf-8',
			'SOAPAction: "' . self::SOAP_ACTION . '"',
		];
		if ($this->sessionKey !== null) {
			$headers[] = 'Cookie: vmware_soap_session="' . $this->sessionKey . '"';
		}

		[$code, $responseBody] = $this->send($envelope, $headers);
		$url = $this->instance->url() . '/sdk';

		if ($code === 0) {
			throw new VCenterException("Transport error for {$url}: no HTTP response received", 0, 'Transport');
		}

		$xml = @simplexml_load_string($responseBody);
		if ($xml === false) {
			throw new VCenterException("Invalid SOAP response from {$url} (HTTP {$code})", $code, 'ParseError');
		}

		$bodies = $xml->xpath('//*[local-name()="Body"]');
		$bodyEl = $bodies[0] ?? null;
		if ($bodyEl === null) {
			throw new VCenterException("SOAP response from {$url} has no Body", $code, 'ParseError');
		}

		$faults = $bodyEl->xpath('./*[local-name()="Fault"]');
		if (!empty($faults)) {
			throw $this->faultException($faults[0], $code);
		}
		if ($code >= 400) {
			throw new VCenterException("SOAP call failed with HTTP {$code} for {$url}", $code, 'HttpError');
		}

		return $bodyEl;
	}

	/**
	 * RetrieveServiceContent -> decoded ServiceContent.
	 *
	 * @return array{about:array,sessionManager:array,propertyCollector:array,rootFolder:array,fileManager:?array}
	 */
	public function serviceContent(): array
	{
		if ($this->serviceContent === null) {
			$ret = $this->invoke('RetrieveServiceContent', 'ServiceInstance', 'ServiceInstance');
			$this->serviceContent = [
				'about' => self::xmlToArray($ret->xpath('.//*[local-name()="about"]')[0] ?? null),
				'sessionManager' => self::moRef($ret, 'sessionManager'),
				'propertyCollector' => self::moRef($ret, 'propertyCollector'),
				'rootFolder' => self::moRef($ret, 'rootFolder'),
				'fileManager' => self::moRef($ret, 'fileManager'),
				'virtualDiskManager' => self::moRef($ret, 'virtualDiskManager'),
				'guestOperationsManager' => self::moRef($ret, 'guestOperationsManager'),
			];
		}
		return $this->serviceContent;
	}

	/** The 'about' block (fullName, version, build, apiVersion, ...). */
	public function about(): array
	{
		return $this->serviceContent()['about'];
	}

	/**
	 * SOAP Login; stores the session key for the cookie header.
	 *
	 * @throws VCenterException
	 */
	public function login(): void
	{
		$content = $this->serviceContent();
		$this->invoke('Login', 'SessionManager', $content['sessionManager']['id'],
			'<userName>' . self::esc($this->instance->username()) . '</userName>'
			. '<password>' . self::esc($this->instance->password()) . '</password>');

		// The session token is the Set-Cookie header value, NOT the
		// UserSession.key in the response body (they differ — the cookie
		// is a 40-char session id, the key is a 36-char UUID).
		foreach ($this->lastResponseHeaders['set-cookie'] ?? [] as $line) {
			if (preg_match('/^\s*vmware_soap_session\s*=\s*"?([^";]*)"?/i', (string) $line, $m)
				&& $m[1] !== '') {
				$this->sessionKey = $m[1];
				return;
			}
		}
		throw new VCenterException(
			'Login succeeded but no vmware_soap_session cookie was returned',
			0, 'NotAuthenticated'
		);
	}

	/** SOAP Logout; best-effort. */
	public function logout(): void
	{
		if ($this->sessionKey === null) {
			return;
		}
		try {
			$content = $this->serviceContent();
			$this->invoke('Logout', 'SessionManager', $content['sessionManager']['id']);
		} catch (\Throwable $e) {
			// best-effort
		}
		$this->sessionKey = null;
	}

	public function hasSession(): bool
	{
		return $this->sessionKey !== null;
	}

	/**
	 * Ensure a SOAP session exists (Login once, re-login once on a
	 * NotAuthenticated fault).
	 */
	public function ensureSession(): void
	{
		if ($this->sessionKey === null) {
			$this->login();
		}
	}

	/**
	 * invoke() for session-bearing methods: ensures Login, and on a
	 * NotAuthenticated fault clears the session, re-logs in and retries
	 * once. Every helper except RetrieveServiceContent/Login/Logout goes
	 * through this.
	 *
	 * @throws VCenterException
	 */
	public function invokeAuthed(string $method, string $thisType, string $thisId, string $innerXml = ''): \SimpleXMLElement
	{
		$this->ensureSession();
		try {
			return $this->invoke($method, $thisType, $thisId, $innerXml);
		} catch (VCenterException $e) {
			if ($e->getErrorType() === 'NotAuthenticated') {
				$this->sessionKey = null;
				$this->login();
				return $this->invoke($method, $thisType, $thisId, $innerXml);
			}
			throw $e;
		}
	}

	/**
	 * RetrievePropertiesEx on a set of MoRefs.
	 *
	 * @param  array<int,array{type:string,id:string}> $objects MoRefs to read
	 * @param  string[] $props   Property names (apply to every object type)
	 * @return array<string,array<string,mixed>> props keyed by "type:id"
	 * @throws VCenterException
	 */
	public function retrieveProperties(array $objects, array $props): array
	{
		$byType = [];
		foreach ($objects as $obj) {
			$byType[$obj['type']] = $props;
		}
		return $this->properties($objects, $byType);
	}

	/**
	 * Shared RetrievePropertiesEx used by PropertyCollector.
	 *
	 * @param  array<int,array{type:string,id:string}> $objects
	 * @param  array<string,string[]> $propsByType type => property names
	 * @return array<string,array<string,mixed>> keyed by "type:id"
	 */
	public function properties(array $objects, array $propsByType): array
	{
		return $this->doRetrieveProperties($objects, $propsByType);
	}

	/**
	 * SearchDatastore_Task on a HostDatastoreBrowser; returns the Task MoRef.
	 *
	 * @param  array{type:string,id:string} $browser   HostDatastoreBrowser MoRef
	 * @param  string                       $datastorePath "[ds] path/" to list
	 * @param  string                       $pattern   File-name pattern ('*', '*.iso')
	 * @return array{type:string,id:string} Task MoRef
	 */
	public function searchDatastore(array $browser, string $datastorePath, string $pattern = '*'): array
	{
		$inner = '<datastorePath>' . self::esc($datastorePath) . '</datastorePath>'
			. '<searchSpec>'
			. '<details><fileType>true</fileType><fileSize>true</fileSize><modification>true</modification><fileOwner>false</fileOwner></details>'
			. '<matchPattern>' . self::esc($pattern) . '</matchPattern>'
			. '<sortFoldersFirst>true</sortFoldersFirst>'
			. '</searchSpec>';
		$ret = $this->invokeAuthed('SearchDatastore_Task', $browser['type'], $browser['id'], $inner);
		return ['type' => (string) $ret['type'], 'id' => (string) $ret];
	}

	/**
	 * Download a datastore file over the /folder endpoint (screenshots).
	 * Requires an active SOAP session (cookie is sent).
	 *
	 * @throws VCenterException
	 */
	public function downloadDatastoreFile(string $datastorePath, string $dcName, string $dsName): string
	{
		$this->ensureSession();
		// $datastorePath is "[ds] vm/dir/file.png" — strip the [ds] prefix
		$path = preg_replace('/^\[[^\]]+\]\s*/', '', $datastorePath);
		$query = 'dcPath=' . rawurlencode($dcName) . '&dsName=' . rawurlencode($dsName);

		$headers = ['Cookie: vmware_soap_session="' . $this->sessionKey . '"'];
		[$code, $body] = $this->send('', $headers, 'folder/' . implode('/', array_map('rawurlencode', explode('/', $path))) . '?' . $query, 'GET');
		if ($code !== 200) {
			throw new VCenterException("Datastore file download failed (HTTP {$code}) for {$datastorePath}", $code, 'Download');
		}
		return $body;
	}

	/** DeleteDatastoreFile_Task via FileManager; returns the Task MoRef. */
	public function deleteDatastoreFile(string $datastorePath, array $datacenterMoRef): array
	{
		$content = $this->serviceContent();
		$ret = $this->invokeAuthed('DeleteDatastoreFile_Task', 'FileManager', $content['fileManager']['id'],
			'<name>' . self::esc($datastorePath) . '</name>'
			. '<datacenter type="' . $datacenterMoRef['type'] . '">' . self::esc($datacenterMoRef['id']) . '</datacenter>');
		return ['type' => (string) $ret['type'], 'id' => (string) $ret];
	}

	// ── Guest Operations (VMware Tools) ─────────────────────────────
	//
	// All guest-ops methods ride on the GuestOperationsManager and take a
	// pre-built <auth> element (see GuestOperations::authXml). File
	// transfers are two-step: Initiate* hands back a one-time URL on the
	// /guestFile endpoint, then a plain PUT/GET moves the bytes. The URL
	// normally points at the ESXi host and is contacted directly (the
	// instance TLS policy covers it — VMCA signs host certs); an asterisk
	// hostname is substituted with the instance's vCenter, which proxies.

	/**
	 * GuestOperationsManager sub-managers (processManager, fileManager).
	 * The guest-ops methods are defined on those, not on the GOM itself,
	 * so their MoRefs are fetched once via the property collector.
	 */
	public function guestManagers(): array
	{
		if ($this->guestManagers === null) {
			$gom = $this->serviceContent()['guestOperationsManager'] ?? null;
			if ($gom === null) {
				throw new VCenterException('vCenter does not report a GuestOperationsManager', 0, 'NotSupported');
			}
			$props = $this->retrieveProperties([$gom], ['processManager', 'fileManager']);
			$props = $props[$gom['type'] . ':' . $gom['id']] ?? [];
			foreach (['processManager', 'fileManager'] as $manager) {
				if (empty($props[$manager])) {
					throw new VCenterException("GuestOperationsManager reports no {$manager}", 0, 'NotSupported');
				}
			}
			$this->guestManagers = $props;
		}
		return $this->guestManagers;
	}

	/**
	 * Shared envelope for guest-ops calls on a sub-manager:
	 * <vm>, <auth>, then the method-specific parameters.
	 */
	private function guestCall(string $manager, string $method, array $vm, string $authXml, string $innerXml): \SimpleXMLElement
	{
		$target = $this->guestManagers()[$manager];
		return $this->invokeAuthed($method, $target['type'], $target['id'],
			'<vm type="' . self::esc($vm['type']) . '">' . self::esc($vm['id']) . '</vm>'
			. $authXml
			. $innerXml);
	}

	/** StartProgramInGuest; returns the guest PID of the started process. */
	public function startProgramInGuest(array $vm, string $authXml, string $specXml): int
	{
		$ret = $this->guestCall('processManager', 'StartProgramInGuest', $vm, $authXml, '<spec>' . $specXml . '</spec>');
		return (int) ((string) $ret);
	}

	/**
	 * ListProcessesInGuest; raw GuestProcessInfo entries as arrays.
	 * PIDs that are not found are simply absent from the result.
	 */
	public function listProcessesInGuest(array $vm, string $authXml, array $pids = []): array
	{
		$inner = '';
		foreach ($pids as $pid) {
			$inner .= '<pids>' . (int) $pid . '</pids>';
		}
		$ret = $this->guestCall('processManager', 'ListProcessesInGuest', $vm, $authXml, $inner);
		return self::repeatedReturnval($ret);
	}

	/**
	 * InitiateFileTransferToGuest; returns the one-time upload URL.
	 * $attributesXml is the <fileAttributes> element (pre-built).
	 */
	public function initiateFileTransferToGuest(array $vm, string $authXml, string $guestPath, string $attributesXml, int $fileSize, bool $overwrite): string
	{
		$ret = $this->guestCall('fileManager', 'InitiateFileTransferToGuest', $vm, $authXml,
			'<guestFilePath>' . self::esc($guestPath) . '</guestFilePath>'
			. $attributesXml
			. '<fileSize>' . $fileSize . '</fileSize>'
			. '<overwrite>' . ($overwrite ? 'true' : 'false') . '</overwrite>');
		return (string) $ret;
	}

	/**
	 * InitiateFileTransferFromGuest.
	 *
	 * @return array{url:string,size:int,attributes:array} FileTransferInformation
	 */
	public function initiateFileTransferFromGuest(array $vm, string $authXml, string $guestPath): array
	{
		$ret = $this->guestCall('fileManager', 'InitiateFileTransferFromGuest', $vm, $authXml,
			'<guestFilePath>' . self::esc($guestPath) . '</guestFilePath>');
		$info = self::xmlToArray($ret);
		return [
			'url' => (string) ($info['url'] ?? ''),
			'size' => (int) ($info['size'] ?? 0),
			'attributes' => is_array($info['attributes'] ?? null) ? $info['attributes'] : [],
		];
	}

	/**
	 * ListFilesInGuest; decoded GuestListFileInfo
	 * ({files:array, newIndex:?int, endOfStream:bool}).
	 */
	public function listFilesInGuest(array $vm, string $authXml, string $path, ?string $matchPattern = null, ?int $index = null, ?int $maxResults = null): array
	{
		$inner = '<filePath>' . self::esc($path) . '</filePath>';
		if ($index !== null) {
			$inner .= '<index>' . $index . '</index>';
		}
		if ($maxResults !== null) {
			$inner .= '<maxResults>' . $maxResults . '</maxResults>';
		}
		if ($matchPattern !== null) {
			$inner .= '<matchPattern>' . self::esc($matchPattern) . '</matchPattern>';
		}
		$info = self::xmlToArray($this->guestCall('fileManager', 'ListFilesInGuest', $vm, $authXml, $inner));
		$files = $info['files'] ?? [];
		if (is_array($files) && !array_is_list($files)) {
			$files = [$files];
		}
		return [
			'files' => $files,
			'newIndex' => isset($info['newIndex']) ? (int) $info['newIndex'] : null,
			'endOfStream' => in_array($info['endOfStream'] ?? false, [true, 'true', 1, '1'], true),
		];
	}

	/** PUT $content to a guest file-transfer URL. */
	public function putGuestFile(string $transferUrl, string $content): void
	{
		[$code] = $this->transferCall($transferUrl, $content, 'PUT',
			['Content-Type: application/octet-stream']);
		if ($code !== 200) {
			throw new VCenterException("Guest file upload failed (HTTP {$code})", $code, 'Upload');
		}
	}

	/** GET the content of a guest file-transfer URL. */
	public function getGuestFile(string $transferUrl): string
	{
		[$code, $body] = $this->transferCall($transferUrl, null, 'GET');
		if ($code !== 200) {
			throw new VCenterException("Guest file download failed (HTTP {$code})", $code, 'Download');
		}
		return $body;
	}

	/**
	 * HTTP round-trip to a guest file-transfer URL
	 * ("https://<host>/guestFile?id=...&token=..."). A concrete host (the
	 * ESXi host serving the file) is contacted directly through its own
	 * Tortilla client with the instance TLS policy applied for that host
	 * (the VMCA signs host certs); an asterisk placeholder is substituted
	 * with the instance's vCenter host, which proxies the transfer.
	 *
	 * @return array{0:int,1:string} HTTP code and body
	 */
	private function transferCall(string $transferUrl, ?string $body, string $verb, array $headers = []): array
	{
		$parts = parse_url($transferUrl);
		if ($parts === false || empty($parts['scheme']) || !isset($parts['host'], $parts['path'])) {
			throw new VCenterException("Malformed guest file-transfer URL: {$transferUrl}", 0, 'ParseError');
		}
		$scheme = $parts['scheme'];
		$host = $parts['host'];
		if ($host === '*') {
			$host = parse_url($this->instance->url(), PHP_URL_HOST);
			if (!is_string($host) || $host === '') {
				throw new VCenterException("Cannot resolve asterisk host in guest file-transfer URL: {$transferUrl}", 0, 'ParseError');
			}
		}
		$port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
		$path = ltrim($parts['path'], '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
		$url = $scheme . '://' . $host . ':' . $port . '/' . $path;

		$fake = $this->instance->httpClient();
		if ($fake !== null) {
			$response = $fake($verb, $url, $headers, $body);
			return [$response['code'], (string) ($response['body'] ?? '')];
		}

		$client = $this->transferClients[$host . ':' . $port] ?? null;
		if ($client === null) {
			$multi = new \EnchiladaMultiHTTP($scheme . '://' . $host . ':' . $port);
			$multi->setTimeout(120);
			$this->instance->tlsPolicy()->apply($multi, $host, $port);
			$client = $this->transferClients[$host . ':' . $port] = new \Enchilada\Tortilla\HttpClient($multi);
		}

		try {
			$result = $client->call($path, $body, $verb, $headers, null, 'raw');
		} catch (\Exception $e) {
			throw new VCenterException("HTTP error for {$url}: " . $e->getMessage(), 0, 'Transport', $e);
		}
		$code = $client->getHttpCode();
		if ($code === 0 || $client->getLastCurlErrno() !== 0) {
			throw new VCenterException("Transport error for {$url}: " . $client->getLastCurlError(), 0, 'Transport');
		}
		return [$code, is_string($result) ? $result : ''];
	}

	/**
	 * Decode a response whose <returnval> may repeat (List*InGuest calls):
	 * invoke() unwraps a single returnval, so it must be re-wrapped.
	 */
	private static function repeatedReturnval(\SimpleXMLElement $ret): array
	{
		if ($ret->getName() === 'returnval') {
			return [self::xmlToArray($ret)];
		}
		$out = [];
		foreach ($ret->xpath('./*[local-name()="returnval"]') as $el) {
			$out[] = self::xmlToArray($el);
		}
		return $out;
	}

	/**
	 * PutUsbScanCodes: inject USB HID key events into a VM.
	 *
	 * One call carries at most 32 key events (vim25 limit); callers chunk.
	 *
	 * @param  array{type:string,id:string} $vm     VirtualMachine MoRef
	 * @param  array<int,array{usbHidCode:int,modifiers:array}> $events
	 * @return int Number of key events the server accepted
	 * @throws VCenterException When more than 32 events are passed
	 */
	public function putUsbScanCodes(array $vm, array $events): int
	{
		if (count($events) > 32) {
			throw new VCenterException('PutUsbScanCodes accepts at most 32 key events per call', 0, 'InvalidArgument');
		}

		$keys = '';
		foreach ($events as $event) {
			$mods = '';
			foreach (['leftControl', 'leftShift', 'leftAlt', 'leftGui', 'rightControl', 'rightShift', 'rightAlt', 'rightGui'] as $m) {
				$mods .= '<' . $m . '>' . (!empty($event['modifiers'][$m]) ? 'true' : 'false') . '</' . $m . '>';
			}
			$keys .= '<keyEvents>'
				. '<usbHidCode>' . (int) $event['usbHidCode'] . '</usbHidCode>'
				. '<modifiers>' . $mods . '</modifiers>'
				. '</keyEvents>';
		}

		$ret = $this->invokeAuthed('PutUsbScanCodes', $vm['type'], $vm['id'],
			'<spec>' . $keys . '</spec>');

		return is_numeric((string) $ret) ? (int) $ret : count($events);
	}

	/**
	 * CreateScreenshot_Task on a VirtualMachine; returns the Task MoRef.
	 * The task's info.result is a datastore path like "[ds] vm/vm-N.png".
	 *
	 * @param  array{type:string,id:string} $vm VirtualMachine MoRef
	 * @return array{type:string,id:string} Task MoRef
	 */
	public function createScreenshot(array $vm): array
	{
		$ret = $this->invokeAuthed('CreateScreenshot_Task', $vm['type'], $vm['id']);
		return ['type' => (string) $ret['type'], 'id' => (string) $ret];
	}

	/**
	 * AcquireTicket on a VirtualMachine.
	 *
	 * @param  array{type:string,id:string} $vm   VirtualMachine MoRef
	 * @param  string                       $type Ticket type ('webmks', ...)
	 * @return array{ticket:string,cfgFile:string,host:string,port:int,sslThumbprint:string}
	 */
	public function acquireTicket(array $vm, string $type): array
	{
		$ret = $this->invokeAuthed('AcquireTicket', $vm['type'], $vm['id'],
			'<ticketType>' . self::esc($type) . '</ticketType>');
		$a = self::xmlToArray($ret);
		return [
			'ticket' => (string) ($a['ticket'] ?? ''),
			'cfgFile' => (string) ($a['cfgFile'] ?? ''),
			'host' => (string) ($a['host'] ?? ''),
			'port' => (int) ($a['port'] ?? 0),
			'sslThumbprint' => (string) ($a['sslThumbprint'] ?? ''),
			// vSphere 8 may add per-algorithm entries [{hashAlgorithm, thumbprint}]
			'certThumbprintList' => array_values(array_filter(
				(array) ($a['certThumbprintList'] ?? []), 'is_array')),
		];
	}

	/**
	 * AnswerVM: answer a blocking VM question.
	 *
	 * @param array{type:string,id:string} $vm         VirtualMachine MoRef
	 * @param string                       $questionId Question id (runtime.question.id)
	 * @param string                       $choice     Choice key from choice.choiceInfo
	 */
	public function answerVm(array $vm, string $questionId, string $choice): void
	{
		$this->invokeAuthed('AnswerVM', $vm['type'], $vm['id'],
			'<questionId>' . self::esc($questionId) . '</questionId>'
			. '<answerChoice>' . self::esc($choice) . '</answerChoice>');
	}

	/**
	 * config.hardware.device with per-device xsi:type preserved — the
	 * generic properties() path flattens ArrayOfVirtualDevice entries
	 * into typeless arrays, and the video card cannot be told apart
	 * from e.g. a controller without the concrete type.
	 *
	 * @param  string $vmId VM MoRef id
	 * @return array<int,array{device:string,key:int,...}> One entry per
	 *         VirtualDevice; 'device' is the concrete xsi type
	 * @throws VCenterException
	 */
	public function vmHardwareDevices(string $vmId): array
	{
		$content = $this->serviceContent();
		$pc = $content['propertyCollector'];
		$ret = $this->invokeAuthed('RetrievePropertiesEx', 'PropertyCollector', $pc['id'],
			'<specSet>'
			. '<propSet><type>VirtualMachine</type><pathSet>config.hardware.device</pathSet></propSet>'
			. '<objectSet><obj type="VirtualMachine">' . self::esc($vmId) . '</obj></objectSet>'
			. '</specSet><options/>');

		$xsi = 'http://www.w3.org/2001/XMLSchema-instance';
		$devices = [];
		foreach ($ret->xpath('.//*[local-name()="propSet"]/*[local-name()="val"]/*') as $device) {
			$type = (string) preg_replace('/^.*:/', '', (string) $device->attributes($xsi)['type']);
			$devices[] = ['device' => $type] + (array) self::xmlToArray($device);
		}
		return $devices;
	}

	/**
	 * ReconfigVM_Task with a device-change spec; returns the Task MoRef.
	 *
	 * @param  array{type:string,id:string} $vm              VirtualMachine MoRef
	 * @param  string                       $deviceChangeXml Inner <deviceChange> XML (pre-escaped)
	 * @return array{type:string,id:string} Task MoRef
	 * @throws VCenterException
	 */
	public function reconfigVm(array $vm, string $deviceChangeXml): array
	{
		$ret = $this->invokeAuthed('ReconfigVM_Task', $vm['type'], $vm['id'],
			'<spec xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
			. $deviceChangeXml
			. '</spec>');
		return ['type' => (string) $ret['type'], 'id' => (string) $ret];
	}

	/**
	 * DeleteVirtualDisk_Task: remove a standalone VMDK. Returns the
	 * Task MoRef.
	 *
	 * @param array{type:string,id:string} $datacenter    Datacenter MoRef
	 * @param string                       $datastorePath "[Datastore] dir/name.vmdk"
	 * @return array{type:string,id:string} Task MoRef
	 * @throws VCenterException
	 */
	public function deleteVirtualDisk(array $datacenter, string $datastorePath): array
	{
		$vdm = $this->serviceContent()['virtualDiskManager'];
		$ret = $this->invokeAuthed('DeleteVirtualDisk_Task', $vdm['type'], $vdm['id'],
			'<name>' . self::esc($datastorePath) . '</name>'
			. '<datacenter type="' . self::esc($datacenter['type']) . '">' . self::esc($datacenter['id']) . '</datacenter>');
		return ['type' => (string) $ret['type'], 'id' => (string) $ret];
	}

	// ── Internals ────────────────────────────────────────────────────

	private function doRetrieveProperties(array $objects, array $propsByType): array
	{
		$content = $this->serviceContent();
		$pc = $content['propertyCollector'];

		// PropertySpec per type
		$propSpec = '';
		foreach ($propsByType as $type => $props) {
			$propSpec .= '<propSet><type>' . self::esc($type) . '</type>';
			foreach ($props as $p) {
				$propSpec .= '<pathSet>' . self::esc($p) . '</pathSet>';
			}
			$propSpec .= '</propSet>';
		}

		$objectSet = '';
		foreach ($objects as $obj) {
			$objectSet .= '<objectSet><obj type="' . self::esc($obj['type']) . '">' . self::esc($obj['id']) . '</obj></objectSet>';
		}

		$inner = '<specSet>' . $propSpec . $objectSet . '</specSet>';

		$ret = $this->invokeAuthed('RetrievePropertiesEx', 'PropertyCollector', $pc['id'],
			$inner . '<options/>');

		$out = [];
		foreach ($ret->xpath('.//*[local-name()="objects"]') as $objContent) {
			$obj = $objContent->xpath('./*[local-name()="obj"]')[0] ?? null;
			if ($obj === null) {
				continue;
			}
			$key = ((string) $obj['type']) . ':' . ((string) $obj);
			foreach ($objContent->xpath('./*[local-name()="propSet"]') as $propSet) {
				$name = (string) ($propSet->xpath('./*[local-name()="name"]')[0] ?? '');
				$val = $propSet->xpath('./*[local-name()="val"]')[0] ?? null;
				if ($name !== '' && $val !== null) {
					$out[$key][$name] = self::xmlToArray($val);
				}
			}
			// missingSet entries carry LocalizedMethodFaults for properties
			// that could not be read. Benign absences (NotFound — e.g.
			// runtime.question when no question is pending) stay silent;
			// real faults (NotAuthenticated, SecurityError, ...) surface
			// instead of silently yielding an empty property set.
			foreach ($objContent->xpath('./*[local-name()="missingSet"]') as $missing) {
				$path = (string) ($missing->xpath('./*[local-name()="path"]')[0] ?? '?');
				$faultEl = $missing->xpath('./*[local-name()="fault"]/*[local-name()="fault"]')[0] ?? null;
				if ($faultEl === null) {
					continue;
				}
				$faultType = preg_replace('/^.*:/', '', (string) $faultEl
					->attributes('http://www.w3.org/2001/XMLSchema-instance')['type']);
				if ($faultType === '' || in_array($faultType, ['NotFound', 'InvalidProperty'], true)) {
					continue;
				}
				$privilege = (string) ($faultEl->xpath('./*[local-name()="privilegeId"]')[0] ?? '');
				$detail = $privilege !== '' ? "missing privilege {$privilege}" : $faultType;
				throw new VCenterException(
					"Property '{$path}' on {$key} unavailable: {$faultType} ({$detail})",
					0, $faultType
				);
			}
		}
		return $out;
	}

	/**
	 * Send a SOAP envelope through the injected callable or shared engine.
	 *
	 * @return array{0:int,1:string} [HTTP status, response body];
	 *         response headers land in $this->lastResponseHeaders
	 *         (lowercase name => list of values).
	 * @throws VCenterException
	 */
	private function send(string $body, array $headers, ?string $pathOverride = null, string $verb = 'POST'): array
	{
		$path = $pathOverride ?? 'sdk';
		$url = $this->instance->url() . '/' . $path;

		$fake = $this->instance->httpClient();
		if ($fake !== null) {
			$response = $fake($verb, $url, $headers, $body === '' ? null : $body);
			$this->lastHttpCode = $response['code'];
			$this->lastResponseHeaders = $response['headers'] ?? [];
			return [$response['code'], (string) ($response['body'] ?? '')];
		}

		try {
			$result = $this->instance->http()->call($path, $body === '' ? null : $body, $verb, $headers, null, 'raw');
		} catch (\Exception $e) {
			throw new VCenterException("HTTP error for {$url}: " . $e->getMessage(), 0, 'Transport', $e);
		}

		$http = $this->instance->http();
		$this->lastHttpCode = $http->getHttpCode();
		$this->lastResponseHeaders = $http->getLastResponseHeaders();
		if ($this->lastHttpCode === 0 || $http->getLastCurlErrno() !== 0) {
			throw new VCenterException("Transport error for {$url}: " . $http->getLastCurlError(), 0, 'Transport');
		}
		return [$this->lastHttpCode, is_string($result) ? $result : ''];
	}

	/**
	 * Map a <Fault> element to a VCenterException.
	 */
	private function faultException(\SimpleXMLElement $fault, int $httpCode): VCenterException
	{
		$faultcode = (string) ($fault->xpath('./*[local-name()="faultcode"]')[0] ?? 'Fault');
		$faultstring = (string) ($fault->xpath('./*[local-name()="faultstring"]')[0] ?? 'SOAP fault');
		$detail = $fault->xpath('./*[local-name()="detail"]')[0] ?? null;
		$faultType = null;
		if ($detail !== null) {
			foreach ($detail->children() as $child) {
				$faultType = $child->getName();
				break;
			}
			// xsi:type on the detail element is common too
			$attrs = $detail->attributes('http://www.w3.org/2001/XMLSchema-instance');
			if ($faultType === null && isset($attrs['type'])) {
				$faultType = preg_replace('/^.*:/', '', (string) $attrs['type']);
			}
			// "NotAuthenticatedFault" -> "NotAuthenticated"
			if ($faultType !== null) {
				$faultType = preg_replace('/Fault$/', '', $faultType);
			}
		}
		return VCenterException::fromSoapFault($faultcode, $faultstring, $faultType, $httpCode);
	}

	/** Extract a ManagedObjectReference child as {type, id}. */
	public static function moRef(?\SimpleXMLElement $el, string $childName): ?array
	{
		if ($el === null) {
			return null;
		}
		$node = $el->xpath('./*[local-name()="' . $childName . '"]')[0] ?? null;
		if ($node === null) {
			return null;
		}
		return ['type' => (string) $node['type'], 'id' => (string) $node];
	}

	/**
	 * Decode a vim25 value element to a PHP value: scalars to strings,
	 * nested elements to arrays (MoRefs to {type,id}, repeated children
	 * to lists).
	 */
	public static function xmlToArray(?\SimpleXMLElement $el): mixed
	{
		if ($el === null) {
			return null;
		}
		$children = $el->children();
		// ArrayOf* types flatten to a plain list of their child values
		$type = (string) $el->attributes('http://www.w3.org/2001/XMLSchema-instance')['type'];
		if (str_contains($type, 'ArrayOf')) {
			$list = [];
			foreach ($children as $child) {
				$list[] = self::xmlToArray($child);
			}
			return $list;
		}
		if (count($children) === 0) {
			$text = (string) $el;
			if (str_contains($type, 'ManagedObjectReference') || isset($el['type'])) {
				return ['type' => (string) $el['type'], 'id' => $text];
			}
			if (str_ends_with($type, ':boolean') || $type === 'xsd:boolean') {
				return $text === 'true' || $text === '1';
			}
			if (str_ends_with($type, ':long') || str_ends_with($type, ':int')) {
				return is_numeric($text) ? (int) $text : $text;
			}
			return $text;
		}
		$out = [];
		foreach ($children as $child) {
			$name = $child->getName();
			$value = self::xmlToArray($child);
			if (isset($out[$name])) {
				if (!is_array($out[$name]) || !array_is_list($out[$name])) {
					$out[$name] = [$out[$name]];
				}
				$out[$name][] = $value;
			} else {
				$out[$name] = $value;
			}
		}
		return $out;
	}

	public static function esc(string $s): string
	{
		return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
	}
}
