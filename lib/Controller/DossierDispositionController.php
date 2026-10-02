<?php

/**
 * Decidiq Dossier Disposition Controller
 *
 * Where a closed archival dossier goes (transfer or destruction, by its
 * Selectielijst category), handing it to OpenRegister's list, and reading
 * back what OpenRegister carried out.
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
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
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
use OCA\Decidiq\Service\Records\DestructionCertificateRenderer;
use OCA\Decidiq\Service\Records\DossierDisposition;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Route a closed dossier to OpenRegister and reflect the outcome.
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
 */
class DossierDispositionController extends Controller {
	use GovernanceControllerTrait;

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request      HTTP request
	 * @param DossierDisposition $disposition  The routing rules
	 * @param DestructionCertificateRenderer $certificates Renders OpenRegister's certificate
	 * @param IUserSession       $userSession  User session
	 * @param LoggerInterface    $logger       Logger
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
	 */
	public function __construct(
		IRequest $request,
		private readonly DossierDisposition $disposition,
		private readonly DestructionCertificateRenderer $certificates,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Where the dossier goes and whether OpenRegister can take it there now.
	 * The service refuses anyone but an archivist or an administrator.
	 *
	 * @param string $id The dossier
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		return $this->respond(action: fn (): array => $this->disposition->describe(dossierId: $id), created: false);
	}//end show()

	/**
	 * Hand the closed dossier to OpenRegister's transfer or destruction list.
	 * The service refuses anyone but an archivist or an administrator.
	 *
	 * @param string $id The dossier
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function propose(string $id): JSONResponse {
		return $this->respond(action: fn (): array => $this->disposition->propose(dossierId: $id), created: true);
	}//end propose()

	/**
	 * Read what OpenRegister did with the dossier's list.
	 * The service refuses anyone but an archivist or an administrator.
	 *
	 * @param string $id The dossier
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-005-destruction-via-openregister-destruction-lists
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function outcome(string $id): JSONResponse {
		return $this->respond(action: fn (): array => $this->disposition->reflectOutcome(dossierId: $id), created: false);
	}//end outcome()

	/**
	 * Render OpenRegister's destruction certificate for the dossier and file it.
	 * The service refuses anyone but an archivist or an administrator.
	 *
	 * @param string $id The dossier
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-006-vernietigingsverklaring-rendering
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function certificate(string $id): JSONResponse {
		return $this->respond(action: fn (): array => $this->certificates->render(dossierId: $id), created: true);
	}//end certificate()

	/**
	 * Run an action and map its outcome: 401 signed out, 403 no authority,
	 * 404 unknown, 409 a conflict with state, 422 not possible, 500 failure.
	 *
	 * @param callable(): array<string, mixed> $action  The action
	 * @param bool                             $created Whether success is a 201
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
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
			if ($e->isConflict() === true) {
				$status = Http::STATUS_CONFLICT;
			}

			return new JSONResponse(['message' => $e->getMessage(), 'reason' => $e->getReason(), 'refused' => $e->getGaps()], $status);
		} catch (Throwable $e) {
			$this->logger->error('Decidiq: dossier disposition failed', ['exception' => $e]);
			return new JSONResponse(['message' => 'The dossier could not be handed to OpenRegister.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end respond()
}//end class
