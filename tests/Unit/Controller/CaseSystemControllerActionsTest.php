<?php

/**
 * Decidiq CaseSystemControllerActionsTest
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\CaseSystemController;
use OCA\Decidiq\Exception\CaseSystemException;
use OCA\Decidiq\Service\CaseDocumentService;
use OCA\Decidiq\Service\CaseExchangeRecords;
use OCA\Decidiq\Service\CaseSystemClient;
use OCA\Decidiq\Service\CaseSystemExchangeService;
use OCA\Decidiq\Service\TranscriptionStaffGuard;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The connected case system: send, resend, the item actions on success and
 * refusal, and the item read failures that answer before any role check.
 *
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
 *
 * @covers \OCA\Decidiq\Controller\CaseSystemController
 * @uses   \OCA\Decidiq\Exception\CaseSystemException
 */
class CaseSystemControllerActionsTest extends TestCase {

	/**
	 * @var CaseSystemClient&MockObject
	 */
	private CaseSystemClient&MockObject $client;

	/**
	 * @var CaseDocumentService&MockObject
	 */
	private CaseDocumentService&MockObject $documents;

	/**
	 * @var CaseSystemExchangeService&MockObject
	 */
	private CaseSystemExchangeService&MockObject $exchange;

	/**
	 * @var CaseExchangeRecords&MockObject
	 */
	private CaseExchangeRecords&MockObject $records;

	/**
	 * @var TranscriptionStaffGuard&MockObject
	 */
	private TranscriptionStaffGuard&MockObject $guard;

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->client = $this->createMock(CaseSystemClient::class);
		$this->documents = $this->createMock(CaseDocumentService::class);
		$this->exchange = $this->createMock(CaseSystemExchangeService::class);
		$this->records = $this->createMock(CaseExchangeRecords::class);
		$this->guard = $this->createMock(TranscriptionStaffGuard::class);
		$this->guard->method('currentUserId')->willReturn('griffier');
		$this->objects = $this->createMock(ObjectServiceInterface::class);
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return CaseSystemController
	 */
	private function controller(): CaseSystemController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default=null): mixed => ['reference' => 'Z-2026-00412', 'urls' => ['https://documenten.example.org/1', 2]][$key] ?? $default);

		return new CaseSystemController(
			request: $request,
			client: $this->client,
			documents: $this->documents,
			exchange: $this->exchange,
			records: $this->records,
			guard: $this->guard,
			objectService: $this->objects
		);
	}//end controller()

	/**
	 * Make the agenda item readable, in the given meeting.
	 *
	 * @return void
	 */
	private function itemInMeeting(): void {
		$item = $this->createMock(ObjectEntity::class);
		$item->method('getObject')->willReturn(['meeting' => 'meeting-12-maart']);
		$this->objects->expects($this->once())->method('find')
			->with('item-1', $this->anything(), $this->anything(), 'decidiq', 'agenda-item')
			->willReturn($item);
	}//end itemInMeeting()

	/**
	 * The chair sends the meeting file: 202 with the exchange's answer.
	 *
	 * @return void
	 */
	public function testSendIsAcceptedForTheChair(): void {
		$this->guard->expects($this->once())->method('forMeeting')->with('meeting-12-maart')->willReturn(null);
		$this->exchange->expects($this->once())->method('requestSend')
			->with('meeting-12-maart', 'griffier')
			->willReturn(['record' => 'rec-1', 'status' => 'queued']);

		$response = $this->controller()->send(id: 'meeting-12-maart');

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame(['record' => 'rec-1', 'status' => 'queued'], $response->getData());
	}//end testSendIsAcceptedForTheChair()

	/**
	 * A refused send answers with the refusal's own status and message.
	 *
	 * @return void
	 */
	public function testSendRefusalAnswersItsOwnStatus(): void {
		$this->guard->method('forMeeting')->willReturn(null);
		$this->exchange->method('requestSend')->willThrowException(new CaseSystemException(message: 'The minutes are not approved yet', status: 409));

		$response = $this->controller()->send(id: 'meeting-12-maart');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['message' => 'The minutes are not approved yet'], $response->getData());
	}//end testSendRefusalAnswersItsOwnStatus()

	/**
	 * A record that cannot be found answers with the refusal before any guard.
	 *
	 * @return void
	 */
	public function testResendUnknownRecordAnswersTheRefusal(): void {
		$this->records->expects($this->once())->method('find')->with('rec-x')
			->willThrowException(new CaseSystemException(message: 'Exchange record not found', status: 404));
		$this->guard->expects($this->never())->method('forMeeting');
		$this->exchange->expects($this->never())->method('requestResend');

		$response = $this->controller()->resend(id: 'rec-x');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Exchange record not found'], $response->getData());
	}//end testResendUnknownRecordAnswersTheRefusal()

	/**
	 * Someone not chair or secretary of the record's meeting is refused.
	 *
	 * @return void
	 */
	public function testResendRefusedForSomeoneElse(): void {
		$this->records->method('find')->willReturn(['meeting' => 'meeting-12-maart']);
		$denied = new JSONResponse(['message' => 'Forbidden: chair or secretary role required.'], Http::STATUS_FORBIDDEN);
		$this->guard->expects($this->once())->method('forMeeting')->with('meeting-12-maart')->willReturn($denied);
		$this->exchange->expects($this->never())->method('requestResend');

		$this->assertSame($denied, $this->controller()->resend(id: 'rec-1'));
	}//end testResendRefusedForSomeoneElse()

	/**
	 * A record without a meeting is guarded against the empty meeting id.
	 *
	 * @return void
	 */
	public function testResendOfRecordWithoutMeetingGuardsTheEmptyId(): void {
		$this->records->method('find')->willReturn(['status' => 'failed']);
		$denied = new JSONResponse(['message' => 'Meeting not found'], Http::STATUS_NOT_FOUND);
		$this->guard->expects($this->once())->method('forMeeting')->with('')->willReturn($denied);

		$this->assertSame($denied, $this->controller()->resend(id: 'rec-2'));
	}//end testResendOfRecordWithoutMeetingGuardsTheEmptyId()

	/**
	 * The secretary resends a record's failed lines: 202.
	 *
	 * @return void
	 */
	public function testResendIsAcceptedForTheSecretary(): void {
		$this->records->method('find')->with('rec-1')->willReturn(['meeting' => 'meeting-12-maart']);
		$this->guard->method('forMeeting')->willReturn(null);
		$this->exchange->expects($this->once())->method('requestResend')
			->with('rec-1', 'griffier')
			->willReturn(['record' => 'rec-1', 'resent' => 2]);

		$response = $this->controller()->resend(id: 'rec-1');

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame(['record' => 'rec-1', 'resent' => 2], $response->getData());
	}//end testResendIsAcceptedForTheSecretary()

	/**
	 * An item that cannot be read answers 503 before any role check.
	 *
	 * @return void
	 */
	public function testUnreadableItemAnswers503(): void {
		$this->objects->method('find')->willThrowException(new \Exception('db'));
		$this->guard->expects($this->never())->method('forMeeting');
		$this->documents->expects($this->never())->method('listDocuments');

		$response = $this->controller()->listDocuments(id: 'item-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['message' => 'The agenda item could not be read.'], $response->getData());
	}//end testUnreadableItemAnswers503()

	/**
	 * An item the caller cannot see answers 404 before any role check.
	 *
	 * @return void
	 */
	public function testInvisibleItemAnswers404(): void {
		$this->objects->method('find')->willReturn(null);
		$this->guard->expects($this->never())->method('forMeeting');

		$response = $this->controller()->linkCase(id: 'item-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Agenda item not found'], $response->getData());
	}//end testInvisibleItemAnswers404()

	/**
	 * With a case system connected the chair links the item to a case: 200.
	 *
	 * @return void
	 */
	public function testLinkCaseWhenConnected(): void {
		$this->itemInMeeting();
		$this->guard->expects($this->once())->method('forMeeting')->with('meeting-12-maart')->willReturn(null);
		$this->client->method('isConnected')->willReturn(true);
		$this->documents->expects($this->once())->method('link')
			->with('item-1', 'Z-2026-00412')
			->willReturn(['reference' => 'Z-2026-00412', 'title' => 'Omgevingsvisie']);

		$response = $this->controller()->linkCase(id: 'item-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['caseReference' => ['reference' => 'Z-2026-00412', 'title' => 'Omgevingsvisie']], $response->getData());
	}//end testLinkCaseWhenConnected()

	/**
	 * With a case system connected the item's case documents are listed: 200.
	 *
	 * @return void
	 */
	public function testListDocumentsWhenConnected(): void {
		$this->itemInMeeting();
		$this->guard->method('forMeeting')->willReturn(null);
		$this->client->method('isConnected')->willReturn(true);
		$this->documents->expects($this->once())->method('listDocuments')->with('item-1')
			->willReturn([['url' => 'https://documenten.example.org/1', 'title' => 'Raadsvoorstel']]);

		$response = $this->controller()->listDocuments(id: 'item-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['documents' => [['url' => 'https://documenten.example.org/1', 'title' => 'Raadsvoorstel']]], $response->getData());
	}//end testListDocumentsWhenConnected()

	/**
	 * Fetching passes the urls as strings and the acting user.
	 *
	 * @return void
	 */
	public function testFetchDocumentsPassesStringUrlsAndUser(): void {
		$this->itemInMeeting();
		$this->guard->method('forMeeting')->willReturn(null);
		$this->client->method('isConnected')->willReturn(true);
		$this->documents->expects($this->once())->method('fetch')
			->with('item-1', ['https://documenten.example.org/1', '2'], 'griffier')
			->willReturn(['id' => 'rec-9', 'fetched' => 2]);

		$response = $this->controller()->fetchDocuments(id: 'item-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['record' => ['id' => 'rec-9', 'fetched' => 2]], $response->getData());
	}//end testFetchDocumentsPassesStringUrlsAndUser()

	/**
	 * A case system refusal during an item action answers its own status.
	 *
	 * @return void
	 */
	public function testItemActionRefusalAnswersItsOwnStatus(): void {
		$this->itemInMeeting();
		$this->guard->method('forMeeting')->willReturn(null);
		$this->client->method('isConnected')->willReturn(true);
		$this->documents->method('link')->willThrowException(new CaseSystemException(message: 'The case system could not be reached: timeout', status: 502));

		$response = $this->controller()->linkCase(id: 'item-1');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame(['message' => 'The case system could not be reached: timeout'], $response->getData());
	}//end testItemActionRefusalAnswersItsOwnStatus()
}//end class
