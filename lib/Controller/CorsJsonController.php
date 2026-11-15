<?php

/**
 * Decidiq CORS JSON controller base
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-05-11-p4-integration/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\Kernel\AppIdentity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Shared answers of the controllers that serve cross-origin JSON: the CORS
 * header triple, the error envelope and the empty preflight answer.
 *
 * @spec openspec/changes/archive/2026-05-11-p4-integration/tasks.md#task-1.4
 */
abstract class CorsJsonController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object
	 * @param IConfig $config The Nextcloud config service (overwrite.cli.url is the allowed origin)
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		protected readonly IConfig $config,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * The empty 200 answer to a CORS preflight.
	 *
	 * @return JSONResponse HTTP 200 with Access-Control-* headers
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-integration/tasks.md#task-1.4
	 */
	protected function preflightResponse(): JSONResponse {
		$response = new JSONResponse([], Http::STATUS_OK);
		$this->applyCorsHeaders(response: $response);

		return $response;
	}//end preflightResponse()

	/**
	 * Build a consistent JSON error envelope (REQ-API-003).
	 *
	 * @param string $message The user-facing message
	 * @param int $status The HTTP status code
	 *
	 * @return JSONResponse The decorated error response
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-integration/tasks.md#task-1.2
	 */
	protected function errorResponse(string $message, int $status): JSONResponse {
		$response = new JSONResponse(['message' => $message, 'code' => $status], $status);
		$this->applyCorsHeaders(response: $response);

		return $response;
	}//end errorResponse()

	/**
	 * Apply CORS headers using the configured proxy origin when available.
	 *
	 * @param JSONResponse $response The response to decorate
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-integration/tasks.md#task-1.4
	 * @spec openspec/changes/archive/2026-05-11-p4-integration/tasks.md#task-10.4
	 */
	protected function applyCorsHeaders(JSONResponse $response): void {
		$origin = $this->config->getSystemValueString(key: 'overwrite.cli.url', default: '*');

		$allowedOrigin = '*';
		if ($origin !== '') {
			$allowedOrigin = $origin;
		}

		$response->addHeader(name: 'Access-Control-Allow-Origin', value: $allowedOrigin);
		$response->addHeader(name: 'Access-Control-Allow-Methods', value: 'GET, OPTIONS');
		$response->addHeader(name: 'Access-Control-Allow-Headers', value: 'Authorization, Content-Type, X-Requested-With');

	}//end applyCorsHeaders()
}//end class
