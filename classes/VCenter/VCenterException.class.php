<?php
/**
 * vCenter MCP Server — vCenter Exception
 *
 * Error mapping for the vSphere REST (vapi std errors) and vim25 SOAP
 * (fault) surfaces. Carries the HTTP status (or 0 for SOAP/transport)
 * plus the server-reported error type when known.
 *
 * @package    VCenterMCP\VCenter
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace VCenter;

class VCenterException extends \Exception
{
	/** @var string|null Server-reported error type (vapi error id or SOAP faultcode) */
	private ?string $errorType;

	public function __construct(string $message, int $code = 0, ?string $errorType = null, ?\Throwable $previous = null)
	{
		parent::__construct($message, $code, $previous);
		$this->errorType = $errorType;
	}

	/**
	 * Build from a vSphere Automation API error body.
	 *
	 * vapi errors look like {"type":"com.vmware.vapi.std.errors.not_found",
	 * "value":{"messages":[{"default_message":"...","id":"..."}]}} — older
	 * endpoints return {"value": "..."} or a plain message.
	 *
	 * @param int    $httpCode HTTP status code
	 * @param string $body     Raw response body
	 * @param string $url      Request URL (already credential-safe)
	 */
	public static function fromRest(int $httpCode, string $body, string $url): self
	{
		$type = null;
		$detail = '';
		$decoded = json_decode($body, true);

		if (is_array($decoded)) {
			$type = $decoded['type'] ?? null;
			$messages = $decoded['value']['messages'] ?? [];
			if (is_array($messages) && isset($messages[0]['default_message'])) {
				$detail = $messages[0]['default_message'];
			} elseif (isset($decoded['value']) && is_string($decoded['value'])) {
				$detail = $decoded['value'];
			} elseif (isset($decoded['message']) && is_string($decoded['message'])) {
				$detail = $decoded['message'];
			}
		}

		$shortType = $type !== null ? basename(str_replace(['.', '\\'], '/', $type)) : null;
		$message = "vCenter API error ({$httpCode}) for {$url}";
		if ($shortType !== null && $shortType !== '') {
			$message .= ": {$shortType}";
		}
		if ($detail !== '') {
			$message .= ($shortType ? ' — ' : ': ') . $detail;
		}

		return new self($message, $httpCode, $type);
	}

	/**
	 * Build from a vim25 SOAP fault.
	 *
	 * @param string      $faultcode   SOAP-ENV fault code (e.g. "ServerFaultCode")
	 * @param string      $faultstring Human-readable fault string
	 * @param string|null $faultType   vim25 fault type when present (e.g. "InvalidArgument")
	 * @param int         $httpCode    HTTP status (500 typical, 0 for parse failures)
	 */
	public static function fromSoapFault(string $faultcode, string $faultstring, ?string $faultType = null, int $httpCode = 0): self
	{
		$type = $faultType ?? $faultcode;
		$message = "vCenter SOAP fault [{$type}]: {$faultstring}";
		return new self($message, $httpCode, $type);
	}

	/** Server-reported error type, or null when the error was not typed. */
	public function getErrorType(): ?string
	{
		return $this->errorType;
	}
}
