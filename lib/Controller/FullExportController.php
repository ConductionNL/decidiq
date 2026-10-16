<?php

/**
 * Decidiq FullExportController
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
 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\BackgroundJob\FullExportJob;
use OCA\Decidiq\Service\FullExportService;
use OCA\Decidiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\BackgroundJob\IJobList;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Admin only: start the full data export, see the newest one, download it.
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
 */
class FullExportController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest          $request       The request.
	 * @param FullExportService $exportService The export store.
	 * @param IJobList          $jobList       Background jobs.
	 * @param IUserSession      $userSession   The signed-in administrator.
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
	 */
	public function __construct(
		IRequest $request,
		private readonly FullExportService $exportService,
		private readonly IJobList $jobList,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Queue the export; the administrator is notified when it is ready.
	 *
	 * POST /api/export/full
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
	 *
	 * @return JSONResponse
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function start(): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return new JSONResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
		}

		$this->jobList->add(FullExportJob::class, ['uid' => $uid]);
		return new JSONResponse(['queued' => true], Http::STATUS_ACCEPTED);
	}//end start()

	/**
	 * The newest export, or null.
	 *
	 * GET /api/export/full
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
	 *
	 * @return JSONResponse
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function latest(): JSONResponse {
		return new JSONResponse(['export' => $this->exportService->latest()]);
	}//end latest()

	/**
	 * Download an export (the link in the notification).
	 *
	 * GET /api/export/full/{name}
	 *
	 * @param string $name The export's file name.
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
	 *
	 * @return Response
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	#[NoCSRFRequired]
	public function download(string $name): Response {
		try {
			$file = $this->exportService->open(name: $name);
		} catch (NotFoundException $e) {
			return new JSONResponse(['message' => 'No such export.'], Http::STATUS_NOT_FOUND);
		}

		// Streamed: a full export can be larger than the PHP memory limit.
		$response = new StreamResponse($file->read());
		$response->addHeader('Content-Type', 'application/zip');
		$response->addHeader('Content-Disposition', 'attachment; filename="' . $file->getName() . '"');
		return $response;
	}//end download()
}//end class
