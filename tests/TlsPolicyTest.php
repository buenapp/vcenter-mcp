<?php

use PHPUnit\Framework\TestCase;
use VCenter\TlsPolicy;
use VCenter\VCenterException;

class TlsPolicyTest extends TestCase
{
	private const CERT = __DIR__ . '/fixtures/test-cert.pem';

	private static function certDer(): string
	{
		return TlsPolicy::pemToDer(file_get_contents(self::CERT));
	}

	private static function spkiDer(): string
	{
		return TlsPolicy::spkiDer(file_get_contents(self::CERT));
	}

	private function policy(array $tlsaRows, ?string $thumbprint = null): TlsPolicy
	{
		return new TlsPolicy(
			true, null, $thumbprint,
			fn(string $name) => $tlsaRows,                       // TLSA lookup seam
			fn(string $host, int $port) => file_get_contents(self::CERT) // probe seam
		);
	}

	public function testDaneEeFullCertSha256Match(): void
	{
		$policy = $this->policy([['usage' => 3, 'selector' => 0, 'matching_type' => 1, 'cert_data' => hash('sha256', self::certDer())]]);
		$d = $policy->resolve('vcenter.test');
		$this->assertSame(TlsPolicy::MODE_DANE, $d['mode']);
		$this->assertFalse($d['verify_peer']);
	}

	public function testDaneEeSpkiSha256Match(): void
	{
		$policy = $this->policy([['usage' => 3, 'selector' => 1, 'matching_type' => 1, 'cert_data' => hash('sha256', self::spkiDer())]]);
		$this->assertSame(TlsPolicy::MODE_DANE, $policy->resolve('vcenter.test')['mode']);
	}

	public function testDaneEeSpkiSha512Match(): void
	{
		$policy = $this->policy([['usage' => 3, 'selector' => 1, 'matching_type' => 2, 'cert_data' => hash('sha512', self::spkiDer())]]);
		$this->assertSame(TlsPolicy::MODE_DANE, $policy->resolve('vcenter.test')['mode']);
	}

	public function testDaneMismatchFails(): void
	{
		$policy = $this->policy([['usage' => 3, 'selector' => 0, 'matching_type' => 1, 'cert_data' => str_repeat('ab', 32)]]);
		$this->expectException(VCenterException::class);
		$this->expectExceptionMessageMatches('/DANE/');
		$policy->resolve('vcenter.test');
	}

	public function testDaneTaSkippedThenMismatchFails(): void
	{
		$logs = [];
		$policy = new TlsPolicy(
			true, null, null,
			fn() => [['usage' => 2, 'selector' => 0, 'matching_type' => 1, 'cert_data' => str_repeat('ab', 32)]],
			fn() => file_get_contents(self::CERT),
			function (string $m) use (&$logs) { $logs[] = $m; }
		);
		try {
			$policy->resolve('vcenter.test');
			$this->fail('expected VCenterException');
		} catch (VCenterException $e) {
			$this->assertStringContainsString('no usable', $e->getMessage());
		}
		$this->assertNotEmpty(array_filter($logs, fn($l) => str_contains($l, 'usage 2')));
	}

	public function testNoTlsaFallsBackToCa(): void
	{
		$policy = $this->policy([]);
		$d = $policy->resolve('vcenter.test');
		$this->assertSame(TlsPolicy::MODE_CA, $d['mode']);
		$this->assertTrue($d['verify_peer']);
	}

	public function testThumbprintPinMatch(): void
	{
		$policy = $this->policy([], hash('sha256', self::certDer()));
		$this->assertSame(TlsPolicy::MODE_CA, $policy->resolve('vcenter.test')['mode']);
	}

	public function testThumbprintPinColonHexMatch(): void
	{
		$policy = $this->policy([], implode(':', str_split(hash('sha256', self::certDer()), 2)));
		$this->assertSame(TlsPolicy::MODE_CA, $policy->resolve('vcenter.test')['mode']);
	}

	public function testThumbprintPinMismatch(): void
	{
		$policy = $this->policy([], str_repeat('cd', 32));
		$this->expectException(VCenterException::class);
		$policy->resolve('vcenter.test');
	}

	public function testVerifyDisabled(): void
	{
		$policy = new TlsPolicy(false, null, null, fn() => $this->fail('TLSA lookup must not run when verify=false'));
		$this->assertSame(TlsPolicy::MODE_INSECURE, $policy->resolve('vcenter.test')['mode']);
	}

	public function testTlsaLookupFailureDegradesToCa(): void
	{
		$policy = new TlsPolicy(true, null, null,
			fn() => throw new \Enchilada\Dns\Exception('timeout'),
			fn() => file_get_contents(self::CERT));
		$this->assertSame(TlsPolicy::MODE_CA, $policy->resolve('vcenter.test')['mode']);
	}
}
