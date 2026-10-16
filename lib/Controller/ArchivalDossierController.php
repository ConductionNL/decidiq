<?php

/**
 * Decidiq Archival Dossier Controller
 *
 * The three dossier actions OpenRegister cannot serve: form a meeting's
 * dossier, gather its records again, and close it. Reading a dossier goes
 * through OpenRegister's object API; transfer and destruction through
 * OpenRegister's archival routes.
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
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\DossierRefusedException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\ArchivalDossierService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Form, gather and close an archival dossier.
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
 */
class ArchivalDossierController extends Controller {
	use GovernanceControllerTrait;

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request     HTTP request
	 * @param ArchivalDossierService $dossiers    The dossier rules
	 * @param IUserSession           $userSession User session
	 * @param LoggerInterface        $logger      Logger
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	public function __construct(
		IRequest $request,
		private readonly ArchivalDossierService $dossiers,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Form the meeting's dossier, or answer the one it has. The service
	 * refuses anyone but the meeting's chair, secretary or an administrator.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function form(string $meetingId): JSONResponse {
		return $this->respond(action: fn (): array => $this->dossiers->formForMeeting(meetingId: $meetingId), created: true);
	}//end form()

	/**
	 * Gather a forming dossier's records again. Authority is checked per
	 * dossier, on its meeting, by the service.
	 *
	 * @param string $id The dossier
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function assemble(string $id): JSONResponse {
		return $this->respond(action: fn (): array => $this->dossiers->assemble(dossierId: $id), created: false);
	}//end assemble()

	/**
	 * Close a dossier. Body: {"overrideReason": "..."} when it has gaps.
	 * Authority is checked per dossier, on its meeting, by the service.
	 *
	 * @param string      $id             The dossier
	 * @param string|null $overrideReason Why it closes despite its gaps
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function close(string $id, ?string $overrideReason = null): JSONResponse {
		return $this->respond(action: fn (): array => $this->dossiers->close(dossierId: $id, overrideReason: $overrideReason), created: false);
	}//end close()

	/**
	 * Run a dossier action and map its outcome to a response: 401 signed
	 * out, 403 no authority, 404 unknown, 409 frozen, 422 gaps, 500 failure.
	 *
	 * @param callable(): array<string, mixed> $action  The action
	 * @param bool                             $created Whether success is a 201
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 *
	 * @return JSONResponse
	 */
	private function respond(callable $action, bool $created): JSONResponse {
		$auth = $this->requireUserOr401(session: $this->userSession);
		if ($auth !== null) {
			return $auth;
		}

		try {
			$status = Http::STATUS_OK;
			if ($created === true) {
				$status = Http::STATUS_CREATED;
			}

			return new JSONResponse(['dossier' => $action()], $status);
		} catch (AccessDeniedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (MissingObjectException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (DossierRefusedException $e) {
			$status = Http::STATUS_UNPROCESSABLE_ENTITY;
			if ($e->getReason() === DossierRefusedException::FROZEN) {
				$status = Http::STATUS_CONFLICT;
			}

			return new JSONResponse(['message' => $e->getMessage(), 'reason' => $e->getReason(), 'gaps' => $e->getGaps()], $status);
		} catch (Throwable $e) {
			$this->logger->error('Decidiq: archival dossier action failed', ['exception' => $e]);
			return new JSONResponse(['message' => 'The dossier could not be saved.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end respond()
}//end class
