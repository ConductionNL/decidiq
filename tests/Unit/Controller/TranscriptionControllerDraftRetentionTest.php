<?php

/**
 * Unit tests for TranscriptionController: draft generation, retention and the attach/transcribe failures.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use DomainException;
use InvalidArgumentException;
use OCA\Decidiq\Controller\TranscriptionController;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Kernel\Contracts\MinutesDraftServiceInterface;
use OCA\Decidiq\Kernel\Contracts\TranscriptionQueueInterface;
use OCA\Decidiq\Kernel\Contracts\TranscriptionServiceInterface;
use OCA\Decidiq\Kernel\Contracts\TranscriptionStaffGuardInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TranscriptionController actions the contract test leaves open.
 *
 * @covers \OCA\Decidiq\Controller\TranscriptionController
 * @uses \OCA\Decidiq\Exception\MissingObjectException
 */
class TranscriptionControllerDraftRetentionTest extends TestCase {

	/**
	 * The transcription service double.
	 *
	 * @var TranscriptionServiceInterface&MockObject
	 */
	private TranscriptionServiceInterface&MockObject $transcripts;

	/**
	 * The draft service double.
	 *
	 * @var MinutesDraftServiceInterface&MockObject
	 */
	private MinutesDraftServiceInterface&MockObject $drafts;

	/**
	 * The queue double.
	 *
	 * @var TranscriptionQueueInterface&MockObject
	 */
	private TranscriptionQueueInterface&MockObject $queue;

	/**
	 * A controller over these request params, with a guard that allows or denies.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param JSONResponse|null    $denial The guard's denial, or null to allow.
	 *
	 * @return TranscriptionController The controller under test.
	 */
	private function controller(array $params = [], ?JSONResponse $denial = null): TranscriptionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$guard = $this->createMock(TranscriptionStaffGuardInterface::class);
		$guard->method('forMeeting')->willReturn($denial);
		$guard->method('forTranscript')->willReturn($denial);
		$guard->method('forBody')->willReturn($denial);
		$guard->method('currentUserId')->willReturn('griffier');
		$this->transcripts = $this->createMock(TranscriptionServiceInterface::class);
		$this->drafts = $this->createMock(MinutesDraftServiceInterface::class);
		$this->queue = $this->createMock(TranscriptionQueueInterface::class);

		return new TranscriptionController($request, $this->transcripts, $this->drafts, $guard, $this->queue);
	}//end controller()

	/**
	 * The guard's denial for one action.
	 *
	 * @return JSONResponse The denial.
	 */
	private function denial(): JSONResponse {
		return new JSONResponse(['message' => 'Chair or secretary role required.'], Http::STATUS_FORBIDDEN);
	}//end denial()

	/**
	 * A staff member gets the generated draft.
	 *
	 * @return void
	 */
	public function testGenerateDraftReturnsTheDraft(): void {
		$controller = $this->controller();
		$this->drafts->expects(self::once())->method('generate')->with('t-1')->willReturn(['minutesId' => 'm-1', 'items' => 3]);

		$response = $controller->generateDraft(transcriptId: 't-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['minutesId' => 'm-1', 'items' => 3], $response->getData());
	}//end testGenerateDraftReturnsTheDraft()

	/**
	 * An unknown transcript is 404 and no AI provider is 503, both with the service's message.
	 *
	 * @return void
	 */
	public function testGenerateDraftFailures(): void {
		$controller = $this->controller();
		$this->drafts->method('generate')->willReturnOnConsecutiveCalls(
			self::throwException(new MissingObjectException(message: 'Transcript not found.')),
			self::throwException(new DomainException('No AI provider is configured.')),
		);

		$missing = $controller->generateDraft(transcriptId: 't-404');
		self::assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		self::assertSame(['message' => 'Transcript not found.'], $missing->getData());

		$unavailable = $controller->generateDraft(transcriptId: 't-1');
		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $unavailable->getStatus());
		self::assertSame(['message' => 'No AI provider is configured.'], $unavailable->getData());
	}//end testGenerateDraftFailures()

	/**
	 * A non-staff caller gets the guard's denial and no draft is generated.
	 *
	 * @return void
	 */
	public function testGenerateDraftDeniedForNonStaff(): void {
		$denial = $this->denial();
		$controller = $this->controller(denial: $denial);
		$this->drafts->expects(self::never())->method('generate');

		self::assertSame($denial, $controller->generateDraft(transcriptId: 't-1'));
	}//end testGenerateDraftDeniedForNonStaff()

	/**
	 * Attach is refused for non-staff before consent is even read.
	 *
	 * @return void
	 */
	public function testAttachDeniedForNonStaff(): void {
		$denial = $this->denial();
		$controller = $this->controller(params: ['consent' => true], denial: $denial);
		$this->transcripts->expects(self::never())->method('attach');

		self::assertSame($denial, $controller->attach(meetingId: 'meeting-1'));
	}//end testAttachDeniedForNonStaff()

	/**
	 * An attach to an unknown meeting is 404; an invalid source is 422.
	 *
	 * @return void
	 */
	public function testAttachFailures(): void {
		$controller = $this->controller(params: ['consent' => '1', 'sourceType' => 'file', 'sourcePath' => '/x.mp3']);
		$this->transcripts->method('attach')->willReturnOnConsecutiveCalls(
			self::throwException(new MissingObjectException(message: 'Meeting not found.')),
			self::throwException(new InvalidArgumentException('Unsupported source type.')),
		);

		$missing = $controller->attach(meetingId: 'meeting-404');
		self::assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		self::assertSame(['message' => 'Meeting not found.'], $missing->getData());

		$invalid = $controller->attach(meetingId: 'meeting-1');
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $invalid->getStatus());
		self::assertSame(['message' => 'Unsupported source type.'], $invalid->getData());
	}//end testAttachFailures()

	/**
	 * Transcribing an unknown transcript is 404 and nothing is queued.
	 *
	 * @return void
	 */
	public function testTranscribeUnknownTranscriptIsNotFound(): void {
		$controller = $this->controller();
		$this->transcripts->method('submit')->willThrowException(new MissingObjectException(message: 'Transcript not found.'));
		$this->queue->expects(self::never())->method('enqueue');

		$response = $controller->transcribe(transcriptId: 't-404');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['message' => 'Transcript not found.'], $response->getData());
	}//end testTranscribeUnknownTranscriptIsNotFound()

	/**
	 * A valid retention policy is stored and echoed; the defaults are delete-both after 30 days.
	 *
	 * @return void
	 */
	public function testRetentionConfigStoresThePolicy(): void {
		$controller = $this->controller(params: ['policy' => 'keep', 'days' => '0']);
		$this->transcripts->expects(self::once())->method('setRetentionPolicy')->with('body-1', 'keep', 0);

		self::assertSame(['policy' => 'keep', 'days' => 0], $controller->retentionConfig(bodyId: 'body-1')->getData());

		$defaults = $this->controller();
		$this->transcripts->expects(self::once())->method('setRetentionPolicy')->with('body-1', 'delete-both', 30);
		self::assertSame(['policy' => 'delete-both', 'days' => 30], $defaults->retentionConfig(bodyId: 'body-1')->getData());
	}//end testRetentionConfigStoresThePolicy()

	/**
	 * An unknown policy or negative days is refused and nothing is stored.
	 *
	 * @return void
	 */
	public function testRetentionConfigRefusesInvalidInput(): void {
		foreach ([
			[['policy' => 'forever'], 'Invalid retention policy.'],
			[['policy' => 'delete-recording', 'days' => -1], 'Retention days must be zero or positive.'],
		] as [$params, $message]) {
			$controller = $this->controller(params: $params);
			$this->transcripts->expects(self::never())->method('setRetentionPolicy');

			$response = $controller->retentionConfig(bodyId: 'body-1');
			self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
			self::assertSame(['message' => $message], $response->getData());
		}
	}//end testRetentionConfigRefusesInvalidInput()

	/**
	 * An unknown body is 404; a non-staff caller gets the guard's denial and nothing is stored.
	 *
	 * @return void
	 */
	public function testRetentionConfigUnknownBodyOrNonStaff(): void {
		$controller = $this->controller();
		$this->transcripts->method('setRetentionPolicy')->willThrowException(new MissingObjectException(message: 'Body not found.'));
		$response = $controller->retentionConfig(bodyId: 'body-404');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['message' => 'Body not found.'], $response->getData());

		$denial = $this->denial();
		$denied = $this->controller(params: ['policy' => 'keep'], denial: $denial);
		$this->transcripts->expects(self::never())->method('setRetentionPolicy');
		self::assertSame($denial, $denied->retentionConfig(bodyId: 'body-1'));
	}//end testRetentionConfigUnknownBodyOrNonStaff()
}//end class
