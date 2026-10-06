<?php

/**
 * Unit tests for VotingRoundLiveController — auth guard and attribute assertions.
 *
 * The live-tally role split is covered by VotingPermissionsTest, which wires
 * the REAL VotingRoundGuard.
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

use OCA\Decidiq\Controller\VotingRoundLiveController;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\ProxyDelegationService;
use OCA\Decidiq\Service\VotingErrorResponder;
use OCA\Decidiq\Service\VotingRoundGuard;
use OCA\Decidiq\Service\VotingRoundResults;
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
use Psr\Log\NullLogger;

/**
 * Tests for VotingRoundLiveController auth guards.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VotingRoundLiveControllerTest extends TestCase {

	/**
	 * Mock VotingService.
	 *
	 * @var VotingService&MockObject
	 */
	private VotingService&MockObject $votingService;

	/**
	 * Mock VotingRoundResults.
	 *
	 * @var VotingRoundResults&MockObject
	 */
	private VotingRoundResults&MockObject $results;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->votingService = $this->createMock(VotingService::class);
		$this->results = $this->createMock(VotingRoundResults::class);

	}//end setUp()

	/**
	 * Build a VotingRoundLiveController for an anonymous caller.
	 *
	 * @return VotingRoundLiveController
	 */
	private function buildAnonymousController(): VotingRoundLiveController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$container = $this->createMock(ContainerInterface::class);

		return new VotingRoundLiveController(
			request: $this->createMock(IRequest::class),
			votingService: $this->votingService,
			results: $this->results,
			userSession: $session,
			guard: new VotingRoundGuard(
				userSession: $session,
				groupManager: $this->createMock(IGroupManager::class),
				appConfig: $this->createMock(IAppConfig::class),
				participantResolver: $this->createMock(ParticipantResolver::class),
				container: $container,
			),
			proxyService: new ProxyDelegationService(
				container: $container,
				logger: new NullLogger(),
				objectService: $this->createMock(ObjectServiceInterface::class),
			),
			errors: new VotingErrorResponder(logger: new NullLogger()),
		);

	}//end buildAnonymousController()

	/**
	 * proxies() returns 401 for unauthenticated requests.
	 *
	 * @return void
	 */
	public function testProxiesUnauthenticatedReturns401(): void {
		$this->votingService->expects($this->never())->method('resolveParticipantUuid');

		$result = $this->buildAnonymousController()->proxies('round-uuid-001');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());

	}//end testProxiesUnauthenticatedReturns401()

	/**
	 * liveTally() returns 401 for unauthenticated requests without reading the round.
	 *
	 * @return void
	 */
	public function testLiveTallyUnauthenticatedReturns401(): void {
		$this->results->expects($this->never())->method('liveCounts');

		$result = $this->buildAnonymousController()->liveTally('round-uuid-001');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());

	}//end testLiveTallyUnauthenticatedReturns401()

	/**
	 * Both actions carry the #[NoAdminRequired] PHP attribute.
	 *
	 * @return void
	 */
	public function testAllActionMethodsHaveNoAdminRequiredAttribute(): void {
		$ref = new \ReflectionClass(VotingRoundLiveController::class);

		foreach (['liveTally', 'proxies'] as $methodName) {
			self::assertNotEmpty(
				$ref->getMethod($methodName)->getAttributes(NoAdminRequired::class),
				"VotingRoundLiveController::{$methodName}() must carry #[NoAdminRequired]"
			);
		}

	}//end testAllActionMethodsHaveNoAdminRequiredAttribute()
}//end class
