<?php

/**
 * Decidiq Forbidden Result
 *
 * A service result that an authorization guard refused, as the 403 the
 * guarded controllers answer with.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-12-board-proxy-vote-authorization-guard/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Maps a guard's refusal to a bare 403.
 *
 * @spec openspec/changes/archive/2026-11-12-board-proxy-vote-authorization-guard/tasks.md#task-2
 */
final class ForbiddenResult {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $result The service result
	 */
	public function __construct(
		private readonly array $result,
	) {
	}//end __construct()

	/**
	 * The 403 for a failed result whose message starts with `Forbidden:` (in
	 * any case), with a static message; null for any other result.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/changes/archive/2026-11-12-board-proxy-vote-authorization-guard/tasks.md#task-2
	 */
	public function response(): ?JSONResponse {
		if (($this->result['success'] ?? false) === false
			&& stripos((string)($this->result['message'] ?? ''), 'Forbidden:') === 0
		) {
			return new JSONResponse(['message' => 'Forbidden.'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end response()
}//end class
