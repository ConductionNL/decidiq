<?php

/**
 * Unit tests for VotingController — auth guard and attribute assertions.
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

use OCA\Decidiq\Controller\VotingController;
use OCA\Decidiq\Service\OriPublicationService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\ProxyDelegationService;
use OCA\Decidiq\Service\VotingErrorResponder;
use OCA\Decidiq\Service\VotingOpenRequestHandler;
use OCA\Decidiq\Service\VotingRoundGuard;
use OCA\Decidiq\Service\VotingService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for VotingController auth guards.
 *
 * Every state-changing endpoint must return 401 for unauthenticated requests
 * and carry the #[NoAdminRequired] PHP attribute so that NC middleware
 * resolves auth before CSRF in authenticated scenarios.
 */
class VotingControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock VotingService.
	 *
	 * @var VotingService&MockObject
	 */
	private VotingService&MockObject $votingService;

	/**
	 * Mock OriPublicationService.
	 *
	 * @var OriPublicationService&MockObject
	 */
	private OriPublicationService&MockObject $oriPublicationService;

	/**
	 * Mock IUserSession — unauthenticated (getUser returns null).
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $unauthSession;

	/**
	 * Mock IGroupManager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock LoggerInterface.
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
		$this->votingService = $this->createMock(VotingService::class);
		$this->oriPublicationService = $this->createMock(OriPublicationService::class);
		$this->unauthSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->unauthSession->method('getUser')->willReturn(null);

	}//end setUp()

	/**
	 * Build a VotingController with the given user session.
	 *
	 * @param IUserSession $session The session to inject.
	 *
	 * @return VotingController
	 */
	private function buildController(IUserSession $session): VotingController {
		$participantResolver = $this->createMock(ParticipantResolver::class);
		$container = $this->createMock(ContainerInterface::class);

		return new VotingController(
			request: $this->request,
			votingService: $this->votingService,
			oriService: $this->oriPublicationService,
			userSession: $session,
			guard: new VotingRoundGuard(
				userSession: $session,
				groupManager: $this->groupManager,
				appConfig: $this->appConfig,
				participantResolver: $participantResolver,
				container: $container,
			),
			openHandler: new VotingOpenRequestHandler(votingService: $this->votingService),
			proxyService: new ProxyDelegationService(
				container: $container,
				logger: $this->logger,
				objectService: $this->createMock(ObjectServiceInterface::class),
			),
			errors: new VotingErrorResponder(logger: $this->logger),
		);

	}//end buildController()

	/**
	 * open() returns 401 for unauthenticated requests.
	 *
	 * @return void
	 */
	public function testOpenUnauthenticatedReturns401(): void {
		$this->votingService->expects($this->never())->method('openVotingRound');

		$result = $this->buildController($this->unauthSession)->open();

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
		self::assertArrayHasKey('message', $result->getData());

	}//end testOpenUnauthenticatedReturns401()

	/**
	 * cast() returns 401 for unauthenticated requests.
	 *
	 * @return void
	 */
	public function testCastUnauthenticatedReturns401(): void {
		$this->votingService->expects($this->never())->method('castVote');

		$result = $this->buildController($this->unauthSession)->cast('round-uuid-001');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
		self::assertArrayHasKey('message', $result->getData());

	}//end testCastUnauthenticatedReturns401()

	/**
	 * close() returns 401 for unauthenticated requests.
	 *
	 * @return void
	 */
	public function testCloseUnauthenticatedReturns401(): void {
		$this->votingService->expects($this->never())->method('closeVotingRound');

		$result = $this->buildController($this->unauthSession)->close('round-uuid-001');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
		self::assertArrayHasKey('message', $result->getData());

	}//end testCloseUnauthenticatedReturns401()

	/**
	 * publish() returns 401 for unauthenticated requests.
	 *
	 * @return void
	 */
	public function testPublishUnauthenticatedReturns401(): void {
		$this->oriPublicationService->expects($this->never())->method('publish');

		$result = $this->buildController($this->unauthSession)->publish('round-uuid-001');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
		self::assertArrayHasKey('message', $result->getData());

	}//end testPublishUnauthenticatedReturns401()

	/**
	 * proxy() returns 401 for unauthenticated requests.
	 *
	 * @return void
	 */
	public function testProxyUnauthenticatedReturns401(): void {
		// Negative control: proxy() resolves the acting participant through
		// VotingService immediately AFTER the auth check and before it reaches
		// ProxyDelegationService, so "resolveParticipantUuid was never called"
		// proves the 401 was raised by the guard and not by later work.
		$this->votingService->expects($this->never())->method('resolveParticipantUuid');

		$result = $this->buildController($this->unauthSession)->proxy('round-uuid-001');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
		self::assertArrayHasKey('message', $result->getData());

	}//end testProxyUnauthenticatedReturns401()

	/**
	 * tally() returns 401 for unauthenticated requests.
	 *
	 * @return void
	 */
	public function testTallyUnauthenticatedReturns401(): void {
		$this->votingService->expects($this->never())->method('saveShowOfHandsTally');

		$result = $this->buildController($this->unauthSession)->tally('round-uuid-001');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
		self::assertArrayHasKey('message', $result->getData());

	}//end testTallyUnauthenticatedReturns401()

	/**
	 * revokeProxy() returns 401 for unauthenticated requests.
	 *
	 * @return void
	 */
	public function testRevokeProxyUnauthenticatedReturns401(): void {
		// Negative control: revokeProxy() resolves the acting participant through
		// VotingService immediately AFTER the auth check and before it reaches
		// ProxyDelegationService, so "resolveParticipantUuid was never called"
		// proves the 401 was raised by the guard and not by later work.
		$this->votingService->expects($this->never())->method('resolveParticipantUuid');

		$result = $this->buildController($this->unauthSession)->revokeProxy('round-uuid-001');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
		self::assertArrayHasKey('message', $result->getData());

	}//end testRevokeProxyUnauthenticatedReturns401()

	/**
	 * All public action methods carry the #[NoAdminRequired] PHP attribute.
	 *
	 * Without this attribute, NC middleware may block non-admin authenticated
	 * users before the controller method is invoked (NC 28+).
	 *
	 * @return void
	 */
	public function testAllActionMethodsHaveNoAdminRequiredAttribute(): void {
		$methods = [
			'open',
			'cast',
			'close',
			'publish',
			'proxy',
			'tally',
			'revokeProxy',
		];

		$ref = new \ReflectionClass(VotingController::class);

		foreach ($methods as $methodName) {
			$method = $ref->getMethod($methodName);
			$attributes = $method->getAttributes(NoAdminRequired::class);
			self::assertNotEmpty(
				$attributes,
				"VotingController::{$methodName}() must carry #[NoAdminRequired]"
			);
		}

	}//end testAllActionMethodsHaveNoAdminRequiredAttribute()
}//end class
