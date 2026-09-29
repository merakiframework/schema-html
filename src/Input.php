<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Form data as it arrived over HTTP, merged into one array and smoothed over the one quirk
 * every form has: an untouched text box submits `''`.
 *
 * This is *transport* and nothing else — where the data came from, not what it means. Turning
 * it into the objects a schema validates is {@see Request\PayloadMapper}'s job, because only a
 * schema can say which nested array is a record and which is a list of rows.
 *
 * - Empty strings become null. `meraki/schema` treats `''` as a real answer (an address
 *   part submitted as `''` was *said*), so the artefact of a box nobody typed in has to be
 *   stripped here, before it reaches the core.
 * - Uploaded files are merged in alongside body fields, keyed by input name, each as the
 *   `{ name, type, size }` record the core's `File` field reads.
 * - Nothing else is converted. A checkbox's `on` is left as it is: only a Boolean field
 *   means "checked" by it, and the mapper knows which fields those are. Converting it here
 *   turned an Enum case or a text answer of "on" into `true`.
 *
 * Normalising is idempotent, so already-normalised data passes through unchanged.
 */
final class Input
{
	/** @var array<array-key, mixed> */
	private readonly array $data;

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

	/**
	 * @return array<array-key, mixed>
	 */
	public function toArray(): array
	{
		return $this->data;
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @return array<array-key, mixed>
	 */
	private static function normalize(array $data): array
	{
		$normalized = [];

		foreach ($data as $key => $value) {
			$normalized[$key] = match (true) {
				is_array($value) => self::normalize($value),
				// A present-but-unfilled field arrives as '' rather than being absent.
				$value === '' => null,
				default => $value,
			};
		}

		return $normalized;
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
