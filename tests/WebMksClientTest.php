<?php

use EnchiladaWebSocket\WebSocketFrame;
use PHPUnit\Framework\TestCase;
use VCenter\Console\WebMksClient;
use VCenter\Instance;
use VCenter\TlsPolicy;
use VCenter\VCenterException;

require_once __DIR__ . '/lib/FakeHttp.php';
require_once __DIR__ . '/lib/FakeWsTransport.php';

class WebMksClientTest extends TestCase
{
	private function certPem(): string
	{
		return file_get_contents(__DIR__ . '/fixtures/test-cert.pem');
	}

	private function thumbprint(string $algo): string
	{
		return strtoupper(implode(':', str_split(openssl_x509_fingerprint($this->certPem(), $algo), 2)));
	}

	private function instance(FakeHttp $fake, array $tlsa = []): Instance
	{
		$inst = new Instance('test', ['url' => 'https://vcenter.test', 'username' => 'u', 'password' => 'p'], $fake->callable());
		$inst->setTlsPolicy(new TlsPolicy(true, null, null, fn(string $name) => $tlsa));
		return $inst;
	}

	private function ticketRoute(FakeHttp $fake, string $thumbprint): void
	{
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'RetrieveServiceContent'),
			fn() => ['code' => 200, 'body' => file_get_contents(__DIR__ . '/fixtures/soap/service-content.xml')]);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', '<Login '),
			fn() => ['code' => 200, 'body' => file_get_contents(__DIR__ . '/fixtures/soap/login-response.xml'), 'headers' => FakeHttp::soapSessionHeaders()]);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'AcquireTicket'),
			fn() => ['code' => 200, 'body' => '<?xml version="1.0"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <soapenv:Body><AcquireTicketResponse xmlns="urn:vim25"><returnval xsi:type="VirtualMachineTicket">
    <ticket>TKT-1</ticket><cfgFile>[ds2] t1/t1.vmx</cfgFile>
    <host>esx1.test</host><port>443</port>
    <sslThumbprint>' . $thumbprint . '</sslThumbprint>
  </returnval></AcquireTicketResponse></soapenv:Body></soapenv:Envelope>']);
	}

	/** Server bytes for a complete open()+screenshot() on a 4x2 console. */
	private function serverScript(): string
	{
		$pixelFormat = pack('CCCCnnnCCCxxx', 32, 24, 0, 1, 255, 255, 255, 16, 8, 0);
		$name = 'testvm';
		$rfb = "RFB 003.008\n"
			. "\x01\x01"                                   // one security type: None
			. "\x00\x00\x00\x00"                           // SecurityResult OK
			. pack('nn', 4, 2) . $pixelFormat . pack('N', strlen($name)) . $name;
		$pxRed = str_repeat(pack('V', 0x00FF0000), 4);
		$pxBlue = str_repeat(pack('V', 0x000000FF), 4);
		$rfb .= chr(0) . "\x00" . pack('n', 2)
			. pack('nnnnN', 0, 0, 4, 1, 0) . $pxRed
			. pack('nnnnN', 0, 1, 4, 1, 0) . $pxBlue;
		return $rfb;
	}

	private function ws101(string $request): string
	{
		preg_match('/Sec-WebSocket-Key:\s*(\S+)/', $request, $m);
		$accept = base64_encode(sha1($m[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
		return "HTTP/1.1 101 Switching Protocols\r\n"
			. "Upgrade: websocket\r\nConnection: Upgrade\r\n"
			. "Sec-WebSocket-Accept: {$accept}\r\n"
			. "Sec-WebSocket-Protocol: binary\r\n\r\n";
	}

	private function fakeTransport(string $certPem, ?string $script = null, ?callable $extraOnWrite = null): FakeWsTransport
	{
		return new FakeWsTransport($certPem, function (FakeWsTransport $t, string $data) use ($script, $extraOnWrite) {
			if (str_starts_with($data, 'GET ')) {
				$t->feed($this->ws101($data));
				if ($script !== null) {
					$t->feed(WebSocketFrame::binary($script, false)->encode());
				}
			}
			if ($extraOnWrite !== null) {
				$extraOnWrite($t, $data);
			}
		});
	}

	/** Decode every client write into WS frame payloads. */
	private function clientPayloads(FakeWsTransport $t): array
	{
		$out = [];
		foreach ($t->writes as $w) {
			if (str_starts_with($w, 'GET ')) {
				$out[] = ['http', $w];
				continue;
			}
			[$frame] = WebSocketFrame::tryDecode($w);
			$out[] = ['frame', $frame->payload];
		}
		return $out;
	}

	public function testHandshakeAndScreenshot(): void
	{
		$fake = new FakeHttp();
		$this->ticketRoute($fake, $this->thumbprint('sha1'));
		$transport = $this->fakeTransport($this->certPem(), $this->serverScript());

		$client = new WebMksClient($this->instance($fake), 'vm-42', fn() => $transport);
		$client->open();

		// handshake request offered only the 'binary' subprotocol
		$this->assertStringContainsString('Sec-WebSocket-Protocol: binary', $transport->writes[0]);
		$this->assertStringContainsString('/ticket/TKT-1', $transport->writes[0]);
		$this->assertSame(['esx1.test', 443, true], array_slice($transport->connectArgs, 0, 3));

		$info = $client->info();
		$this->assertSame('binary', $info['protocol']);
		$this->assertSame('thumbprint:sha1', $info['tls_trust']);
		$this->assertSame(4, $info['width']);
		$this->assertSame(2, $info['height']);

		$shot = $client->screenshot();
		$this->assertSame(4, $shot['width']);
		$this->assertSame(2, $shot['height']);
		$this->assertSame("\x89PNG\r\n\x1a\n", substr($shot['png'], 0, 8));

		// RFB client bytes: version, security selection, ClientInit,
		// then SetPixelFormat + SetEncodings([0]) + FBURequest in one frame
		$payloads = array_column(
			array_filter($this->clientPayloads($transport), fn($p) => $p[0] === 'frame'), 1);
		$this->assertSame("RFB 003.008\n", $payloads[0]);
		$this->assertSame("\x01", $payloads[1]);
		$this->assertSame("\x01", $payloads[2]); // ClientInit shared
		$init = $payloads[3];
		$this->assertStringContainsString("\x02\x00\x00\x01" . pack('N', 0), $init); // SetEncodings lists only Raw
		$this->assertStringContainsString("\x03\x00" . pack('nnnn', 0, 0, 4, 2), $init); // full non-incremental request

		$client->close();
		$this->assertFalse($client->isOpen());
	}

	public function testTlsaTrustBasis(): void
	{
		$fake = new FakeHttp();
		$this->ticketRoute($fake, '00:00:00:00'); // ticket thumbprint must be ignored when TLSA matches
		$der = TlsPolicy::pemToDer($this->certPem());
		$tlsa = [['usage' => 3, 'selector' => 0, 'matching_type' => 1, 'cert_data' => hash('sha256', $der)]];

		$transport = $this->fakeTransport($this->certPem(), "RFB 003.008\n\x01\x01\x00\x00\x00\x00"
			. pack('nn', 2, 2) . pack('CCCCnnnCCCxxx', 32, 24, 0, 1, 255, 255, 255, 16, 8, 0)
			. pack('N', 1) . 'x');
		$client = new WebMksClient($this->instance($fake, $tlsa), 'vm-42', fn() => $transport);
		$client->open();
		$this->assertSame('tlsa', $client->info()['tls_trust']);
	}

	public function testThumbprintMismatchThrows(): void
	{
		$fake = new FakeHttp();
		$this->ticketRoute($fake, 'AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD');
		$transport = $this->fakeTransport($this->certPem(), $this->serverScript());
		$client = new WebMksClient($this->instance($fake), 'vm-42', fn() => $transport);
		$this->expectException(VCenterException::class);
		$this->expectExceptionMessageMatches('/thumbprint/i');
		$client->open();
	}

	public function testSendKeysWritesKeyEvents(): void
	{
		$fake = new FakeHttp();
		$this->ticketRoute($fake, $this->thumbprint('sha1'));
		$transport = $this->fakeTransport($this->certPem(), $this->serverScript());
		$client = new WebMksClient($this->instance($fake), 'vm-42', fn() => $transport);
		$client->open();

		$sent = $client->sendKeys(\VCenter\Console\Keysyms::fromText('a'), 0);
		$this->assertSame(1, $sent);

		$payloads = array_column(
			array_filter($this->clientPayloads($transport), fn($p) => $p[0] === 'frame'), 1);
		$last = end($payloads);
		$this->assertSame("\x04\x01\x00\x00" . pack('N', 0x61) . "\x04\x00\x00\x00" . pack('N', 0x61), $last);
	}
}
