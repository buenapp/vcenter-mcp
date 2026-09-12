<?php
/**
 * vCenter MCP Server — Console Framebuffer
 *
 * 32bpp framebuffer filled by RFB Raw rectangles. Pixels are stored as
 * received (4 bytes per pixel, little-endian u32, channels per the
 * negotiated SetPixelFormat: red<<16 | green<<8 | blue, pad byte high).
 * toRgb() packs to 3-byte RGB for the PNG encoder; coverage tracking
 * reports how much of the frame has been painted.
 *
 * @package    VCenterMCP\VCenter\Console
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace VCenter\Console;

use VCenter\VCenterException;

class Framebuffer
{
	private int $width;
	private int $height;

	/** @var string w*h*4 raw pixel bytes */
	private string $pixels;

	/** @var string w*h bytes: "\1" where painted */
	private string $painted;

	private int $paintedCount = 0;

	public function __construct(int $width, int $height)
	{
		if ($width <= 0 || $height <= 0) {
			throw new VCenterException("Framebuffer: invalid dimensions {$width}x{$height}", 0, 'Framebuffer');
		}
		$this->width = $width;
		$this->height = $height;
		$this->pixels = str_repeat("\0", $width * $height * 4);
		$this->painted = str_repeat("\0", $width * $height);
	}

	public function width(): int
	{
		return $this->width;
	}

	public function height(): int
	{
		return $this->height;
	}

	/**
	 * Write a Raw-encoding rectangle of 32bpp pixels.
	 *
	 * @param string $rawPixels w*h*4 bytes, row-major
	 * @throws VCenterException On out-of-bounds rect or bad length
	 */
	public function blit(int $x, int $y, int $w, int $h, string $rawPixels): void
	{
		if ($x < 0 || $y < 0 || $w <= 0 || $h <= 0
			|| $x + $w > $this->width || $y + $h > $this->height) {
			throw new VCenterException(
				"Framebuffer: rect {$x},{$y} {$w}x{$h} out of bounds {$this->width}x{$this->height}",
				0, 'Framebuffer'
			);
		}
		if (strlen($rawPixels) !== $w * $h * 4) {
			throw new VCenterException(
				'Framebuffer: expected ' . ($w * $h * 4) . ' pixel bytes, got ' . strlen($rawPixels),
				0, 'Framebuffer'
			);
		}

		for ($row = 0; $row < $h; $row++) {
			$dst = (($y + $row) * $this->width + $x);
			$this->pixels = substr_replace(
				$this->pixels,
				substr($rawPixels, $row * $w * 4, $w * 4),
				$dst * 4,
				$w * 4
			);
			$slice = substr($this->painted, $dst, $w);
			$this->paintedCount += $w - substr_count($slice, "\1");
			$this->painted = substr_replace($this->painted, str_repeat("\1", $w), $dst, $w);
		}
	}

	/** Fraction of the frame painted (0.0 - 1.0). */
	public function coverage(): float
	{
		return $this->paintedCount / ($this->width * $this->height);
	}

	/** True when every pixel has been written. */
	public function complete(): bool
	{
		return $this->paintedCount === $this->width * $this->height;
	}

	/** Packed RGB (3 bytes/pixel), channels per our negotiated format. */
	public function toRgb(): string
	{
		$out = '';
		$count = $this->width * $this->height;
		for ($i = 0; $i < $count; $i++) {
			$v = unpack('V', $this->pixels, $i * 4)[1];
			$out .= chr(($v >> 16) & 0xFF) . chr(($v >> 8) & 0xFF) . chr($v & 0xFF);
		}
		return $out;
	}
}
