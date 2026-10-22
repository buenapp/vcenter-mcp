<?php
// Unix-socket HTTP fixture for the curl behaviour suite: answers every
// connection with one fixed JSON response naming the request line.
// Usage: php unix-server.php <socket path>; runs until terminated.

$path = $argv[1] ?? '';
if ($path === '') {
	fwrite(STDERR, "usage: php unix-server.php <socket path>\n");
	exit(2);
}
if (file_exists($path)) {
	unlink($path);
}
$server = stream_socket_server('unix://' . $path, $errno, $errstr);
if ($server === false) {
	fwrite(STDERR, "unix-server: cannot listen on {$path}: [{$errno}] {$errstr}\n");
	exit(1);
}

while (($conn = stream_socket_accept($server, -1)) !== false) {
	$request = '';
	while (!str_contains($request, "\r\n\r\n") && ($line = fgets($conn)) !== false) {
		$request .= $line;
	}
	$requestLine = strtok($request, "\r\n");
	$body = json_encode(['via' => 'unix', 'request' => $requestLine]);
	fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body)
		. "\r\nConnection: close\r\n\r\n" . $body);
	fclose($conn);
}
