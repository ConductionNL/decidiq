<?php

/**
 * Unit tests for BoardEvaluationController's unauthenticated and failure paths.
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
 * @spec openspec/specs/board-self-evaluation/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\BoardEvaluationController;
use OCA\Decidiq\Controller\GovernanceControllerTrait;
use OCA\Decidiq\Kernel\Contracts\BoardEvaluationAccessGuardInterface;
use OCA\Decidiq\Kernel\Contracts\BoardEvaluationReportServiceInterface;
use OCA\Decidiq\Kernel\Contracts\BoardEvaluationResponseServiceInterface;
use OCA\Decidiq\Kernel\Contracts\BoardEvaluationScoreServiceInterface;
use OCA\Decidiq\Kernel\Contracts\ParticipationPublicationServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * close(), publish() and report() without a session, and with a failing service.
 */
#[CoversClass(BoardEvaluationController::class)]
#[UsesClass(GovernanceControllerTrait::class)]
final class BoardEvaluationControllerRefusalTest extends TestCase {

	/**
	 * Mock score service.
	 *
	 * @var BoardEvaluationScoreServiceInterface&MockObject
	 */
	private BoardEvaluationScoreServiceInterface&MockObject $scoreService;

	/**
	 * Mock report service.
	 *
	 * @var BoardEvaluationReportServiceInterface&MockObject
	 */
	private BoardEvaluationReportServiceInterface&MockObject $reportService;

	/**
	 * Mock publication service.
	 *
	 * @var ParticipationPublicationServiceInterface&MockObject
	 */
	private ParticipationPublicationServiceInterface&MockObject $publicationService;

	/**
	 * Mock access guard.
	 *
	 * @var BoardEvaluationAccessGuardInterface&MockObject
	 */
	private BoardEvaluationAccessGuardInterface&MockObject $accessGuard;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->scoreService       = $this->createMock(BoardEvaluationScoreServiceInterface::class);
		$this->reportService      = $this->createMock(BoardEvaluationReportServiceInterface::class);
		$this->publicationService = $this->createMock(ParticipationPublicationServiceInterface::class);
		$this->accessGuard        = $this->createMock(BoardEvaluationAccessGuardInterface::class);

	}//end setUp()

	/**
	 * Build the controller for a caller, or for no session when $uid is null.
	 *
	 * @param string|null $uid The caller's UID.
	 *
	 * @return BoardEvaluationController
	 */
	private function controllerFor(?string $uid): BoardEvaluationController {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new BoardEvaluationController(
			request: $this->createMock(IRequest::class),
			responseService: $this->createMock(BoardEvaluationResponseServiceInterface::class),
			scoreService: $this->scoreService,
			reportService: $this->reportService,
			publicationService: $this->publicationService,
			userSession: $session,
			accessGuard: $this->accessGuard,
		);

	}//end controllerFor()

	/**
	 * Without a session, close/publish/report answer 401 and never reach the
	 * access guard or a service.
	 *
	 * @return void
	 */
	public function testEveryActionRefusesAnUnauthenticatedCaller(): void {
		$this->accessGuard->expects($this->never())->method('requireChairOrSecretary');
		$this->accessGuard->expects($this->never())->method('requireBodyMember');
		$this->scoreService->expects($this->never())->method('closeCycle');
		$this->publicationService->expects($this->never())->method('publishEvaluationResults');
		$this->reportService->expects($this->never())->method('generate');

		$controller = $this->controllerFor(null);

		foreach ([$controller->close('ev-1'), $controller->publish('ev-1'), $controller->report('ev-1')] as $response) {
			self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			self::assertSame(['message' => 'Authentication required.'], $response->getData());
		}

	}//end testEveryActionRefusesAnUnauthenticatedCaller()

	/**
	 * A failing publication answers 422 with the failure's message.
	 *
	 * @return void
	 */
	public function testPublishMapsAFailureToUnprocessable(): void {
		$this->accessGuard->expects($this->once())
			->method('requireChairOrSecretary')
			->with('ev-1')
			->willReturn(null);
		$this->publicationService->expects($this->once())
			->method('publishEvaluationResults')
			->with('ev-1')
			->willThrowException(new \RuntimeException('Evaluation is not closed'));

		$response = $this->controllerFor('chair')->publish('ev-1');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame(['message' => 'Evaluation is not closed'], $response->getData());

	}//end testPublishMapsAFailureToUnprocessable()

	/**
	 * A failing report generation answers 422 with the failure's message.
	 *
	 * @return void
	 */
	public function testReportMapsAFailureToUnprocessable(): void {
		$this->accessGuard->expects($this->once())
			->method('requireBodyMember')
			->with('ev-2')
			->willReturn(null);
		$this->reportService->expects($this->once())
			->method('generate')
			->with('ev-2')
			->willThrowException(new \RuntimeException('Docudesk refused the document'));

		$response = $this->controllerFor('member')->report('ev-2');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame(['message' => 'Docudesk refused the document'], $response->getData());

	}//end testReportMapsAFailureToUnprocessable()
}//end class
