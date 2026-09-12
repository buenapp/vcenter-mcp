<?php

use PHPUnit\Framework\TestCase;
use VCenter\InstanceManager;
use VCenter\VCenterException;

require_once __DIR__ . '/lib/FakeHttp.php';

class InstanceManagerTest extends TestCase
{
	private function config(): array
	{
		return [
			'a' => ['url' => 'https://a.test', 'username' => 'u', 'password' => 'p'],
			'b' => ['url' => 'https://b.test', 'username' => 'u', 'password' => 'p', 'netrc' => false],
		];
	}

	public function testDefaultFallsBackToFirst(): void
	{
		$m = new InstanceManager($this->config());
		$this->assertSame('a', $m->getDefault());
	}

	public function testExplicitDefault(): void
	{
		$m = new InstanceManager($this->config(), 'b');
		$this->assertSame('b', $m->getDefault());
	}

	public function testUnknownInstance(): void
	{
		$m = new InstanceManager($this->config());
		$this->expectException(\InvalidArgumentException::class);
		$m->instance('zzz');
	}

	public function testEmptyInstanceStringResolvesDefault(): void
	{
		$m = new InstanceManager($this->config(), 'b');
		$this->assertSame('b', $m->instance('')->name());
	}

	public function testPasswordFromNetrc(): void
	{
		$netrc = tempnam(sys_get_temp_dir(), 'netrc');
		file_put_contents($netrc, 'machine vcenter.test login vcadmin password netrcpw');
		try {
			$m = new InstanceManager([
				'n' => ['url' => 'https://vcenter.test', 'username' => 'vcadmin', 'password' => null, 'netrc' => true, 'netrc_path' => $netrc],
			]);
			$this->assertSame('netrcpw', $m->instance('n')->password());
		} finally {
			@unlink($netrc);
		}
	}

	public function testMissingPasswordThrows(): void
	{
		$m = new InstanceManager(['x' => ['url' => 'https://x.test', 'username' => 'u']]);
		$this->expectException(VCenterException::class);
		$m->instance('x')->password();
	}

	public function testFromFile(): void
	{
		$path = tempnam(sys_get_temp_dir(), 'inst') . '.json';
		file_put_contents($path, json_encode(['default' => 'a', 'instances' => $this->config()]));
		try {
			$m = InstanceManager::fromFile($path);
			$this->assertSame('a', $m->getDefault());
			$this->assertSame(2, $m->count());
		} finally {
			@unlink($path);
		}
	}
}
