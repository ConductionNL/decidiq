<?php

/**
 * Decidiq Closed Agenda Item Controller
 *
 * Where a meeting's closed agenda items sit, for readers who may not read them.
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
 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-005-a-non-public-item-stays-out-of-the-pack-and-the-public-site
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Service\ClosedAgendaItemNumbers;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists the order numbers of the closed items the caller cannot read.
 *
 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-005-a-non-public-item-stays-out-of-the-pack-and-the-public-site
 */
class ClosedAgendaItemController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                $request     HTTP request
	 * @param ClosedAgendaItemNumbers $closedItems The hidden closed items
	 * @param IUserSession            $userSession User session
	 * @param LoggerInterface         $logger      Logger
	 *
	 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-005-a-non-public-item-stays-out-of-the-pack-and-the-public-site
	 */
	public function __construct(
		IRequest $request,
		private readonly ClosedAgendaItemNumbers $closedItems,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The closed items of a meeting the caller cannot read, number only.
	 *
	 * @param string $meetingId The meeting.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-005-a-non-public-item-stays-out-of-the-pack-and-the-public-site
	 *
	 * @return JSONResponse 200 with `closedItems`, 401, 404 or 500.
	 */
	#[NoAdminRequired]
	public function index(string $meetingId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(['closedItems' => $this->closedItems->forMeeting(meetingId: $meetingId)]);
		} catch (MissingObjectException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error('Decidiq: closed agenda items could not be read', ['exception' => $e]);
			return new JSONResponse(['message' => 'The closed agenda items could not be read.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end index()
}//end class
