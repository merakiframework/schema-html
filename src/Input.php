<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use ArrayAccess;

/**
 * A normalized, read-only view over request input that smooths over PHP's
 * request-data quirks so it can be fed straight to a schema:
 *
 * - Empty strings (a present-but-unfilled field) become null. Presence is still
 *   recoverable via has(): a submitted-but-empty field is has()===true with
 *   get()===null, whereas an absent field is has()===false.
 * - The checkbox convention 'on' becomes boolean true.
 * - Uploaded files are merged in alongside body fields, keyed by input name,
 *   each as a { name, type, size } metadata array (ready for the File field).
 * - Nested names like price[amount] are accessible as arrays, objects, or via
 *   chained get(): $input->get('price')->get('amount').
 *
 * Build it from PSR-7 (fromPsrRequest), the superglobals (fromGlobals), or a
 * single already-merged array (the constructor, mainly for tests).
 *
 * @implements ArrayAccess<string, mixed>
 */
final class Input implements ArrayAccess
{
	/** @var array<array-key, mixed> */
	private array $data;

	/**
	 * @param array<array-key, mixed> $data A single, already-merged body+files array.
	 */
	public function __construct(array $data)
	{
		$this->data = self::normalize($data);
	}

	public static function fromGlobals(): self
	{
		return new self(array_replace_recursive($_POST, self::normalizeUploadedFiles($_FILES)));
	}

	public static function fromPsrRequest(ServerRequestInterface $request): self
	{
		$body = $request->getParsedBody();
		$body = is_array($body) ? $body : [];

		return new self(array_replace_recursive($body, self::normalizePsrFiles($request->getUploadedFiles())));
	}

	public function get(string $name, mixed $default = null): mixed
	{
		$value = array_key_exists($name, $this->data) ? $this->data[$name] : $default;

		return is_array($value) ? new self($value) : $value;
	}

	public function has(string $name): bool
	{
		return array_key_exists($name, $this->data);
	}

	/**
	 * @return array<array-key, mixed>
	 */
	public function toArray(): array
	{
		return $this->data;
	}

	public function offsetExists(mixed $offset): bool
	{
		return $this->has((string) $offset);
	}

	public function offsetGet(mixed $offset): mixed
	{
		return $this->get((string) $offset);
	}

	public function offsetSet(mixed $offset, mixed $value): void
	{
		throw new \LogicException('Input is read-only.');
	}

	public function offsetUnset(mixed $offset): void
	{
		throw new \LogicException('Input is read-only.');
	}

	public function __get(string $name): mixed
	{
		return $this->get($name);
	}

	public function __isset(string $name): bool
	{
		return $this->has($name);
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @return array<array-key, mixed>
	 */
	private static function normalize(array $data): array
	{
		$normalized = [];

		foreach ($data as $key => $value) {
			$normalized[$key] = self::normalizeValue($value);
		}

		return $normalized;
	}

	private static function normalizeValue(mixed $value): mixed
	{
		if (is_array($value)) {
			return self::normalize($value);
		}

		// A present-but-unfilled field arrives as '' rather than being absent.
		if ($value === '') {
			return null;
		}

		// Checked checkboxes submit the string 'on'; unchecked ones are absent.
		if ($value === 'on') {
			return true;
		}

		return $value;
	}

	/**
	 * Reorganises the $_FILES superglobal into a name-keyed structure of
	 * { name, type, size } metadata arrays (mirroring nested input names).
	 *
	 * @param array<array-key, mixed> $files
	 * @return array<array-key, mixed>
	 */
	private static function normalizeUploadedFiles(array $files): array
	{
		$normalized = [];

		foreach ($files as $key => $info) {
			if (!is_array($info) || !isset($info['name'])) {
				continue;
			}

			$entry = self::reorganizeFile($info);

			if ($entry !== null) {
				$normalized[$key] = $entry;
			}
		}

		return $normalized;
	}

	/**
	 * @param array{name: mixed, type: mixed, size: mixed, error?: mixed} $info
	 */
	private static function reorganizeFile(array $info): mixed
	{
		if (!is_array($info['name'])) {
			return self::fileMetadata($info);
		}

		$result = [];

		foreach (array_keys($info['name']) as $key) {
			$entry = self::reorganizeFile([
				'name' => $info['name'][$key],
				'type' => $info['type'][$key] ?? null,
				'size' => $info['size'][$key] ?? null,
				'error' => $info['error'][$key] ?? UPLOAD_ERR_OK,
			]);

			if ($entry !== null) {
				$result[$key] = $entry;
			}
		}

		return $result === [] ? null : $result;
	}

	/**
	 * @param array{name: mixed, type: mixed, size: mixed, error?: mixed} $info
	 * @return array{name: mixed, type: mixed, size: mixed}|null
	 */
	private static function fileMetadata(array $info): ?array
	{
		if (($info['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
			return null;
		}

		return [
			'name' => $info['name'],
			'type' => $info['type'],
			'size' => $info['size'],
		];
	}

	/**
	 * @param array<array-key, UploadedFileInterface|array> $files
	 * @return array<array-key, mixed>
	 */
	private static function normalizePsrFiles(array $files): array
	{
		$normalized = [];

		foreach ($files as $key => $file) {
			if (is_array($file)) {
				$nested = self::normalizePsrFiles($file);

				if ($nested !== []) {
					$normalized[$key] = $nested;
				}

				continue;
			}

			if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
				continue;
			}

			$normalized[$key] = [
				'name' => $file->getClientFilename(),
				'type' => $file->getClientMediaType(),
				'size' => $file->getSize(),
			];
		}

		return $normalized;
	}
}
