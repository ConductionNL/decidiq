<?php

/**
 * Unit tests for VoteBreakdownController.
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
 * @spec openspec/specs/motion-and-voting/spec.md#requirement-req-vrf-001-results-per-faction-and-per-member
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\VoteBreakdownController;
use OCA\Decidiq\Service\VoteBreakdownService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for VoteBreakdownController.
 *
 * @spec openspec/specs/motion-and-voting/spec.md#requirement-req-vrf-001-results-per-faction-and-per-member
 */
class VoteBreakdownControllerTest extends TestCase {

	/**
	 * Build the controller.
	 *
	 * @param bool       $loggedIn Whether someone is logged in
	 * @param array|null $result   What the service returns
	 *
	 * @return VoteBreakdownController
	 */
	private function makeController(bool $loggedIn, ?array $result): VoteBreakdownController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($loggedIn ? $this->createMock(IUser::class) : null);
		$service = $this->createMock(VoteBreakdownService::class);
		$service->method('forRound')->willReturn($result);
		$service->method('canReadRound')->willReturn($result !== null);

		return new VoteBreakdownController($this->createMock(IRequest::class), $service, $session);
	}//end makeController()

	/**
	 * A member reads the breakdown of a round they can see.
	 *
	 * @return void
	 */
	public function testAMemberReadsTheBreakdown(): void {
		$breakdown = ['secret' => false, 'totals' => ['for' => 1, 'against' => 0, 'abstain' => 0], 'members' => [], 'factions' => []];
		$response = $this->makeController(loggedIn: true, result: $breakdown)->show(id: 'round-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($breakdown, $response->getData());
	}//end testAMemberReadsTheBreakdown()

	/**
	 * A round the user cannot read is refused.
	 *
	 * @return void
	 */
	public function testAnUnreadableRoundIsRefused(): void {
		$response = $this->makeController(loggedIn: true, result: null)->show(id: 'round-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAnUnreadableRoundIsRefused()

	/**
	 * Without a login nothing is shown.
	 *
	 * @return void
	 */
	public function testAnonymousIsUnauthorised(): void {
		$response = $this->makeController(loggedIn: false, result: [])->show(id: 'round-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnonymousIsUnauthorised()

	/**
	 * The route reaches the method.
	 *
	 * @return void
	 */
	public function testTheRouteReachesTheController(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$byName = array_column($routes['routes'], 'url', 'name');

		self::assertSame('/api/voting-rounds/{id}/breakdown', ($byName['voteBreakdown#show'] ?? null));
		self::assertTrue(method_exists(VoteBreakdownController::class, 'show'));
	}//end testTheRouteReachesTheController()
}//end class
