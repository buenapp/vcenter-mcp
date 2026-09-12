<?php

/* Enchilada Framework 3.0
 * DNS Resolver — query TXT/A/AAAA/CNAME/PTR/NS/MX/SRV via a specific server
 *
 * $Id$
 *
 * Software License Agreement (BSD License)
 *
 * Copyright (c) 2013-2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 *
 * Redistribution and use of this software in source and binary forms, with or without modification, are
 * permitted provided that the following conditions are met:
 *
 *   Redistributions of source code must retain the above
 *   copyright notice, this list of conditions and the
 *   following disclaimer.
 *
 *   Redistributions in binary form must reproduce the above
 *   copyright notice, this list of conditions and the
 *   following disclaimer in the documentation and/or other
 *   materials provided with the distribution.
 *
 *   Neither the name of The Daniel Morante Company, Inc. nor the names of its
 *   contributors may be used to endorse or promote products
 *   derived from this software without specific prior
 *   written permission of The Daniel Morante Company, Inc.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED
 * WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A
 * PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR
 * TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF
 * ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

namespace Enchilada\Dns;

/**
 * A minimal DNS client that can query a specific nameserver, bypassing the
 * OS resolver (/etc/resolv.conf).
 *
 * Needed on hosts behind split-horizon DNS: the system resolver answers from
 * internal views while public-facing checks (SPF verification, DKIM key
 * matching) must see the public view.
 *
 * Transport failures (timeout, refused, malformed packet) raise
 * \Enchilada\Dns\Exception so callers can distinguish "lookup failed" from
 * "record does not exist" — the former should not be treated as absence.
 *
 * dns_get_record() note: A non-zero RCODE (NXDOMAIN, NODATA under NOERROR)
 * is a successful answer meaning "no such records" and yields an empty array.
 *
 * @author Daniel Morante
 */
class Resolver {

	const TYPE_A     = 1;
	const TYPE_NS    = 2;
	const TYPE_CNAME = 5;
	const TYPE_PTR   = 12;
	const TYPE_MX    = 15;
	const TYPE_TXT   = 16;
	const TYPE_AAAA  = 28;
	const TYPE_SRV   = 33;
	const TYPE_TLSA  = 52;

	const CLASS_IN = 1;

	/** @var string|null Nameserver to query; null = OS resolver via dns_get_record() */
	private ?string $server;
	private int $port;
	private float $timeout;

	public function __construct(?string $server = null, int $port = 53, float $timeout = 4.0) {
		$this->server  = $server;
		$this->port    = $port;
		$this->timeout = $timeout;
	}

	/**
	 * Callable seam compatible with \Enchilada\Core\Onion's resolver hook and
	 * dns_get_record()'s signature: function(string $name, int $type): array.
	 *
	 * @param bool $swallowErrors Return [] on transport failure (dns_get_record parity)
	 */
	public function callable(bool $swallowErrors = true): callable {
		return function (string $name, int $type) use ($swallowErrors): array {
			try {
				return $this->query($name, self::mapPhpType($type));
			}
			catch (Exception $e) {
				if ($swallowErrors) {
					return [];
				}
				throw $e;
			}
		};
	}

	/**
	 * Query records of a given type for a name.
	 *
	 * $type is a WIRE type code (self::TYPE_*). PHP's DNS_* constants are
	 * filter bitmasks, not wire codes — use callable() when interoperating
	 * with dns_get_record()-style call sites.
	 *
	 * Returns rows in dns_get_record() format: each row has host, class, ttl,
	 * type; TXT rows also have txt (joined) and entries (chunks); A/AAAA have
	 * ip/ipv6; CNAME/NS/PTR have target; MX has pri/target; SRV has
	 * pri/weight/port/target.
	 *
	 * @return array Rows (empty when the name or type does not exist)
	 * @throws Exception On transport failure or malformed response
	 */
	public function query(string $name, int $type = self::TYPE_TXT): array {
		if ($this->server === null) {
			$rows = @dns_get_record(rtrim($name, '.'), self::mapWireToPhpType($type));
			return $rows === false ? [] : $rows;
		}

		$name = strtolower(rtrim(trim($name), '.'));
		if ($name === '' || strlen($name) > 253) {
			throw new Exception("Invalid query name");
		}

		$id     = random_int(0, 0xFFFF);
		$packet = $this->buildQuery($id, $name, $type);

		$response = $this->sendUdp($packet);
		// Truncated answer — retry over TCP per RFC 1035 4.2.1
		if (strlen($response) >= 4 && (ord($response[2]) & 0x02)) {
			$response = $this->sendTcp($packet);
		}

		return $this->parseResponse($response, $id, $type);
	}

	private function buildQuery(int $id, string $name, int $type): string {
		$header = pack('n6', $id, 0x0100, 1, 0, 0, 0); // RD set

		$qname = '';
		foreach (explode('.', $name) as $label) {
			$len = strlen($label);
			if ($len < 1 || $len > 63) {
				throw new Exception("Invalid label in query name");
			}
			$qname .= chr($len) . $label;
		}
		$qname .= "\0";

		return $header . $qname . pack('nn', $type, self::CLASS_IN);
	}

	private function sendUdp(string $packet): string {
		$errno = 0; $errstr = '';
		$socket = @stream_socket_client(
			"udp://{$this->server}:{$this->port}",
			$errno, $errstr, $this->timeout
		);
		if ($socket === false) {
			throw new Exception("UDP connect to {$this->server}:{$this->port} failed: {$errstr}");
		}
		stream_set_blocking($socket, false);

		if (@fwrite($socket, $packet) !== strlen($packet)) {
			fclose($socket);
			throw new Exception("UDP send failed");
		}

		$response  = '';
		$deadline  = microtime(true) + $this->timeout;
		while (microtime(true) < $deadline) {
			$read = [$socket]; $write = null; $except = null;
			$wait = max(1, (int) (($deadline - microtime(true)) * 1e6));
			if (stream_select($read, $write, $except, 0, $wait) > 0) {
				$chunk = @fread($socket, 65535);
				if ($chunk === false) {
					break;
				}
				if ($chunk === '') {
					continue;
				}
				$response = $chunk;
				break;
			}
		}
		fclose($socket);

		if ($response === '') {
			throw new Exception("UDP query to {$this->server} timed out");
		}
		return $response;
	}

	private function sendTcp(string $packet): string {
		$errno = 0; $errstr = '';
		$socket = @stream_socket_client(
			"tcp://{$this->server}:{$this->port}",
			$errno, $errstr, $this->timeout
		);
		if ($socket === false) {
			throw new Exception("TCP connect to {$this->server}:{$this->port} failed: {$errstr}");
		}
		stream_set_blocking($socket, true);
		stream_set_timeout($socket, (int) ceil($this->timeout));

		@fwrite($socket, pack('n', strlen($packet)) . $packet);

		$header = @fread($socket, 2);
		if ($header === false || strlen($header) !== 2) {
			fclose($socket);
			throw new Exception("TCP length prefix read failed");
		}
		$length = unpack('n', $header)[1];

		$body = '';
		while (strlen($body) < $length) {
			$chunk = @fread($socket, $length - strlen($body));
			if ($chunk === false || $chunk === '') {
				break;
			}
			$body .= $chunk;
		}
		fclose($socket);

		if (strlen($body) !== $length) {
			throw new Exception("TCP response truncated at " . strlen($body) . " of {$length} bytes");
		}
		return $body;
	}

	private function parseResponse(string $packet, int $id, int $type): array {
		if (strlen($packet) < 12) {
			throw new Exception("Response shorter than DNS header");
		}

		$h = unpack('nid/nflags/nqd/nan/nns/nar', substr($packet, 0, 12));
		if ($h['id'] !== $id) {
			throw new Exception("Response ID mismatch");
		}
		if (!($h['flags'] & 0x8000)) {
			throw new Exception("Response QR bit not set");
		}

		$rcode = $h['flags'] & 0x000F;
		if ($rcode !== 0) {
			// NXDOMAIN et al. are valid "no records" answers, not failures
			return [];
		}

		$offset = 12;

		// Skip the question section
		for ($i = 0; $i < $h['qd']; $i++) {
			[, $offset] = $this->decodeName($packet, $offset);
			$offset += 4; // QTYPE + QCLASS
		}

		$rows = [];
		for ($i = 0; $i < $h['an']; $i++) {
			[$host, $offset] = $this->decodeName($packet, $offset);
			if ($offset + 10 > strlen($packet)) {
				throw new Exception("Truncated answer header");
			}
			$a = unpack('ntype/nclass/Nttl/nrdlength', substr($packet, $offset, 10));
			$offset += 10;
			if ($offset + $a['rdlength'] > strlen($packet)) {
				throw new Exception("Truncated rdata");
			}
			$rdata = substr($packet, $offset, $a['rdlength']);
			$offset += $a['rdlength'];

			if ($a['type'] !== $type) {
				continue;
			}

			$row = [
				'host'  => $host,
				'class' => 'IN',
				'ttl'   => $a['ttl'],
				'type'  => $this->typeName($a['type']),
			];

			switch ($a['type']) {
				case self::TYPE_TXT:
					[$row['txt'], $row['entries']] = $this->decodeTxt($rdata);
					break;
				case self::TYPE_A:
					$row['ip'] = inet_ntop($rdata);
					break;
				case self::TYPE_AAAA:
					$row['ipv6'] = inet_ntop($rdata);
					break;
				case self::TYPE_CNAME:
				case self::TYPE_NS:
				case self::TYPE_PTR:
					[$row['target']] = $this->decodeName($packet, $offset - $a['rdlength']);
					break;
				case self::TYPE_MX:
					// RDATA is PREFERENCE, EXCHANGE (RFC 1035). The preference
					// is two bytes and the exchange name is at least one, so
					// anything shorter than three bytes cannot be an MX record.
					if ($a['rdlength'] < 3) {
						throw new Exception("Truncated MX rdata");
					}
					$row['pri'] = unpack('n', $rdata)[1];
					[$row['target']] = $this->decodeName($packet, $offset - $a['rdlength'] + 2);
					break;
				case self::TYPE_SRV:
					// RDATA is PRIORITY, WEIGHT, PORT, TARGET (RFC 2782). All
					// three numbers are needed: without priority and weight a
					// caller cannot order the targets, which is the whole point
					// of returning more than one.
					if ($a['rdlength'] < 7) {
						throw new Exception("Truncated SRV rdata");
					}
					$srv = unpack('npri/nweight/nport', $rdata);
					$row['pri']    = $srv['pri'];
					$row['weight'] = $srv['weight'];
					$row['port']   = $srv['port'];
					[$row['target']] = $this->decodeName($packet, $offset - $a['rdlength'] + 6);
					break;
				case self::TYPE_TLSA:
					// RDATA is USAGE, SELECTOR, MATCHING-TYPE, CERT-DATA
					// (RFC 6698). The three selector bytes are meaningless
					// without the association data, so shorter is truncated.
					if ($a['rdlength'] < 4) {
						throw new Exception("Truncated TLSA rdata");
					}
					$tlsa = unpack('Cusage/Cselector/Cmatching_type', $rdata);
					$row['usage']         = $tlsa['usage'];
					$row['selector']      = $tlsa['selector'];
					$row['matching_type'] = $tlsa['matching_type'];
					$row['cert_data']     = bin2hex(substr($rdata, 3));
					break;
				default:
					// Types without a decoder still surface their raw
					// wire-format RDATA so callers can handle newer record
					// types without waiting for first-class support here.
					$row['rdata'] = $rdata;
					break;
			}
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Decode a possibly-compressed domain name at $offset.
	 *
	 * @return array{0: string, 1: int} Name (no trailing dot) and the offset of
	 *         the next field after the name
	 * @throws Exception On out-of-bounds or pointer loops
	 */
	private function decodeName(string $packet, int $offset): array {
		$length = strlen($packet);
		$labels = [];
		$next   = 0;  // offset after the name, set at the first pointer
		$hops   = 0;

		while (true) {
			if ($offset >= $length) {
				throw new Exception("Name exceeds packet bounds");
			}
			$len = ord($packet[$offset]);

			if (($len & 0xC0) === 0xC0) {
				// Compression pointer
				if ($offset + 1 >= $length) {
					throw new Exception("Truncated compression pointer");
				}
				$pointed = (($len & 0x3F) << 8) | ord($packet[$offset + 1]);
				if ($pointed >= $length || ++$hops > 16) {
					throw new Exception("Invalid compression pointer");
				}
				if ($next === 0) {
					$next = $offset + 2;
				}
				$offset = $pointed;
				continue;
			}
			if (($len & 0xC0) !== 0) {
				throw new Exception("Unsupported label type");
			}

			$offset++;
			if ($len === 0) {
				break;
			}
			if ($offset + $len > $length) {
				throw new Exception("Truncated label");
			}
			$labels[] = substr($packet, $offset, $len);
			$offset  += $len;
		}

		if ($next === 0) {
			$next = $offset;
		}
		return [implode('.', $labels), $next];
	}

	/**
	 * Decode TXT rdata into its joined value and individual char-strings.
	 *
	 * @return array{0: string, 1: string[]}
	 * @throws Exception On out-of-bounds char-string lengths
	 */
	private function decodeTxt(string $rdata): array {
		$chunks = [];
		$length = strlen($rdata);
		$offset = 0;
		while ($offset < $length) {
			$len = ord($rdata[$offset]);
			$offset++;
			if ($offset + $len > $length) {
				throw new Exception("Truncated TXT char-string");
			}
			$chunks[] = substr($rdata, $offset, $len);
			$offset  += $len;
		}
		return [implode('', $chunks), $chunks];
	}

	/**
	 * Translate a PHP dns_get_record() type flag (DNS_A, DNS_TXT, ...) to the
	 * corresponding wire type code. PHP's constants are bitmasks; a combined
	 * mask resolves to the first set bit we know.
	 */
	public static function mapPhpType(int $flag): int {
		static $map = [
			DNS_A     => self::TYPE_A,
			DNS_NS    => self::TYPE_NS,
			DNS_CNAME => self::TYPE_CNAME,
			DNS_PTR   => self::TYPE_PTR,
			DNS_CAA   => 257,
			DNS_MX    => self::TYPE_MX,
			DNS_TXT   => self::TYPE_TXT,
			DNS_AAAA  => self::TYPE_AAAA,
			DNS_SRV   => self::TYPE_SRV,
			DNS_NAPTR => 35,
		];
		foreach ($map as $phpFlag => $wireType) {
			if ($flag & $phpFlag) {
				return $wireType;
			}
		}
		throw new Exception("Unsupported DNS_* type flag: {$flag}");
	}

	private static function mapWireToPhpType(int $type): int {
		static $map = [
			self::TYPE_A     => DNS_A,
			self::TYPE_NS    => DNS_NS,
			self::TYPE_CNAME => DNS_CNAME,
			self::TYPE_PTR   => DNS_PTR,
			self::TYPE_MX    => DNS_MX,
			self::TYPE_TXT   => DNS_TXT,
			self::TYPE_AAAA  => DNS_AAAA,
			self::TYPE_SRV   => DNS_SRV,
			257              => DNS_CAA,
			35               => DNS_NAPTR,
		];
		if (!isset($map[$type])) {
			throw new Exception("Unsupported wire type: {$type}");
		}
		return $map[$type];
	}

	private function typeName(int $type): string {
		static $map = [
			self::TYPE_A     => 'A',
			self::TYPE_NS    => 'NS',
			self::TYPE_CNAME => 'CNAME',
			self::TYPE_PTR   => 'PTR',
			self::TYPE_MX    => 'MX',
			self::TYPE_TXT   => 'TXT',
			self::TYPE_AAAA  => 'AAAA',
			self::TYPE_SRV   => 'SRV',
			self::TYPE_TLSA  => 'TLSA',
		];
		return $map[$type] ?? (string) $type;
	}
}
