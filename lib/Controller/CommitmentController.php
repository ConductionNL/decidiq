<?php

/**
 * Decidiq Commitment Controller
 *
 * The clerk adds a dated progress entry to a commitment from its page.
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
 * @spec openspec/specs/ori-api/spec.md#requirement-req-fpp-002-the-clerk-adds-a-progress-entry
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use InvalidArgumentException;
use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\CommitmentProgressService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Progress entries on a commitment.
 *
 * @spec openspec/specs/ori-api/spec.md#requirement-req-fpp-002-the-clerk-adds-a-progress-entry
 */
class CommitmentController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request     The request
	 * @param CommitmentProgressService $progress    Progress entries and who may add them
	 * @param IUserSession              $userSession The session
	 * @param LoggerInterface           $logger      The logger
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly CommitmentProgressService $progress,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * POST /api/commitments/{id}/progress: add a progress entry dated today.
	 * Body: { "note": "Draft report sent to the committee" }.
	 *
	 * @param string $id The commitment UUID
	 *
	 * @return JSONResponse The progress entries after the addition
	 *
	 * @spec openspec/specs/ori-api/spec.md#requirement-req-fpp-002-the-clerk-adds-a-progress-entry
	 */
	#[NoAdminRequired]
	public function addProgress(string $id): JSONResponse {
		$uid = ($this->userSession->getUser()?->getUID() ?? '');

		try {
			if ($this->progress->canAddProgress(commitmentId: $id, uid: $uid) === false) {
				return new JSONResponse(
					['message' => 'Only the chair or secretary of the meeting can add progress.'],
					Http::STATUS_FORBIDDEN
				);
			}

			$entries = $this->progress->addEntry(
				commitmentId: $id,
				note: (string)($this->request->getParam('note') ?? ''),
			);
			return new JSONResponse(['progress' => $entries]);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new JSONResponse(['message' => 'Commitment not found.'], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error(
				'Adding progress to commitment {id} failed: {error}',
				['id' => $id, 'error' => $e->getMessage(), 'exception' => $e]
			);
			return new JSONResponse(['message' => 'An internal error occurred.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end addProgress()
}//end class
