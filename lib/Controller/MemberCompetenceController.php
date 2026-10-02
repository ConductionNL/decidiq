<?php

/**
 * Decidiq Member Competence Controller
 *
 * The competence writes OpenRegister cannot guard: a body's competences,
 * a member's competences and confirming one. Reading them goes through
 * OpenRegister's object API (bodies-board-composition-skills-and-diversity).
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
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\CompetenceRefusedException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\MemberCompetenceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Save competences and confirm a member competence.
 *
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
 */
class MemberCompetenceController extends Controller {
	use GovernanceControllerTrait;

	/**
	 * Constructor.
	 *
	 * @param IRequest                $request     HTTP request
	 * @param MemberCompetenceService $competences The competence writes
	 * @param IUserSession            $userSession User session
	 * @param LoggerInterface         $logger      Logger
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
	 */
	public function __construct(
		IRequest $request,
		private readonly MemberCompetenceService $competences,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Add a competence to a body. Body: governanceBody, name, description,
	 * requiredHolders, order. The service refuses anyone but a signatory of
	 * the body or an administrator.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-001-a-body-lists-the-competences-it-needs
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function createCompetence(): JSONResponse {
		return $this->respond(
			key: 'competence',
			created: true,
			action: fn (): array => $this->competences->saveCompetence(data: $this->bodyParams(request: $this->request), id: null)
		);
	}//end createCompetence()

	/**
	 * Change or deactivate a body's competence. Authority is checked on the
	 * competence's own body by the service.
	 *
	 * @param string $id The competence
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-001-a-body-lists-the-competences-it-needs
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function updateCompetence(string $id): JSONResponse {
		return $this->respond(
			key: 'competence',
			created: false,
			action: fn (): array => $this->competences->saveCompetence(data: $this->bodyParams(request: $this->request), id: $id)
		);
	}//end updateCompetence()

	/**
	 * Record a member competence. Body: membership, competence, level,
	 * note. The service lets the member themselves or a signatory of the
	 * membership's body in.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function record(): JSONResponse {
		return $this->respond(
			key: 'memberCompetence',
			created: true,
			action: fn (): array => $this->competences->record(data: $this->bodyParams(request: $this->request), id: null)
		);
	}//end record()

	/**
	 * Change a member competence's level or note. Authority is checked on
	 * its membership by the service; a new level clears the confirmation.
	 *
	 * @param string $id The member competence
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		return $this->respond(
			key: 'memberCompetence',
			created: false,
			action: fn (): array => $this->competences->record(data: $this->bodyParams(request: $this->request), id: $id)
		);
	}//end update()

	/**
	 * Confirm a member competence. Only a signatory of the membership's body
	 * who is not the holder, checked by the service.
	 *
	 * @param string $id The member competence
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function confirm(string $id): JSONResponse {
		return $this->respond(key: 'memberCompetence', created: false, action: fn (): array => $this->competences->confirm(id: $id));
	}//end confirm()

	/**
	 * Run a competence write and map its outcome: 401 signed out, 403 no
	 * authority, 404 unknown, 422 refused content, 500 failure.
	 *
	 * @param string                           $key     The response key
	 * @param bool                             $created Whether success is a 201
	 * @param callable(): array<string, mixed> $action  The write
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
	 *
	 * @return JSONResponse
	 */
	private function respond(string $key, bool $created, callable $action): JSONResponse {
		$auth = $this->requireUserOr401(session: $this->userSession);
		if ($auth !== null) {
			return $auth;
		}

		try {
			$status = Http::STATUS_OK;
			if ($created === true) {
				$status = Http::STATUS_CREATED;
			}

			return new JSONResponse([$key => $action()], $status);
		} catch (AccessDeniedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (MissingObjectException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (CompetenceRefusedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error('Decidiq: competence write failed', ['exception' => $e]);
			return new JSONResponse(['message' => 'The competence could not be saved.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end respond()
}//end class
