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

use OCA\Decidiq\Exception\ExportBundleException;
use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Kernel\Contracts\ExportBundleServiceInterface;
use OCA\Decidiq\Service\ExportRequest;
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
	 * @param ExportBundleServiceInterface $exports The export.
	 * @param LoggerInterface     $logger  Diagnostics.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
	 */
	public function __construct(
		IRequest $request,
		private readonly ExportBundleServiceInterface $exports,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
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
		$exportRequest = $this->exportRequest();

		try {
			$result = $this->exports->export(request: $exportRequest);
		} catch (ExportBundleException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		} catch (Throwable $e) {
			$this->logger->error('Decidiq export: the export failed', ['exception' => $e]);
			return new JSONResponse(
				['message' => 'The export failed. Try again, or read the Nextcloud log for the cause.'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse($result, self::statusOf(result: $result));
	}//end create()

	/**
	 * The export the request asks for.
	 *
	 * @return ExportRequest The format, the list's name and which of its rows.
	 */
	private function exportRequest(): ExportRequest {
		$ids    = $this->request->getParam('ids', []);
		$filter = $this->request->getParam('filter', []);

		$export = new ExportRequest(
			format: (string)$this->request->getParam('format', ''),
			list: (string)$this->request->getParam('list', 'Decisions'),
		);
		$export->ids = array_values(array_map('strval', (array)$ids));
		$export->filter = (array)$filter;
		$export->search = (string)$this->request->getParam('search', '');

		return $export;
	}//end exportRequest()

	/**
	 * 201 for a finished export, 202 for one queued.
	 *
	 * @param array<string,mixed> $result The export's result.
	 *
	 * @return int The status.
	 */
	private static function statusOf(array $result): int {
		if ($result['status'] === 'queued') {
			return Http::STATUS_ACCEPTED;
		}

		return Http::STATUS_CREATED;
	}//end statusOf()
}//end class
