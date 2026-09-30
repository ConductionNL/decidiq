<?php

/**
 * Decidiq CaseSystemController
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
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Exception\CaseSystemException;
use OCA\Decidiq\Service\CaseDocumentService;
use OCA\Decidiq\Service\CaseExchangeRecords;
use OCA\Decidiq\Service\CaseSystemClient;
use OCA\Decidiq\Service\CaseSystemExchangeService;
use OCA\Decidiq\Service\TranscriptionStaffGuard;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The case system on the agenda item, the minutes page and the meeting page.
 * Every action but the status belongs to the meeting's chair or secretary.
 *
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
 */
class CaseSystemController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request       The request.
	 * @param CaseSystemClient          $client        The case system.
	 * @param CaseDocumentService       $documents     Case link and fetch.
	 * @param CaseSystemExchangeService $exchange      The meeting file send.
	 * @param CaseExchangeRecords       $records       The exchange records.
	 * @param TranscriptionStaffGuard   $guard         Chair or secretary of the meeting.
	 * @param ObjectServiceInterface    $objectService OpenRegister object service (the caller's own read).
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
	 */
	public function __construct(
		IRequest $request,
		private readonly CaseSystemClient $client,
		private readonly CaseDocumentService $documents,
		private readonly CaseSystemExchangeService $exchange,
		private readonly CaseExchangeRecords $records,
		private readonly TranscriptionStaffGuard $guard,
		private readonly ObjectServiceInterface $objectService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Whether a case system is connected, so the screens know whether to offer case actions.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function status(): JSONResponse {
		return new JSONResponse(['connected' => $this->client->isConnected()]);
	}//end status()

	/**
	 * Link an agenda item to a case.
	 *
	 * @param string $id The agenda item.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-002-an-agenda-item-links-to-its-case
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function linkCase(string $id): JSONResponse {
		return $this->forItem(
			itemId: $id,
			action: fn (): array => ['caseReference' => $this->documents->link(itemId: $id, reference: (string)$this->request->getParam('reference', ''))]
		);
	}//end linkCase()

	/**
	 * The documents of the item's case.
	 *
	 * @param string $id The agenda item.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-003-the-griffier-fetches-case-documents-onto-the-item-once-each
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function listDocuments(string $id): JSONResponse {
		return $this->forItem(itemId: $id, action: fn (): array => ['documents' => $this->documents->listDocuments(itemId: $id)]);
	}//end listDocuments()

	/**
	 * Fetch the chosen documents onto the item.
	 *
	 * @param string $id The agenda item.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-003-the-griffier-fetches-case-documents-onto-the-item-once-each
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function fetchDocuments(string $id): JSONResponse {
		$urls = array_values(array_map('strval', (array)$this->request->getParam('urls', [])));
		return $this->forItem(
			itemId: $id,
			action: fn (): array => ['record' => $this->documents->fetch(itemId: $id, urls: $urls, userId: $this->guard->currentUserId())]
		);
	}//end fetchDocuments()

	/**
	 * Send the meeting file to the case system.
	 *
	 * @param string $id The meeting.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function send(string $id): JSONResponse {
		$denied = $this->guard->forMeeting(meetingId: $id);
		if ($denied !== null) {
			return $denied;
		}

		$userId = $this->guard->currentUserId();
		return $this->run(action: fn (): array => $this->exchange->requestSend(meetingId: $id, userId: $userId), status: Http::STATUS_ACCEPTED);
	}//end send()

	/**
	 * Send one record's failed lines again.
	 *
	 * @param string $id The record.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function resend(string $id): JSONResponse {
		try {
			$meetingId = (string)($this->records->find(recordId: $id)['meeting'] ?? '');
		} catch (CaseSystemException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		}

		$denied = $this->guard->forMeeting(meetingId: $meetingId);
		if ($denied !== null) {
			return $denied;
		}

		$userId = $this->guard->currentUserId();
		return $this->run(action: fn (): array => $this->exchange->requestResend(recordId: $id, userId: $userId), status: Http::STATUS_ACCEPTED);
	}//end resend()

	/**
	 * Run an item action for the chair or secretary of the item's meeting.
	 *
	 * The item is read with the caller's own rights, so an item the caller
	 * cannot see answers 404 before any role is checked.
	 *
	 * @param string   $itemId The agenda item.
	 * @param callable $action The action.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-002-an-agenda-item-links-to-its-case
	 *
	 * @return JSONResponse
	 */
	private function forItem(string $itemId, callable $action): JSONResponse {
		$item = $this->objectService->find(id: $itemId, register: 'decidiq', schema: 'agenda-item');
		if ($item === null) {
			return new JSONResponse(['message' => 'Agenda item not found'], Http::STATUS_NOT_FOUND);
		}

		$denied = $this->guard->forMeeting(meetingId: (string)(((array)$item->getObject())['meeting'] ?? ''));
		if ($denied !== null) {
			return $denied;
		}

		if ($this->client->isConnected() === false) {
			return new JSONResponse(['message' => CaseSystemClient::NOT_CONNECTED], Http::STATUS_CONFLICT);
		}

		return $this->run(action: $action, status: Http::STATUS_OK);
	}//end forItem()

	/**
	 * Run an action, answering a refusal with its own status.
	 *
	 * @param callable $action The action.
	 * @param int      $status The status of success.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
	 *
	 * @return JSONResponse
	 */
	private function run(callable $action, int $status): JSONResponse {
		try {
			return new JSONResponse($action(), $status);
		} catch (CaseSystemException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		}
	}//end run()
}//end class
