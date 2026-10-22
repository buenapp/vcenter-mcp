<?php
// Fixture router for the curl behaviour suite's `php -S` server.
// Responses are deterministic apart from the Date header.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$json = function (int $status, $body): void {
	http_response_code($status);
	header('Content-Type: application/json');
	echo json_encode($body);
};

switch (true) {
	case $path === '/json':
		$json(200, ['ok' => true, 'n' => 1]);
		return;

	case $path === '/invalid-json':
		header('Content-Type: application/json');
		echo '{"truncated": ';
		return;

	case $path === '/empty':
		header('Content-Type: application/json');
		return;

	case $path === '/no-content':
		http_response_code(204);
		return;

	case $path === '/missing':
		$json(404, ['error' => 'not here']);
		return;

	case preg_match('#^/redirect/(\d+)$#', $path, $m) === 1:
		$hops = (int) $m[1];
		if ($hops > 0) {
			header('X-Hop: ' . $hops);
			header('Set-Cookie: hop' . $hops . '=1');
			header('Location: /redirect/' . ($hops - 1), true, 302);
			return;
		}
		header('X-Final: yes');
		$json(200, ['path' => $path]);
		return;

	case $path === '/echo':
		// Request fingerprint, for byte-exact payload and header checks
		$body = file_get_contents('php://input');
		$json(200, [
			'method' => $_SERVER['REQUEST_METHOD'],
			'query' => $_GET,
			'post' => $_POST,
			'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
			'len' => strlen($body),
			'sha256' => hash('sha256', $body),
			'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
			'files' => array_map(fn($f) => ['name' => $f['name'], 'size' => $f['size']], $_FILES),
		]);
		return;

	case $path === '/auth':
		if (($_SERVER['PHP_AUTH_USER'] ?? null) !== 'alice' || ($_SERVER['PHP_AUTH_PW'] ?? null) !== 's3cret') {
			header('WWW-Authenticate: Basic realm="fixture"');
			$json(401, ['error' => 'unauthorized']);
			return;
		}
		$json(200, ['user' => $_SERVER['PHP_AUTH_USER']]);
		return;

	case $path === '/stream':
		// Five lines with a pause between each, flushed one by one
		header('Content-Type: text/plain');
		for ($i = 1; $i <= 5; $i++) {
			echo "line-{$i}\n";
			flush();
			usleep(40000);
		}
		return;

	case $path === '/sse-done':
		// [DONE] arrives early; the connection then stays open. A client
		// must complete on [DONE]; the keepalive comments end this script
		// as soon as the client has gone (write fails), so a
		// single-threaded server is free again at once.
		header('Content-Type: text/event-stream');
		echo "data: {\"n\":1}\n\n";
		echo "data: [DONE]\n\n";
		flush();
		for ($i = 0; $i < 40; $i++) {
			usleep(50000);
			echo ": keepalive\n\n";
			flush();
		}
		return;

	case $path === '/sse-done-trailing':
		// [DONE] and later bytes in one write: they reach the client in
		// the same chunk
		header('Content-Type: text/event-stream');
		echo "data: {\"n\":1}\n\ndata: [DONE]\n\n: trailing\n\ndata: late\n\n";
		flush();
		return;

	case $path === '/slow':
		usleep(2000000);
		$json(200, ['slow' => true]);
		return;
}

$json(404, ['error' => 'unknown fixture ' . $path]);
