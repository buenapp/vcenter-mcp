<?php

/**
 * Test seam for the vCenter HTTP path: a scriptable callable matching
 * fn(string $method, string $url, array $headers, ?string $body)
 * returning ['code' => int, 'body' => string].
 *
 * Routes are matched in order; each is fn(array $call): array{code,body}
 * or a literal response array. Every call is recorded in $log.
 */
class FakeHttp
{
	/** @var array<int,array{method:string,url:string,headers:array,body:?string}> */
	public array $log = [];

	/** @var callable[] */
	private array $routes = [];

	/** @var callable|null Fallback handler */
	private $default;

	public function __construct(?callable $default = null)
	{
		$this->default = $default ?? fn(array $call) => ['code' => 404, 'body' => ''];
	}

	/**
	 * Add a route. $matcher: fn(array $call): bool; $responder: fn(array $call): array.
	 */
	public function when(callable $matcher, callable $responder): self
	{
		$this->routes[] = function (array $call) use ($matcher, $responder) {
			return $matcher($call) ? $responder($call) : null;
		};
		return $this;
	}

	/** Route by method + URL substring. */
	public function on(string $method, string $urlContains, callable|array $responder): self
	{
		$responder = is_callable($responder) ? $responder : fn() => $responder;
		return $this->when(
			fn(array $call) => $call['method'] === $method && str_contains($call['url'], $urlContains),
			$responder
		);
	}

	/**
	 * Response-headers array carrying the SOAP session cookie, for
	 * Login responders: ['headers' => FakeHttp::soapSessionHeaders()].
	 */
	public static function soapSessionHeaders(string $value = '5271f3e6-9b3d-4d7a-a1c2-0123456789ab'): array
	{
		return ['set-cookie' => ['vmware_soap_session="' . $value . '"; Path=/sdk; HttpOnly']];
	}

	public function callable(): callable
	{
		return function (string $method, string $url, array $headers, ?string $body): array {
			$call = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
			$this->log[] = $call;
			foreach ($this->routes as $route) {
				$response = $route($call);
				if ($response !== null) {
					return $response;
				}
			}
			return ($this->default)($call);
		};
	}

	/** All recorded calls matching a method + URL substring. */
	public function calls(string $method = '', string $urlContains = ''): array
	{
		return array_values(array_filter($this->log, fn($c) =>
			($method === '' || $c['method'] === $method)
			&& ($urlContains === '' || str_contains($c['url'], $urlContains))
		));
	}
}
