<?php

use PHPUnit\Framework\TestCase;
use VCenter\Netrc;

require_once __DIR__ . '/lib/FakeHttp.php';

class NetrcTest extends TestCase
{
	private string $file;

	protected function setUp(): void
	{
		$this->file = tempnam(sys_get_temp_dir(), 'netrc');
	}

	protected function tearDown(): void
	{
		@unlink($this->file);
	}

	public function testSingleLineFormat(): void
	{
		file_put_contents($this->file, 'machine vcenter.example.com login vcadmin password s3cret');
		$this->assertSame(['login' => 'vcadmin', 'password' => 's3cret'], Netrc::lookup('vcenter.example.com', $this->file));
	}

	public function testMultiLineFormat(): void
	{
		file_put_contents($this->file, "machine vcenter.example.com\n\tlogin vcadmin\n\tpassword p@ssw0rd\nmachine other login bob password x\n");
		$this->assertSame('p@ssw0rd', Netrc::lookup('vcenter.example.com', $this->file)['password']);
	}

	public function testDefaultEntryFallback(): void
	{
		file_put_contents($this->file, "machine a.example.com login a password pa\ndefault login defuser password defpass\n");
		$this->assertSame('defuser', Netrc::lookup('missing.example.com', $this->file)['login']);
		$this->assertSame('pa', Netrc::lookup('a.example.com', $this->file)['password']);
	}

	public function testMachinePreferredOverDefault(): void
	{
		file_put_contents($this->file, "default login d password dp\nmachine b.example.com login b password bp\n");
		$this->assertSame('bp', Netrc::lookup('b.example.com', $this->file)['password']);
	}

	public function testMacdefBlockSkipped(): void
	{
		file_put_contents($this->file, "machine m.example.com login u password p\nmacdef init\ncd /pub\n\nmachine n.example.com login n password np\n");
		$this->assertSame('np', Netrc::lookup('n.example.com', $this->file)['password']);
	}

	public function testMissingFileReturnsNull(): void
	{
		$this->assertNull(Netrc::lookup('x', '/nonexistent/.netrc'));
	}

	public function testUnknownHostReturnsNull(): void
	{
		file_put_contents($this->file, 'machine a.example.com login a password pa');
		$this->assertNull(Netrc::lookup('zzz.example.com', $this->file));
	}
}
