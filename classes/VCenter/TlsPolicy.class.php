<?php
/**
 * vCenter MCP Server — TLS Policy
 *
 * DANE-first certificate validation for vCenter connections:
 *
 *   1. _443._tcp.<host> TLSA records (DANE-EE, usage 3): probe the peer
 *      with peer verification off and CURLOPT_CERTINFO on, extract the
 *      leaf certificate, and require a match against any usable record
 *      (selector 0 = full cert / 1 = SPKI; matching type 1 = SHA-256,
 *      2 = SHA-512). Usage 2 (DANE-TA) is out of scope for v0.1 — such
 *      records are skipped and logged. A mismatch is a hard failure.
 *   2. No TLSA records: standard CA verification (ca_cert override
 *      supported).
 *   3. An optional SHA-256 thumbprint (colon-hex of the leaf) pins the
 *      leaf in either mode; it does not disable steps 1/2.
 *
 * The TLSA lookup and the certificate probe are injectable so the policy
 * is fully testable offline.
 *
 * @package    VCenterMCP\VCenter
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace VCenter;

class TlsPolicy
{
	/** Decision: let curl perform standard CA verification. */
	public const MODE_CA = 'ca';
	/** Decision: DANE-EE authenticated the leaf; skip CA verification. */
	public const MODE_DANE = 'dane';
	/** Decision: verification disabled by configuration. */
	public const MODE_INSECURE = 'insecure';

	/** @var bool */
	private bool $verify;

	/** @var string|null CA bundle path override */
	private ?string $caCert;

	/** @var string|null SHA-256 leaf pin, colon-hex or plain hex */
	private ?string $thumbprint;

	/** @var callable|null function(string $name): array — TLSA row lookup (test seam) */
	private $tlsaLookup;

	/** @var callable|null function(string $host, int $port): string — leaf PEM probe (test seam) */
	private $certProbe;

	/** @var callable|null function(string $message): void — log sink */
	private $log;

	/** @var array<string,array> Resolved decisions keyed by "host:port" */
	private array $decisions = [];

	/**
	 * @param bool          $verify     Master switch (tls.verify)
	 * @param string|null   $caCert     CA bundle override (tls.ca_cert)
	 * @param string|null   $thumbprint SHA-256 leaf pin (tls.thumbprint)
	 * @param callable|null $tlsaLookup TLSA lookup seam: fn(string $name): array of Resolver rows
	 * @param callable|null $certProbe  Leaf-cert probe seam: fn(string $host, int $port): string PEM
	 * @param callable|null $log        Log sink: fn(string $message)
	 */
	public function __construct(
		bool $verify = true,
		?string $caCert = null,
		?string $thumbprint = null,
		?callable $tlsaLookup = null,
		?callable $certProbe = null,
		?callable $log = null
	) {
		$this->verify = $verify;
		$this->caCert = $caCert;
		$this->thumbprint = $thumbprint !== null ? strtolower(str_replace(':', '', $thumbprint)) : null;
		$this->tlsaLookup = $tlsaLookup;
		$this->certProbe = $certProbe;
		$this->log = $log;
	}

	/**
	 * Resolve the verification decision for a host:port.
	 *
	 * @param  string $host vCenter hostname
	 * @param  int    $port TLS port (default 443)
	 * @return array{mode:string, verify_peer:bool, ca_cert:?string, dane:bool}
	 * @throws VCenterException On DANE mismatch or thumbprint-pin failure
	 */
	public function resolve(string $host, int $port = 443): array
	{
		$key = "{$host}:{$port}";
		if (isset($this->decisions[$key])) {
			return $this->decisions[$key];
		}

		if (!$this->verify) {
			return $this->decisions[$key] = [
				'mode' => self::MODE_INSECURE, 'verify_peer' => false,
				'ca_cert' => null, 'dane' => false,
			];
		}

		$tlsa = $this->lookupTlsa($host, $port);

		if (!empty($tlsa)) {
			$leafPem = $this->probeLeaf($host, $port);
			$this->assertThumbprint(self::pemToDer($leafPem), $host);
			$this->assertTlsaMatch($leafPem, $tlsa, "{$host}:{$port}");

			return $this->decisions[$key] = [
				'mode' => self::MODE_DANE, 'verify_peer' => false,
				'ca_cert' => null, 'dane' => true,
			];
		}

		if ($this->thumbprint !== null) {
			$leafPem = $this->probeLeaf($host, $port);
			$this->assertThumbprint(self::pemToDer($leafPem), $host);
		}

		return $this->decisions[$key] = [
			'mode' => self::MODE_CA, 'verify_peer' => true,
			'ca_cert' => $this->caCert, 'dane' => false,
		];
	}

	/**
	 * Apply the resolved decision to an EnchiladaMultiHTTP engine.
	 *
	 * @param \EnchiladaMultiHTTP $multi Engine to configure
	 * @param string              $host  vCenter hostname
	 * @param int                 $port  TLS port
	 * @return array The resolved decision (see resolve())
	 */
	public function apply(\EnchiladaMultiHTTP $multi, string $host, int $port = 443): array
	{
		$decision = $this->resolve($host, $port);
		$multi->setVerifySsl($decision['verify_peer']);
		if ($decision['ca_cert'] !== null) {
			$multi->setCaCert($decision['ca_cert']);
		}
		return $decision;
	}

	/**
	 * Public TLSA lookup for _<port>._tcp.<host> — used by the WebMKS
	 * console hop where the leaf cert is captured after connect rather
	 * than probed up front. Returns Resolver rows ([] = no records).
	 */
	public function tlsaRecords(string $host, int $port): array
	{
		return $this->lookupTlsa($host, $port);
	}

	/**
	 * Require a leaf certificate (PEM) to match at least one usable
	 * DANE-EE TLSA record (usage 3; selector 0/1; matching type 1/2).
	 * Usage 2 records are skipped with a log line.
	 *
	 * @param string $leafPem Leaf certificate PEM
	 * @param array  $records Resolver rows (usage/selector/matching_type/cert_data)
	 * @param string $target  "host:port" label for errors/logging
	 * @throws VCenterException On mismatch
	 */
	public function assertTlsaMatch(string $leafPem, array $records, string $target): void
	{
		$leafDer = self::pemToDer($leafPem);
		$matched = false;
		$usable = 0;
		foreach ($records as $record) {
			$usage = (int) ($record['usage'] ?? -1);
			$selector = (int) ($record['selector'] ?? -1);
			$matching = (int) ($record['matching_type'] ?? -1);
			$certData = strtolower((string) ($record['cert_data'] ?? ''));

			if ($usage === 2) {
				$this->emit("TLS policy: skipping DANE-TA (usage 2) record for {$target} — out of scope for v0.1");
				continue;
			}
			if ($usage !== 3 || !in_array($selector, [0, 1], true) || !in_array($matching, [1, 2], true)) {
				continue;
			}
			$usable++;

			$selectorData = ($selector === 0) ? $leafDer : self::spkiDer($leafPem);
			$algo = ($matching === 1) ? 'sha256' : 'sha512';
			if (hash($algo, $selectorData) === $certData) {
				$matched = true;
				break;
			}
		}

		if (!$matched) {
			throw new VCenterException(
				"DANE verification failed for {$target}: leaf certificate matches no usable TLSA record"
				. ($usable === 0 ? ' (no usable DANE-EE records published)' : ''),
				0, 'DaneMismatch'
			);
		}
	}

	/**
	 * TLSA lookup for _<port>._tcp.<host> via Enchilada\Dns\Resolver.
	 *
	 * With no injected lookup, the first nameserver in /etc/resolv.conf is
	 * queried directly — dns_get_record() cannot express type 52. A
	 * transport failure or an empty answer both mean "no usable DANE":
	 * the lookup degrades to CA verification.
	 *
	 * @return array Resolver rows ([] = no records)
	 */
	private function lookupTlsa(string $host, int $port): array
	{
		$name = "_{$port}._tcp.{$host}";

		if ($this->tlsaLookup !== null) {
			try {
				return ($this->tlsaLookup)($name) ?: [];
			} catch (\Throwable $e) {
				$this->emit("TLSA lookup for {$name} failed: {$e->getMessage()} — falling back to CA verification");
				return [];
			}
		}

		$server = $this->systemNameserver();
		if ($server === null) {
			return [];
		}

		try {
			$resolver = new \Enchilada\Dns\Resolver($server);
			return $resolver->query($name, \Enchilada\Dns\Resolver::TYPE_TLSA);
		} catch (\Throwable $e) {
			$this->emit("TLSA lookup for {$name} failed: {$e->getMessage()} — falling back to CA verification");
			return [];
		}
	}

	/**
	 * Probe the peer's leaf certificate: connect with peer verification
	 * disabled and CURLOPT_CERTINFO on, extract the first cert PEM.
	 *
	 * @throws VCenterException When no certificate is presented
	 */
	private function probeLeaf(string $host, int $port): string
	{
		if ($this->certProbe !== null) {
			$pem = ($this->certProbe)($host, $port);
			if (!is_string($pem) || $pem === '') {
				throw new VCenterException("TLS probe for {$host}:{$port} returned no certificate", 0, 'TlsProbe');
			}
			return $pem;
		}

		$ch = curl_init("https://{$host}:{$port}/");
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_NOBODY => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_CERTINFO => true,
			CURLOPT_TIMEOUT => 15,
		]);
		curl_exec($ch);
		$info = curl_getinfo($ch, CURLINFO_CERTINFO);
		curl_close($ch);

		$pem = $info[0]['Cert'] ?? null;
		if (!is_string($pem) || $pem === '') {
			throw new VCenterException("TLS probe for {$host}:{$port} returned no certificate", 0, 'TlsProbe');
		}
		return $pem;
	}

	/**
	 * @throws VCenterException When the pinned leaf does not match
	 */
	private function assertThumbprint(string $leafDer, string $host): void
	{
		if ($this->thumbprint === null) {
			return;
		}
		if (hash('sha256', $leafDer) !== $this->thumbprint) {
			throw new VCenterException(
				"TLS thumbprint mismatch for {$host}: leaf certificate does not match the configured pin",
				0, 'ThumbprintMismatch'
			);
		}
	}

	/** PEM certificate -> DER bytes. */
	public static function pemToDer(string $pem): string
	{
		if (!preg_match('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $pem, $m)) {
			throw new VCenterException('Probe did not return a PEM certificate', 0, 'TlsProbe');
		}
		return base64_decode(preg_replace('/\s+/', '', $m[1]));
	}

	/** SubjectPublicKeyInfo DER bytes extracted from a PEM certificate. */
	public static function spkiDer(string $pem): string
	{
		$key = openssl_pkey_get_details(openssl_pkey_get_public($pem));
		if ($key === false || !isset($key['key'])) {
			throw new VCenterException('Could not extract public key from peer certificate', 0, 'TlsProbe');
		}
		return self::pemToDerGeneric($key['key'], 'PUBLIC KEY');
	}

	private static function pemToDerGeneric(string $pem, string $label): string
	{
		if (!preg_match('/-----BEGIN ' . $label . '-----(.*?)-----END ' . $label . '-----/s', $pem, $m)) {
			throw new VCenterException("Could not decode {$label} PEM block", 0, 'TlsProbe');
		}
		return base64_decode(preg_replace('/\s+/', '', $m[1]));
	}

	/** First nameserver from /etc/resolv.conf, or null. */
	private function systemNameserver(): ?string
	{
		$rc = @file_get_contents('/etc/resolv.conf');
		if ($rc === false) {
			return null;
		}
		if (preg_match('/^\s*nameserver\s+(\S+)/m', $rc, $m)) {
			return $m[1];
		}
		return null;
	}

	private function emit(string $message): void
	{
		if ($this->log !== null) {
			try {
				($this->log)($message);
			} catch (\Throwable $e) {
				// logging must never break TLS policy
			}
		}
	}
}
