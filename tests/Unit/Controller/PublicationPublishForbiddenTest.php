<?php

/**
 * Tests: publishing a decision is refused server-side for a non-staff caller.
 *
 * The Decisions index used to declare a Publish row action with
 * `permission: decidesk.decision.publish`, which nothing reads (#1263, #1270).
 * The action is gone; what stands between an account and publishing is this
 * endpoint's per-object staff guard, so the denied case is pinned here.
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

use OCA\Decidiq\Controller\PublicationController;
use OCA\Decidiq\Service\PublicationService;
use OCA\Decidiq\Service\PublicationStaffGuard;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Controller\PublicationController
 *
 * @spec openspec/specs/public-publication/spec.md
 */
class PublicationPublishForbiddenTest extends TestCase {

	/**
	 * A decision publish request, with the guard answering $outcome.
	 *
	 * @param string             $outcome The staff guard's answer.
	 * @param PublicationService $service The publication service mock.
	 * @param string|null        $uid     The current uid, or null when logged out.
	 *
	 * @return PublicationController
	 */
	private function controller(string $outcome, PublicationService $service, ?string $uid='member'): PublicationController {
		$guard = $this->createMock(PublicationStaffGuard::class);
		$guard->method('currentUid')->willReturn($uid);
		$guard->method('checkSource')->willReturn($outcome);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap(
			[
				['sourceType', '', 'decision'],
				['sourceId', '', 'decision-1'],
			]
		);

		return new PublicationController($request, $service, $guard);
	}//end controller()

	/**
	 * A caller without chair/secretary authority gets 403 and nothing is published.
	 *
	 * @return void
	 */
	public function testANonStaffCallerIsRefusedAndNothingIsPublished(): void {
		$service = $this->createMock(PublicationService::class);
		$service->expects(self::never())->method('publish');

		$response = $this->controller(PublicationStaffGuard::FORBIDDEN, $service)->publish();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testANonStaffCallerIsRefusedAndNothingIsPublished()

	/**
	 * An unauthenticated caller gets 401 and nothing is published.
	 *
	 * @return void
	 */
	public function testAnUnauthenticatedCallerIsRefusedAndNothingIsPublished(): void {
		$service = $this->createMock(PublicationService::class);
		$service->expects(self::never())->method('publish');

		$response = $this->controller(PublicationStaffGuard::ALLOWED, $service, null)->publish();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnUnauthenticatedCallerIsRefusedAndNothingIsPublished()
}//end class
