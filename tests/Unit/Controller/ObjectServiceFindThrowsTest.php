<?php

/**
 * Unknown-id handling across controllers that call ObjectService::find().
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
 * @spec openspec/specs/resolution-minutes/spec.md
 * @spec openspec/specs/meeting-transcription/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\MinutesCorrectionController;
use OCA\Decidiq\Controller\TranscriptionController;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\MinutesAccessGuard;
use OCA\Decidiq\Service\MinutesDraftService;
use OCA\Decidiq\Service\TranscriptionQueue;
use OCA\Decidiq\Service\TranscriptionService;
use OCA\Decidiq\Service\TranscriptionStaffGuard;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * An unknown object id is 404, not 500.
 *
 * WHAT THIS GUARDS. OpenRegister's `ObjectService::find()` THROWS
 * `DoesNotExistException` for an id that is not there — it does not return
 * null. Three controllers were written against the opposite contract:
 *
 *   MinutesCorrectionController::addCorrection      500 (swallowed by
 *   MinutesCorrectionController::resolveCorrection  `catch (Exception)`)
 *   TranscriptionController::retentionConfig        500 (uncaught entirely)
 *
 * Each had an `if ($entity === null) return 404;` branch that could never run
 * for the case it was written for, so an ordinary "no such object" request
 * produced a server error — and in the Transcription case, a stack trace.
 *
 * These assertions are deliberately at ITEM level: they name the HTTP STATUS
 * the caller is owed, not merely "a JSONResponse came back". A container-level
 * assertion ("returns a response") passes just as happily on the 500, which is
 * exactly how this survived.
 *
 * `DoesNotExistException` extends `\Exception`, which is why ORDER matters in
 * the two Minutes methods: the narrow arm must precede the broad one. The
 * third test below pins that a non-DoesNotExist failure is still reported as a
 * server error, so the narrow arm cannot quietly widen into a catch-all that
 * hides a real outage behind a tidy 404.
 */
final class ObjectServiceFindThrowsTest extends TestCase {

	/**
	 * Build a MinutesCorrectionController whose find() throws.
	 *
	 * @return MinutesCorrectionController
	 */
	private function minutesControllerWithThrowingFind(\Throwable $toThrow): MinutesCorrectionController {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willThrowException($toThrow);

		// The access guard answers "allowed" so the test reaches the lookup
		// rather than stopping at authorisation — otherwise a 403 would mask
		// whatever the lookup does and the test would prove nothing.
		$guard = $this->createMock(MinutesAccessGuard::class);
		$guard->method('requireParticipant')->willReturn(null);
		$guard->method('requireChairOrAdmin')->willReturn(null);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				return match ($key) {
					'text' => 'A suggested correction.',
					'status' => 'accepted',
					default => $default,
				};
			}
		);

		return new MinutesCorrectionController(
			request: $request,
			accessGuard: $guard,
			objectService: $objectService,
			userSession: $this->createMock(IUserSession::class),
		);

	}//end minutesControllerWithThrowingFind()

	/**
	 * Suggesting a correction on unknown minutes is 404, not 500.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return void
	 */
	public function testAddCorrectionAnswersNotFoundForAnUnknownId(): void {
		$controller = $this->minutesControllerWithThrowingFind(
			new DoesNotExistException('no such minutes')
		);

		$response = $controller->addCorrection(minutesId: 'missing-uuid');

		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$response->getStatus(),
			'an unknown minutes id owes 404; 500 means DoesNotExistException fell through to the broad Exception arm'
		);

	}//end testAddCorrectionAnswersNotFoundForAnUnknownId()

	/**
	 * Resolving a correction on unknown minutes is 404, not 500.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return void
	 */
	public function testResolveCorrectionAnswersNotFoundForAnUnknownId(): void {
		$controller = $this->minutesControllerWithThrowingFind(
			new DoesNotExistException('no such minutes')
		);

		$response = $controller->resolveCorrection(
			minutesId: 'missing-uuid',
			correctionId: 'correction-1'
		);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testResolveCorrectionAnswersNotFoundForAnUnknownId()

	/**
	 * A failure that is NOT an unknown id is still a server error.
	 *
	 * Keeps the narrow catch honest. If it widened to `\Throwable`, an
	 * OpenRegister outage would be reported to the caller — and to
	 * monitoring — as a tidy 404, which is worse than the 500 it replaced.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return void
	 */
	public function testAnUnrelatedFailureIsStillAServerError(): void {
		$controller = $this->minutesControllerWithThrowingFind(
			new \RuntimeException('OpenRegister is unreachable')
		);

		$response = $controller->addCorrection(minutesId: 'any-uuid');

		self::assertSame(
			Http::STATUS_INTERNAL_SERVER_ERROR,
			$response->getStatus(),
			'a broken data layer must not be disguised as a 404'
		);

	}//end testAnUnrelatedFailureIsStillAServerError()

	/**
	 * Setting retention on an unknown governance body is 404, not an
	 * uncaught 500.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 *
	 * @return void
	 */
	public function testRetentionConfigAnswersNotFoundForAnUnknownBody(): void {
		// The lookup now lives behind TranscriptionService/TranscriptRepository,
		// which converts an absent object into the app's own
		// MissingObjectException. Asserting on the CONTROLLER's status is still
		// the point: it is the thing the caller receives.
		$transcriptionService = $this->createMock(TranscriptionService::class);
		$transcriptionService->method('setRetentionPolicy')
			->willThrowException(new MissingObjectException('Governance body "missing-uuid" not found.'));

		$staffGuard = $this->createMock(TranscriptionStaffGuard::class);
		$staffGuard->method('forBody')->willReturn(null);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				return match ($key) {
					'policy' => 'delete-both',
					'days' => 30,
					default => $default,
				};
			}
		);

		$controller = new TranscriptionController(
			request: $request,
			transcriptionService: $transcriptionService,
			minutesDraftService: $this->createMock(MinutesDraftService::class),
			staffGuard: $staffGuard,
			queue: $this->createMock(TranscriptionQueue::class),
		);

		$response = $controller->retentionConfig(bodyId: 'missing-uuid');

		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$response->getStatus(),
			'this method has no try/catch of its own, so before the fix the exception escaped the controller entirely'
		);

	}//end testRetentionConfigAnswersNotFoundForAnUnknownBody()
}//end class
