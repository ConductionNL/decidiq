<?php

/**
 * Unit tests for CitizenAdviceController's session check and outcome mapping.
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
 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\CitizenAdviceController;
use OCA\Decidiq\Exception\NotFoundException;
use OCA\Decidiq\Kernel\Contracts\CitizenAdviceServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MotionServiceInterface;
use OCA\Decidiq\Kernel\Contracts\ParticipantResolverInterface;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * open()/close() without a session, on a missing motion and on an unexpected failure.
 */
#[CoversClass(CitizenAdviceController::class)]
final class CitizenAdviceControllerOutcomeTest extends TestCase {

	/**
	 * Mock advice service.
	 *
	 * @var CitizenAdviceServiceInterface&MockObject
	 */
	private CitizenAdviceServiceInterface&MockObject $adviceService;

	/**
	 * Mock group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->adviceService = $this->createMock(CitizenAdviceServiceInterface::class);
		$this->groupManager  = $this->createMock(IGroupManager::class);
		$this->logger        = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build the controller for a caller, or for no session when $uid is null.
	 *
	 * @param string|null $uid The caller's UID.
	 *
	 * @return CitizenAdviceController
	 */
	private function controllerFor(?string $uid): CitizenAdviceController {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new CitizenAdviceController(
			request: $this->createMock(IRequest::class),
			citizenAdviceService: $this->adviceService,
			motionService: $this->createMock(MotionServiceInterface::class),
			participantResolver: $this->createMock(ParticipantResolverInterface::class),
			userSession: $session,
			groupManager: $this->groupManager,
			logger: $this->logger,
		);

	}//end controllerFor()

	/**
	 * Without a session open() answers 401 and never reaches the service.
	 *
	 * @return void
	 */
	public function testOpenRefusesAnUnauthenticatedCaller(): void {
		$this->groupManager->expects($this->never())->method('isAdmin');
		$this->adviceService->expects($this->never())->method('open');

		$response = $this->controllerFor(null)->open('motion-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['message' => 'Unauthorized'], $response->getData());

	}//end testOpenRefusesAnUnauthenticatedCaller()

	/**
	 * A motion the service cannot find answers 404 with its message.
	 *
	 * @return void
	 */
	public function testOpenOnAMissingMotionIsNotFound(): void {
		$this->groupManager->method('isAdmin')->with('admin')->willReturn(true);
		$this->adviceService->expects($this->once())
			->method('open')
			->with('motion-404')
			->willThrowException(new NotFoundException('Motion not found'));

		$response = $this->controllerFor('admin')->open('motion-404');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['message' => 'Motion not found'], $response->getData());

	}//end testOpenOnAMissingMotionIsNotFound()

	/**
	 * An unexpected failure is logged and answers 500 with a generic message
	 * that does not leak the cause.
	 *
	 * @return void
	 */
	public function testCloseLogsAnUnexpectedFailureAndAnswers500(): void {
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->expects($this->once())
			->method('isInGroup')
			->with('griffier', CitizenAdviceController::SECRETARIAT_GROUP)
			->willReturn(true);
		$this->adviceService->expects($this->once())
			->method('close')
			->with('motion-1')
			->willThrowException(new \RuntimeException('database gone'));
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'Decidiq: opening or closing the advisory vote failed',
				['exception' => 'database gone']
			);

		$response = $this->controllerFor('griffier')->close('motion-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['message' => 'The advisory vote could not be changed'], $response->getData());

	}//end testCloseLogsAnUnexpectedFailureAndAnswers500()
}//end class
