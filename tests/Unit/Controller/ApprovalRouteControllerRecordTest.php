<?php

/**
 * Unit tests for ApprovalRouteController — recording actions, refused
 * clearance questions and the caller-principal fallback.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\ApprovalRouteController;
use OCA\Decidiq\Service\ApprovalPrincipal;
use OCA\Decidiq\Service\ApprovalRouteConclusionAnnouncer;
use OCA\Decidiq\Service\ApprovalRouteService;
use OCA\Decidiq\Service\HeldRouteRequest;
use OCA\Decidiq\Service\SubjectClearanceService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Recording an approval action, and the refusals around it.
 *
 * @covers \OCA\Decidiq\Controller\ApprovalRouteController
 * @uses   \OCA\Decidiq\Service\HeldRouteRequest
 */
class ApprovalRouteControllerRecordTest extends TestCase {

	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock route engine.
	 *
	 * @var ApprovalRouteService&MockObject
	 */
	private ApprovalRouteService&MockObject $service;

	/**
	 * Mock announcer.
	 *
	 * @var ApprovalRouteConclusionAnnouncer&MockObject
	 */
	private ApprovalRouteConclusionAnnouncer&MockObject $announcer;

	/**
	 * Mock session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

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

		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(ApprovalRouteService::class);
		$this->announcer = $this->createMock(ApprovalRouteConclusionAnnouncer::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build the controller.
	 *
	 * @return ApprovalRouteController
	 */
	private function controller(): ApprovalRouteController {
		return new ApprovalRouteController(
			request: $this->request,
			service: $this->service,
			announcer: $this->announcer,
			clearanceService: new SubjectClearanceService(),
			userSession: $this->session,
			groupManager: $this->groupManager,
			logger: $this->logger,
		);

	}//end controller()

	/**
	 * A user with the given uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser&MockObject
	 */
	private function user(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;

	}//end user()

	/**
	 * Request parameters answered from a map, falling back to the default.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return void
	 */
	private function params(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => array_key_exists($key, $params) ? $params[$key] : $default
		);

	}//end params()

	/**
	 * Recording without a session is refused before anything is read or written.
	 *
	 * @return void
	 */
	public function testRecordAnonymousIs401(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->service->expects($this->never())->method('record');
		$this->announcer->expects($this->never())->method('announceIfConcluded');

		$result = $this->controller()->record();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
		self::assertSame(['message' => 'Unauthorized'], $result->getData());

	}//end testRecordAnonymousIs401()

	/**
	 * The actor is the session user, never the body; defaults fill missing fields.
	 *
	 * @return void
	 */
	public function testRecordTakesTheActorFromTheSessionAndAnnounces(): void {
		$this->session->method('getUser')->willReturn($this->user('alice'));
		$this->params([
			'subject' => 'subj-1',
			'subjectSchema' => 'decision',
			'step' => 2,
			'actor' => 'mallory',
			'action' => 'approve',
			'comment' => 'fine',
		]);

		$expected = [
			'subject' => 'subj-1',
			'subjectSchema' => 'decision',
			'step' => 2,
			'actor' => 'alice',
			'actorType' => 'user',
			'onBehalfOf' => '',
			'mandate' => '',
			'action' => 'approve',
			'returnToStep' => null,
			'comment' => 'fine',
			'advice' => '',
		];
		$this->service->expects($this->once())->method('record')->with($expected)->willReturn(['id' => 'act-1', 'action' => 'approve']);
		$this->announcer->expects($this->once())->method('announceIfConcluded')->with('subj-1');

		$result = $this->controller()->record();

		self::assertSame(Http::STATUS_CREATED, $result->getStatus());
		self::assertSame(['id' => 'act-1', 'action' => 'approve'], $result->getData());

	}//end testRecordTakesTheActorFromTheSessionAndAnnounces()

	/**
	 * An engine refusal is a 400 carrying the reason, logged, and nothing is announced.
	 *
	 * @return void
	 */
	public function testRecordRefusedIs400WithReason(): void {
		$this->session->method('getUser')->willReturn($this->user('alice'));
		$this->params(['subject' => 'subj-1', 'subjectSchema' => 'decision', 'action' => 'approve']);
		$this->service->method('record')->willThrowException(new \DomainException('You are not the approver of this step.'));
		$this->announcer->expects($this->never())->method('announceIfConcluded');
		$this->logger->expects($this->once())->method('warning')->with(
			'Decidiq: an approval action was refused',
			['app' => 'decidiq', 'subject' => 'subj-1', 'reason' => 'You are not the approver of this step.']
		);

		$result = $this->controller()->record();

		self::assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
		self::assertSame(['message' => 'You are not the approver of this step.'], $result->getData());

	}//end testRecordRefusedIs400WithReason()

	/**
	 * A subject the caller cannot reach gets a 400 with the reason, not the clearance.
	 *
	 * @return void
	 */
	public function testClearanceForAnUnreachableSubjectIs400(): void {
		$this->session->method('getUser')->willReturn($this->user('alice'));
		$this->params(['subject' => 'subj-1', 'subjectSchema' => 'decision']);
		$this->service->expects($this->once())->method('assertSubjectAccessible')->with('subj-1', 'decision')
			->willThrowException(new \RuntimeException('Subject not found'));
		$this->service->expects($this->never())->method('routesWithStagesFor');
		$this->logger->expects($this->once())->method('warning')->with(
			'Decidiq: could not answer a clearance question',
			['app' => 'decidiq', 'subject' => 'subj-1', 'reason' => 'Subject not found']
		);

		$result = $this->controller()->clearance();

		self::assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
		self::assertSame(['message' => 'Subject not found'], $result->getData());

	}//end testClearanceForAnUnreachableSubjectIs400()

	/**
	 * A session that ends between the 401 check and the principal lookup is an ordinary principal.
	 *
	 * @return void
	 */
	public function testInstantiateWithAVanishedSessionIsOrdinary(): void {
		$this->session->method('getUser')->willReturnOnConsecutiveCalls($this->user('alice'), null);
		$this->params(['route' => ['steps' => []], 'subject' => 'subj-1', 'subjectSchema' => 'decision']);
		$this->groupManager->expects($this->never())->method('isAdmin');
		$this->service->expects($this->once())->method('instantiate')
			->with(['steps' => []], 'subj-1', 'decision', ApprovalPrincipal::Ordinary)
			->willReturn([['id' => 'stage-1']]);

		$result = $this->controller()->instantiate();

		self::assertSame(Http::STATUS_CREATED, $result->getStatus());
		self::assertSame(['stages' => [['id' => 'stage-1']]], $result->getData());

	}//end testInstantiateWithAVanishedSessionIsOrdinary()

	/**
	 * Instantiating without a session is refused before the engine is touched.
	 *
	 * @return void
	 */
	public function testInstantiateAnonymousIs401(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->service->expects($this->never())->method('assertSubjectAccessible');
		$this->service->expects($this->never())->method('instantiate');

		$result = $this->controller()->instantiate();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
		self::assertSame(['message' => 'Unauthorized'], $result->getData());

	}//end testInstantiateAnonymousIs401()

	/**
	 * Requests missing the route, subject or schema are a 400.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function incompleteInstantiateRequests(): array {
		return [
			'route not an array' => [['route' => 'x', 'subject' => 's', 'subjectSchema' => 'd']],
			'no subject' => [['route' => [], 'subjectSchema' => 'd']],
			'no schema' => [['route' => [], 'subject' => 's']],
		];

	}//end incompleteInstantiateRequests()

	/**
	 * Requests missing the route, subject or schema are a 400 and write nothing.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @dataProvider incompleteInstantiateRequests
	 *
	 * @return void
	 */
	public function testInstantiateIncompleteRequestIs400(array $params): void {
		$this->session->method('getUser')->willReturn($this->user('alice'));
		$this->params($params);
		$this->service->expects($this->never())->method('assertSubjectAccessible');
		$this->service->expects($this->never())->method('instantiate');

		$result = $this->controller()->instantiate();

		self::assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
		self::assertSame(['message' => 'route, subject and subjectSchema are required'], $result->getData());

	}//end testInstantiateIncompleteRequestIs400()

	/**
	 * The substitute fraction a held route is due with.
	 *
	 * @return array<string, array{0: mixed, 1: float|null}>
	 */
	public static function substituteFractions(): array {
		return [
			'not numeric' => ['soon', null],
			'zero' => ['0', null],
			'above one' => ['1.5', null],
			'half' => ['0.5', 0.5],
			'one' => [1, 1.0],
		];

	}//end substituteFractions()

	/**
	 * An adhoc route is held with the deadline and only an in-range substitute fraction.
	 *
	 * @param mixed      $given    The askSubstituteAfter parameter.
	 * @param float|null $expected The fraction the held request should carry.
	 *
	 * @dataProvider substituteFractions
	 *
	 * @return void
	 */
	public function testAdhocRouteIsHeldWithAnInRangeSubstituteFraction(mixed $given, ?float $expected): void {
		$this->session->method('getUser')->willReturn($this->user('alice'));
		$route = ['origin' => 'adhoc', 'actors' => ['bob']];
		$this->params([
			'route' => $route,
			'subject' => 'subj-1',
			'subjectSchema' => 'document',
			'deadline' => '2026-12-01T00:00:00Z',
			'askSubstituteAfter' => $given,
		]);
		$this->service->expects($this->once())->method('heldRouteFrom')->with($route, 'subj-1', 'document')
			->willReturn(new HeldRouteRequest(subject: 'subj-1', subjectSchema: 'document', actors: ['bob']));
		$this->service->expects($this->never())->method('instantiate');
		$this->service->expects($this->once())->method('holdFor')
			->with($this->callback(
				static fn (HeldRouteRequest $held): bool => $held->deadline() === '2026-12-01T00:00:00Z'
					&& $held->askSubstituteAfter() === $expected
			))
			->willReturn([['id' => 'held-1']]);

		$result = $this->controller()->instantiate();

		self::assertSame(Http::STATUS_CREATED, $result->getStatus());
		self::assertSame(['stages' => [['id' => 'held-1']]], $result->getData());

	}//end testAdhocRouteIsHeldWithAnInRangeSubstituteFraction()

}//end class
