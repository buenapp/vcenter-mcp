<?php
/**
 * vCenter MCP Server — Console Framebuffer
 *
 * 32bpp framebuffer filled by RFB Raw rectangles. Pixels are stored as
 * received (4 bytes per pixel, little-endian u32, channels per the
 * negotiated SetPixelFormat: red<<16 | green<<8 | blue, pad byte high),
 * one string per row so a blit copies only the rows it touches.
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

	/** @var string[] One w*4-byte string of raw pixels per row */
	private array $rows;

	/** @var string[] One w-byte string per row: "\1" where painted */
	private array $painted;

	private int $paintedCount = 0;

	public function __construct(int $width, int $height)
	{
		if ($width <= 0 || $height <= 0) {
			throw new VCenterException("Framebuffer: invalid dimensions {$width}x{$height}", 0, 'Framebuffer');
		}
		$this->width = $width;
		$this->height = $height;
		$this->rows = array_fill(0, $height, str_repeat("\0", $width * 4));
		$this->painted = array_fill(0, $height, str_repeat("\0", $width));
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

		$span = $w * 4;
		$mark = str_repeat("\1", $w);
		for ($row = 0; $row < $h; $row++) {
			$r = $y + $row;
			$this->rows[$r] = $w === $this->width
				? substr($rawPixels, $row * $span, $span)
				: substr_replace($this->rows[$r], substr($rawPixels, $row * $span, $span), $x * 4, $span);
			$this->paintedCount += $w - substr_count($this->painted[$r], "\1", $x, $w);
			$this->painted[$r] = substr_replace($this->painted[$r], $mark, $x, $w);
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

	/** Packed RGB (3 bytes/pixel): each stored pixel is B,G,R,pad in
	 *  memory (little-endian red<<16 | green<<8 | blue). */
	public function toRgb(): string
	{
		$rgb = preg_replace('/(.)(.)(.)./s', '$3$2$1', implode('', $this->rows));
		if ($rgb === null) {
			throw new VCenterException('Framebuffer: RGB conversion failed: ' . preg_last_error_msg(), 0, 'Framebuffer');
		}
		return $rgb;
	}
}
