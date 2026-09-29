<?php

/**
 * Decidiq ExportBundleController
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
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Exception\ExportBundleException;
use OCA\Decidiq\Service\ExportBundleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Export selected or filtered motions and decisions with their attachments.
 *
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
 */
class ExportBundleController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest            $request The request.
	 * @param ExportBundleService $exports The export.
	 * @param LoggerInterface     $logger  Diagnostics.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
	 */
	public function __construct(
		IRequest $request,
		private readonly ExportBundleService $exports,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The formats this instance can make: a ZIP always, one PDF with filinq.
	 *
	 * GET /api/exports/decision-bundle/formats
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
	 *
	 * @no-admin-idor-exempt reports whether an app is installed; no object is read.
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function formats(): JSONResponse {
		return new JSONResponse(['zip' => true, 'pdf' => $this->exports->pdfAvailable()]);
	}//end formats()

	/**
	 * Build an export.
	 *
	 * POST /api/exports/decision-bundle with `format` (pdf|zip), `list`
	 * (Motions|Decisions), and `ids` or `filter` + `search`.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-002-a-selection-or-a-filtered-set-exports-as-a-zip-of-its-documents
	 *
	 * @no-admin-idor-exempt every decision is read through OpenRegister as the caller, so its read rules decide
	 *                       what the export holds, and filinq reads each attachment as the caller too.
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$ids    = $this->request->getParam('ids', []);
		$filter = $this->request->getParam('filter', []);

		try {
			$result = $this->exports->export(
				format: (string)$this->request->getParam('format', ''),
				list: (string)$this->request->getParam('list', 'Decisions'),
				ids: array_values(array_map('strval', (array)$ids)),
				filter: (array)$filter,
				search: (string)$this->request->getParam('search', ''),
			);
		} catch (ExportBundleException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		} catch (Throwable $e) {
			$this->logger->error('Decidiq export: the export failed', ['exception' => $e]);
			return new JSONResponse(['message' => 'The export failed. Try again, or read the Nextcloud log for the cause.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$status = Http::STATUS_CREATED;
		if ($result['status'] === 'queued') {
			$status = Http::STATUS_ACCEPTED;
		}

		return new JSONResponse($result, $status);
	}//end create()
}//end class
