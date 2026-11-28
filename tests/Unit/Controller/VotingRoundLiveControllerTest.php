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
use OCA\Decidiq\Service\VotingRoundGuard;
use OCA\Decidiq\Service\VotingRoundResults;
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

/**
 * Tests for VotingRoundLiveController auth guards.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VotingRoundLiveControllerTest extends TestCase {

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

		$this->results = $this->createMock(originalClassName: VotingRoundResults::class);

	}//end setUp()

	/**
	 * Build a VotingRoundLiveController for an anonymous caller.
	 *
	 * @return VotingRoundLiveController
	 */
	private function buildAnonymousController(): VotingRoundLiveController {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$container = $this->createMock(originalClassName: ContainerInterface::class);

		return new VotingRoundLiveController(
			request: $this->createMock(originalClassName: IRequest::class),
			results: $this->results,
			userSession: $session,
			guard: new VotingRoundGuard(
				userSession: $session,
				groupManager: $this->createMock(originalClassName: IGroupManager::class),
				appConfig: $this->createMock(originalClassName: IAppConfig::class),
				participantResolver: $this->createMock(originalClassName: ParticipantResolver::class),
				container: $container,
			),
		);

	}//end buildAnonymousController()

	/**
	 * liveTally() returns 401 for unauthenticated requests without reading the round.
	 *
	 * @return void
	 */
	public function testLiveTallyUnauthenticatedReturns401(): void {
		$this->results->expects($this->never())->method('liveCounts');

		$result = $this->buildAnonymousController()->liveTally('round-uuid-001');

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $result->getStatus());

	}//end testLiveTallyUnauthenticatedReturns401()

	/**
	 * Both actions carry the #[NoAdminRequired] PHP attribute.
	 *
	 * @return void
	 */
	public function testAllActionMethodsHaveNoAdminRequiredAttribute(): void {
		$ref = new \ReflectionClass(VotingRoundLiveController::class);

		foreach (['liveTally'] as $methodName) {
			self::assertNotEmpty(
				actual: $ref->getMethod($methodName)->getAttributes(NoAdminRequired::class),
				message: "VotingRoundLiveController::{$methodName}() must carry #[NoAdminRequired]"
			);
		}

	}//end testAllActionMethodsHaveNoAdminRequiredAttribute()
}//end class
