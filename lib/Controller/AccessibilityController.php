<?php

/**
 * On-demand accessibility scan of one paper.
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
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-001-heuristic-accessibility-scan-of-uploaded-pdf-documents
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Decidiq\Service\DocumentAccessibilityScanService;
use OCA\Decidiq\Service\PaperFolderOwner;
use OCA\Decidiq\Support\OpenRegisterFiles;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * POST /api/accessibility/scan scans one paper of a meeting or agenda item now
 * and answers with its report. The caller must be able to read the source
 * object under OpenRegister's own RBAC, and the file must be one of that
 * object's papers, so no other file can be scanned through it. CRUD on the
 * reports stays on the OpenRegister object API.
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-001-heuristic-accessibility-scan-of-uploaded-pdf-documents
 */
class AccessibilityController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                         $request        The request.
	 * @param IUserSession                     $userSession    The session.
	 * @param ObjectServiceInterface           $objectService  The OpenRegister object service.
	 * @param ListenerSchemaResolverInterface  $schemaResolver Reads an object's schema slug.
	 * @param OpenRegisterFiles                $files          OpenRegister's files, to list the object's papers.
	 * @param DocumentAccessibilityScanService $scanner        The scanner.
	 * @param LoggerInterface                  $logger         The logger.
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-001-heuristic-accessibility-scan-of-uploaded-pdf-documents
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ObjectServiceInterface $objectService,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly OpenRegisterFiles $files,
		private readonly DocumentAccessibilityScanService $scanner,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Scan one paper now.
	 *
	 * Body: `{sourceObject, fileId}`. 200 with the stored report; 413 with the
	 * report (saved as `not-scanned`) when the file is over the size cap.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-001-heuristic-accessibility-scan-of-uploaded-pdf-documents
	 */
	#[NoAdminRequired]
	public function scan(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
		}

		$sourceObject = (string)$this->request->getParam('sourceObject', '');
		$fileId       = (int)$this->request->getParam('fileId', 0);
		if ($sourceObject === '' || $fileId <= 0) {
			return new JSONResponse(['message' => 'sourceObject and fileId are required.'], Http::STATUS_BAD_REQUEST);
		}

		$source = $this->readableSource(objectId: $sourceObject);
		if ($source === null) {
			return new JSONResponse(['message' => 'You may not scan the papers of this object.'], Http::STATUS_FORBIDDEN);
		}

		if ($this->holdsPaper(objectId: $sourceObject, fileId: $fileId) === false) {
			return new JSONResponse(['message' => 'This file is not a paper of this object.'], Http::STATUS_NOT_FOUND);
		}

		try {
			$report = $this->scanner->scanFile(
				fileId: $fileId,
				sourceObject: $sourceObject,
				sourceSchema: $source['schema'],
				governanceBody: $this->bodyOf(source: $source)
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: an on-demand accessibility scan broke off', ['fileId' => $fileId, 'error' => $e->getMessage()]);
			return new JSONResponse(['message' => 'The paper could not be scanned.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($report, $this->statusOf(report: $report));
	}//end scan()

	/**
	 * The meeting or agenda item the caller may read, with its schema, or null.
	 *
	 * Read under OpenRegister's RBAC for the caller; a refusal, a missing object
	 * and any other schema all answer null, so existence is not revealed.
	 *
	 * @param string $objectId The object uuid.
	 *
	 * @return array{schema: string, object: array<string, mixed>}|null
	 */
	private function readableSource(string $objectId): ?array {
		try {
			$found = $this->objectService->find(id: $objectId, register: 'decidiq', _rbac: true, _multitenancy: true);
		} catch (\Throwable) {
			return null;
		}

		if ($found === null) {
			return null;
		}

		$object = (array)$found->getObject();
		$schema = $this->schemaResolver->schemaSlug(entity: $found, row: $object);
		if (in_array($schema, PaperFolderOwner::SCHEMAS, true) === false) {
			return null;
		}

		return ['schema' => $schema, 'object' => $object];
	}//end readableSource()

	/**
	 * Whether the file is one of the object's papers.
	 *
	 * @param string $objectId The object uuid.
	 * @param int    $fileId   The file id.
	 *
	 * @return bool
	 */
	private function holdsPaper(string $objectId, int $fileId): bool {
		if ($this->files->installed() === false) {
			return false;
		}

		try {
			$nodes = (array)$this->files->fileService()->getFiles($objectId);
		} catch (\Throwable) {
			return false;
		}

		foreach ($nodes as $node) {
			if (is_object($node) === true && method_exists($node, 'getId') === true && (int)$node->getId() === $fileId) {
				return true;
			}
		}

		return false;
	}//end holdsPaper()

	/**
	 * The governance body of the source's meeting, or null.
	 *
	 * @param array{schema: string, object: array<string, mixed>} $source The source.
	 *
	 * @return string|null
	 */
	private function bodyOf(array $source): ?string {
		$meeting = $source['object'];
		if ($source['schema'] === 'agenda-item') {
			$meeting = $this->meeting(meetingId: (string)($source['object']['meeting'] ?? ''));
		}

		$body = (string)($meeting['governanceBody'] ?? '');
		if ($body === '') {
			return null;
		}

		return $body;
	}//end bodyOf()

	/**
	 * An agenda item's meeting, or [] when it cannot be read.
	 *
	 * @param string $meetingId The meeting uuid.
	 *
	 * @return array<string, mixed>
	 */
	private function meeting(string $meetingId): array {
		if ($meetingId === '') {
			return [];
		}

		try {
			$found = $this->objectService->find(id: $meetingId, register: 'decidiq', schema: 'meeting', _rbac: false, _multitenancy: false);
		} catch (\Throwable) {
			return [];
		}

		if ($found === null) {
			return [];
		}

		return (array)$found->getObject();
	}//end meeting()

	/**
	 * 413 for a report that names the file too large to scan, 200 otherwise.
	 *
	 * @param array<string, mixed> $report The stored report.
	 *
	 * @return int
	 */
	private function statusOf(array $report): int {
		foreach ((array)($report['findings'] ?? []) as $finding) {
			if (is_array($finding) === true && ($finding['code'] ?? '') === 'too-large') {
				return Http::STATUS_REQUEST_ENTITY_TOO_LARGE;
			}
		}

		return Http::STATUS_OK;
	}//end statusOf()
}//end class
