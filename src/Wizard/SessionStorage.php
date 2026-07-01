<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

/**
 * $_SESSION-backed {@see Storage}. The caller is responsible for having started the
 * session (session_start()) before use.
 */
final class SessionStorage implements Storage
{
	public function read(string $key): array
	{
		$value = $_SESSION[$key] ?? [];

		return is_array($value) ? $value : [];
	}

	public function write(string $key, array $data): void
	{
		$_SESSION[$key] = $data;
	}
}
