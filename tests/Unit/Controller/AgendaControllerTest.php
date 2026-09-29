<?php

/**
 * Unit tests for AgendaController — auth guard assertions.
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

use OCA\Decidiq\Controller\AgendaController;
use OCA\Decidiq\Service\AgendaAuthorizationGuard;
use OCA\Decidiq\Service\AgendaService;
use OCA\Decidiq\Service\ParticipantResolver;
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
	 * A session for a logged-in user who is a chair or secretary of the
	 * meeting, or not.
	 *
	 * @param bool $chair Whether the participant resolver says chair/secretary
	 *
	 * @return IUserSession
	 */
	private function sessionFor(bool $chair): IUserSession {
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('clerk');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->participantResolver->method('hasRole')->willReturn($chair);
		return $session;
	}//end sessionFor()

	/**
	 * The chair or secretary marks an item as a formality.
	 *
	 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-afh-001-formalities-are-marked-and-adopted-together
	 *
	 * @return void
	 */
	public function testTheSecretaryMarksAFormality(): void {
		$this->request->method('getParam')->with('isFormality')->willReturn(true);
		$this->agendaService->expects($this->once())->method('setFormality')->with('meeting-uuid-001', 'item-4', true);

		$result = $this->buildController($this->sessionFor(chair: true))->formality('meeting-uuid-001', 'item-4');

		self::assertSame(Http::STATUS_OK, $result->getStatus());
	}//end testTheSecretaryMarksAFormality()

	/**
	 * A member who is not chair or secretary cannot mark formalities.
	 *
	 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-afh-001-formalities-are-marked-and-adopted-together
	 *
	 * @return void
	 */
	public function testAMemberCannotMarkAFormality(): void {
		$this->agendaService->expects($this->never())->method('setFormality');

		$result = $this->buildController($this->sessionFor(chair: false))->formality('meeting-uuid-001', 'item-4');

		self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
	}//end testAMemberCannotMarkAFormality()

	/**
	 * An item of another meeting reads as a bad request, with the reason.
	 *
	 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-afh-001-formalities-are-marked-and-adopted-together
	 *
	 * @return void
	 */
	public function testAnItemOfAnotherMeetingIsABadRequest(): void {
		$this->request->method('getParam')->willReturn(true);
		$this->agendaService->method('setFormality')->willThrowException(new \InvalidArgumentException('This agenda item is not on this meeting.'));

		$result = $this->buildController($this->sessionFor(chair: true))->formality('meeting-uuid-001', 'item-4');

		self::assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
		self::assertSame('This agenda item is not on this meeting.', $result->getData()['message']);
	}//end testAnItemOfAnotherMeetingIsABadRequest()

	/**
	 * Adopting the formalities answers how many were adopted.
	 *
	 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-afh-001-formalities-are-marked-and-adopted-together
	 *
	 * @return void
	 */
	public function testAdoptingAnswersHowManyWereAdopted(): void {
		$this->agendaService->method('processHamerstukken')->willReturn(3);

		$result = $this->buildController($this->sessionFor(chair: true))->processHamerstukken('meeting-uuid-001');

		self::assertSame(['success' => true, 'adopted' => 3], $result->getData());
	}//end testAdoptingAnswersHowManyWereAdopted()

	/**
	 * The formality route reaches the method.
	 *
	 * @return void
	 */
	public function testTheFormalityRouteReachesTheController(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$byName = array_column($routes['routes'], 'url', 'name');

		self::assertSame('/api/agendas/{meetingId}/items/{itemId}/formality', ($byName['agenda#formality'] ?? null));
	}//end testTheFormalityRouteReachesTheController()

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

	/**
	 * The chair makes an item current; the choice is saved on the meeting.
	 *
	 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-001-everyone-follows-the-current-item
	 *
	 * @return void
	 */
	public function testTheChairMakesAnItemCurrent(): void {
		$this->request->method('getParam')->with('agendaItem')->willReturn('item-5');
		$this->agendaService->expects($this->once())->method('setCurrentItem')->with('meeting-uuid-001', 'item-5');

		$result = $this->buildController($this->sessionFor(chair: true))->currentItem('meeting-uuid-001');

		self::assertSame(Http::STATUS_OK, $result->getStatus());
		self::assertSame(['success' => true, 'currentAgendaItem' => 'item-5'], $result->getData());
	}//end testTheChairMakesAnItemCurrent()

	/**
	 * A member who is not chair or secretary cannot move the meeting on.
	 *
	 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-001-everyone-follows-the-current-item
	 *
	 * @return void
	 */
	public function testAMemberCannotMakeAnItemCurrent(): void {
		$this->agendaService->expects($this->never())->method('setCurrentItem');

		$result = $this->buildController($this->sessionFor(chair: false))->currentItem('meeting-uuid-001');

		self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
	}//end testAMemberCannotMakeAnItemCurrent()

	/**
	 * An item of another meeting cannot be made current here.
	 *
	 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-001-everyone-follows-the-current-item
	 *
	 * @return void
	 */
	public function testAnItemOfAnotherMeetingCannotBeMadeCurrent(): void {
		$this->request->method('getParam')->willReturn('item-9');
		$this->agendaService->method('setCurrentItem')->willThrowException(new \InvalidArgumentException('This agenda item is not on this meeting.'));

		$result = $this->buildController($this->sessionFor(chair: true))->currentItem('meeting-uuid-001');

		self::assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
	}//end testAnItemOfAnotherMeetingCannotBeMadeCurrent()

	/**
	 * The current-item route reaches the method.
	 *
	 * @return void
	 */
	public function testTheCurrentItemRouteReachesTheController(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$byName = array_column($routes['routes'], 'url', 'name');
		$verbs = array_column($routes['routes'], 'verb', 'name');

		self::assertSame('/api/agendas/{meetingId}/current-item', ($byName['agenda#currentItem'] ?? null));
		self::assertSame('PUT', ($verbs['agenda#currentItem'] ?? null));
	}//end testTheCurrentItemRouteReachesTheController()

}//end class
