<?php

/**
 * Decidiq Voting Record Controller
 *
 * GET /api/people/{personId}/voting-record: a person's votes in closed rounds
 * that were not secret, for any signed-in user who can read the person. A
 * named vote in a round that was not secret is known to everyone who was in
 * the room; secret rounds and anonymised votes are never part of it.
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
 * @spec openspec/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\VotingRecordService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * A person's voting record.
 *
 * @spec openspec/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
 */
class VotingRecordController extends Controller {
	use GovernanceControllerTrait;

	/**
	 * Constructor.
	 *
	 * @param IRequest            $request     HTTP request
	 * @param VotingRecordService $records     Reads the record
	 * @param IUserSession        $userSession User session
	 */
	public function __construct(
		IRequest $request,
		private readonly VotingRecordService $records,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The record of one person. Whether the person can be read is
	 * OpenRegister's call: the person is looked up with the caller's rights,
	 * and a person they cannot see answers 404 before any vote is read.
	 *
	 * @param string $personId The person id
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function forPerson(string $personId): JSONResponse {
		$auth = $this->requireUserOr401(session: $this->userSession);
		if ($auth !== null) {
			return $auth;
		}

		try {
			$this->records->requireReadablePerson(personId: $personId);
		} catch (DoesNotExistException) {
			return new JSONResponse(['message' => 'Person not found.'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['personId' => $personId, 'votes' => $this->records->forPerson(personId: $personId)]);
	}//end forPerson()
}//end class
