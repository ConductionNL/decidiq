<?php

/**
 * Unit tests for AgendaController — auth guard assertions.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Controller
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

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\AgendaController;
use OCA\Decidesk\Service\AgendaAuthorizationGuard;
use OCA\Decidesk\Service\AgendaService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AgendaController auth guards.
 *
 * Every state-changing endpoint must return 401 for unauthenticated requests
 * before touching any service layer.
 */
class AgendaControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock AgendaService.
	 *
	 * @var AgendaService&MockObject
	 */
	private AgendaService&MockObject $agendaService;

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock IGroupManager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock ParticipantResolver.
	 *
	 * @var ParticipantResolver&MockObject
	 */
	private ParticipantResolver&MockObject $participantResolver;

	/**
	 * Unauthenticated user session (getUser returns null).
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $unauthSession;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->agendaService = $this->createMock(AgendaService::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->participantResolver = $this->createMock(ParticipantResolver::class);
		$this->unauthSession = $this->createMock(IUserSession::class);

		$this->unauthSession->method('getUser')->willReturn(null);

	}//end setUp()

	/**
	 * Build an AgendaController with the given user session.
	 *
	 * The authorization guard is the REAL AgendaAuthorizationGuard over the
	 * same mocks the controller used to hold directly, so these tests still
	 * exercise the actual auth path (in particular that ObjectService::find()
	 * is never reached before the 401).
	 *
	 * @param IUserSession $session The session to inject.
	 *
	 * @return AgendaController
	 */
	private function buildController(IUserSession $session): AgendaController {
		$guard = new AgendaAuthorizationGuard(
			objectService: $this->objectService,
			userSession: $session,
			groupManager: $this->groupManager,
			participantResolver: $this->participantResolver,
		);

		return new AgendaController(
			request: $this->request,
			agendaService: $this->agendaService,
			guard: $guard,
			logger: $this->logger,
		);

	}//end buildController()

	/**
	 * publish() returns 401 for unauthenticated requests.
	 *
	 * @return void
	 */
	public function testPublishUnauthenticatedReturns401(): void {
		$this->agendaService->expects($this->never())->method('publishAgenda');

		$result = $this->buildController($this->unauthSession)->publish('meeting-uuid-001');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
		self::assertArrayHasKey('message', $result->getData());

	}//end testPublishUnauthenticatedReturns401()

	/**
	 * advanceBobPhase() returns 401 for unauthenticated requests without touching ObjectService.
	 *
	 * @return void
	 */
	public function testAdvanceBobPhaseUnauthenticatedReturns401(): void {
		// objectService->find must NOT be called before the auth check.
		$this->objectService->expects($this->never())->method('find');
		$this->agendaService->expects($this->never())->method('advanceBobPhase');

		$result = $this->buildController($this->unauthSession)->advanceBobPhase('item-uuid-001');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
		self::assertArrayHasKey('message', $result->getData());

	}//end testAdvanceBobPhaseUnauthenticatedReturns401()

	/**
	 * processHamerstukken() returns 401 for unauthenticated requests.
	 *
	 * @return void
	 */
	public function testProcessHamerstukkenUnauthenticatedReturns401(): void {
		$this->agendaService->expects($this->never())->method('processHamerstukken');

		$result = $this->buildController($this->unauthSession)->processHamerstukken('meeting-uuid-001');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
		self::assertArrayHasKey('message', $result->getData());

	}//end testProcessHamerstukkenUnauthenticatedReturns401()

	/**
	 * reorder() returns 401 for unauthenticated requests.
	 *
	 * @return void
	 */
	public function testReorderUnauthenticatedReturns401(): void {
		$this->agendaService->expects($this->never())->method('reorderItems');

		$result = $this->buildController($this->unauthSession)->reorder('meeting-uuid-001');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
		self::assertArrayHasKey('message', $result->getData());

	}//end testReorderUnauthenticatedReturns401()

	/**
	 * revise() returns 401 for unauthenticated requests.
	 *
	 * @return void
	 */
	public function testReviseUnauthenticatedReturns401(): void {
		$this->agendaService->expects($this->never())->method('reviseAgenda');

		$result = $this->buildController($this->unauthSession)->revise('meeting-uuid-001');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
		self::assertArrayHasKey('message', $result->getData());

	}//end testReviseUnauthenticatedReturns401()
}//end class
