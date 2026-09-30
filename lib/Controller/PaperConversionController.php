<?php

/**
 * Lets the chair or the secretariat try the PDF conversion of an Office paper again.
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
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-002-a-failed-or-impossible-conversion-is-visible-and-the-original-stays
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\BackgroundJob\ConvertPaperToPdfJob;
use OCA\Decidiq\Service\AgendaAuthorizationGuard;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;

/**
 * POST /api/papers/{schema}/{objectId}/{fileId}/convert queues a new
 * conversion of a paper the object already records. Only the chair, the
 * secretary or an administrator of the meeting may ask; the paper must be
 * one of the object's own, so no other file can be converted through it.
 *
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-002-a-failed-or-impossible-conversion-is-visible-and-the-original-stays
 */
class PaperConversionController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request       The request.
	 * @param ObjectServiceInterface   $objectService The OpenRegister object service.
	 * @param AgendaAuthorizationGuard $guard         Chair, secretary or admin of a meeting.
	 * @param IJobList                 $jobList       The background job list.
	 *
	 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-002-a-failed-or-impossible-conversion-is-visible-and-the-original-stays
	 */
	public function __construct(
		IRequest $request,
		private readonly ObjectServiceInterface $objectService,
		private readonly AgendaAuthorizationGuard $guard,
		private readonly IJobList $jobList,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Queue a new conversion of one paper.
	 *
	 * @param string $schema   `agenda-item` or `meeting`.
	 * @param string $objectId The object uuid.
	 * @param int    $fileId   The Office paper's file id.
	 *
	 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-002-a-failed-or-impossible-conversion-is-visible-and-the-original-stays
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function convert(string $schema, string $objectId, int $fileId): JSONResponse {
		$denied = $this->guard->requireUser();
		if ($denied !== null) {
			return $denied;
		}

		if (in_array($schema, ['agenda-item', 'meeting'], true) === false) {
			return new JSONResponse(['message' => 'Papers are converted for meetings and agenda items only.'], Http::STATUS_BAD_REQUEST);
		}

		$object = $this->objectService->find(id: $objectId, register: 'decidiq', schema: $schema, _rbac: false, _multitenancy: false);
		if ($object === null) {
			return new JSONResponse(['message' => 'Not found.'], Http::STATUS_NOT_FOUND);
		}

		$data      = $object->getObject();
		$meetingId = $objectId;
		if ($schema === 'agenda-item') {
			$meetingId = (string)($data['meeting'] ?? '');
		}

		if ($meetingId === '') {
			return new JSONResponse(['message' => 'Chair or secretary role required for this meeting'], Http::STATUS_FORBIDDEN);
		}

		$denied = $this->guard->requireChairOrAdmin(meetingId: $meetingId);
		if ($denied !== null) {
			return $denied;
		}

		if ($this->recordsPaper(data: $data, fileId: $fileId) === false) {
			return new JSONResponse(['message' => 'This file is not a paper of this page.'], Http::STATUS_NOT_FOUND);
		}

		$this->jobList->add(ConvertPaperToPdfJob::class, ['fileId' => $fileId, 'objectId' => $objectId, 'schema' => $schema]);
		return new JSONResponse(['queued' => true], Http::STATUS_ACCEPTED);
	}//end convert()

	/**
	 * Whether the object records the paper.
	 *
	 * @param array<string, mixed> $data   The object.
	 * @param int                  $fileId The file id.
	 *
	 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-002-a-failed-or-impossible-conversion-is-visible-and-the-original-stays
	 *
	 * @return bool
	 */
	private function recordsPaper(array $data, int $fileId): bool {
		foreach ((array)($data['paperRenditions'] ?? []) as $entry) {
			if (is_array($entry) === true && (int)($entry['sourceFileId'] ?? 0) === $fileId) {
				return true;
			}
		}

		return false;
	}//end recordsPaper()
}//end class
