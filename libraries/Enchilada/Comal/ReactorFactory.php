<?php
/**
 * Enchilada Comal — Reactor Factory
 *
 * Auto-detects the best available event loop backend and creates
 * the appropriate reactor instance. Priority order:
 *   1. ext-ev (libev) — EvReactor
 *   2. ext-event (libevent2) — EventReactor
 *   3. stream_select (pure PHP) — SelectReactor
 *
 * @package    Enchilada\Comal
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace Enchilada\Comal;

class ReactorFactory
{
	/**
	 * Create a reactor instance.
	 *
	 * @param string|null $backend Explicit backend: 'ev', 'event', 'select', or null for auto-detect
	 * @return ReactorInterface
	 * @throws \RuntimeException If the requested backend is not available
	 */
	public static function create(?string $backend = null): ReactorInterface
	{
		if ($backend !== null) {
			return self::createExplicit($backend);
		}

		return self::autoDetect();
	}

	/**
	 * Auto-detect the best available backend.
	 */
	private static function autoDetect(): ReactorInterface
	{
		if (extension_loaded('ev')) {
			return new EvReactor();
		}

		if (extension_loaded('event')) {
			return new EventReactor();
		}

		return new SelectReactor();
	}

	/**
	 * Create a specific backend by name.
	 *
	 * @throws \RuntimeException If the backend is not available
	 */
	private static function createExplicit(string $backend): ReactorInterface
	{
		return match ($backend) {
			'ev' => new EvReactor(),
			'event' => new EventReactor(),
			'select' => new SelectReactor(),
			'kqueue' => new KqueueReactor(),
			default => throw new \RuntimeException("Unknown reactor backend: {$backend}"),
		};
	}

	/**
	 * Get the name of the backend that would be auto-detected.
	 *
	 * @return string 'ev', 'event', or 'select'
	 */
	public static function detectBackend(): string
	{
		if (extension_loaded('ev')) {
			return 'ev';
		}

		if (extension_loaded('event')) {
			return 'event';
		}

		return 'select';
	}
}
