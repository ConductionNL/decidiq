<?php

/**
 * Unit tests for CommitmentController: every answer addProgress gives.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Decidiq\Controller\CommitmentController;
use OCA\Decidiq\Kernel\Contracts\CommitmentProgressServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for CommitmentController.
 *
 * @covers \OCA\Decidiq\Controller\CommitmentController
 */
class CommitmentControllerTest extends TestCase {

	/**
	 * The progress service double.
	 *
	 * @var CommitmentProgressServiceInterface&MockObject
	 */
	private CommitmentProgressServiceInterface&MockObject $progress;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * A controller for this caller and request note.
	 *
	 * @param string|null $uid  The caller, or null when nobody is signed in.
	 * @param mixed       $note The `note` request parameter.
	 *
	 * @return CommitmentController The controller under test.
	 */
	private function controller(?string $uid = 'chair', mixed $note = 'Half done'): CommitmentController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([['note', null, $note]]);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->progress = $this->createMock(CommitmentProgressServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		return new CommitmentController(request: $request, progress: $this->progress, userSession: $session, logger: $this->logger);
	}//end controller()

	/**
	 * An allowed caller's note is added and the entries come back.
	 *
	 * @return void
	 */
	public function testAnAllowedCallerAddsTheNote(): void {
		$controller = $this->controller();
		$this->progress->expects(self::once())->method('canAddProgress')->with('c-1', 'chair')->willReturn(true);
		$this->progress->expects(self::once())->method('addEntry')->with('c-1', 'Half done')
			->willReturn([['date' => '2026-11-01', 'note' => 'Half done']]);

		$response = $controller->addProgress(id: 'c-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['progress' => [['date' => '2026-11-01', 'note' => 'Half done']]], $response->getData());
	}//end testAnAllowedCallerAddsTheNote()

	/**
	 * A missing note is passed on as an empty string, and an anonymous caller is checked as ''.
	 *
	 * @return void
	 */
	public function testAMissingNoteIsEmptyAndAnAnonymousCallerIsBlank(): void {
		$controller = $this->controller(uid: null, note: null);
		$this->progress->expects(self::once())->method('canAddProgress')->with('c-1', '')->willReturn(true);
		$this->progress->expects(self::once())->method('addEntry')->with('c-1', '')->willReturn([]);

		self::assertSame(['progress' => []], $controller->addProgress(id: 'c-1')->getData());
	}//end testAMissingNoteIsEmptyAndAnAnonymousCallerIsBlank()

	/**
	 * A caller who is neither chair nor secretary is forbidden and nothing is added.
	 *
	 * @return void
	 */
	public function testAnyoneElseIsForbidden(): void {
		$controller = $this->controller(uid: 'member');
		$this->progress->method('canAddProgress')->willReturn(false);
		$this->progress->expects(self::never())->method('addEntry');

		$response = $controller->addProgress(id: 'c-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['message' => 'Only the chair or secretary of the meeting can add progress.'], $response->getData());
	}//end testAnyoneElseIsForbidden()

	/**
	 * An invalid note is a bad request carrying the service's message.
	 *
	 * @return void
	 */
	public function testAnInvalidNoteIsABadRequest(): void {
		$controller = $this->controller();
		$this->progress->method('canAddProgress')->willReturn(true);
		$this->progress->method('addEntry')->willThrowException(new InvalidArgumentException('A note is required.'));

		$response = $controller->addProgress(id: 'c-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'A note is required.'], $response->getData());
	}//end testAnInvalidNoteIsABadRequest()

	/**
	 * An unknown commitment is not found.
	 *
	 * @return void
	 */
	public function testAnUnknownCommitmentIsNotFound(): void {
		$controller = $this->controller();
		$this->progress->method('canAddProgress')->willThrowException(new DoesNotExistException('gone'));
		$this->logger->expects(self::never())->method('error');

		$response = $controller->addProgress(id: 'c-404');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['message' => 'Commitment not found.'], $response->getData());
	}//end testAnUnknownCommitmentIsNotFound()

	/**
	 * Any other failure is logged with the id and answered without its detail.
	 *
	 * @return void
	 */
	public function testAnyOtherFailureIsLoggedAndHidden(): void {
		$controller = $this->controller();
		$this->progress->method('canAddProgress')->willReturn(true);
		$failure = new RuntimeException('database down');
		$this->progress->method('addEntry')->willThrowException($failure);
		$this->logger->expects(self::once())->method('error')->with(
			'Adding progress to commitment {id} failed: {error}',
			['id' => 'c-1', 'error' => 'database down', 'exception' => $failure]
		);

		$response = $controller->addProgress(id: 'c-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['message' => 'An internal error occurred.'], $response->getData());
	}//end testAnyOtherFailureIsLoggedAndHidden()
}//end class
