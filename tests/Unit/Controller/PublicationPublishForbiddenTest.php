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
		$guard = $this->createMock(originalClassName: PublicationStaffGuard::class);
		$guard->method('currentUid')->willReturn($uid);
		$guard->method('checkSource')->willReturn($outcome);

		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnMap(
			[
				['sourceType', '', 'decision'],
				['sourceId', '', 'decision-1'],
			]
		);

		return new PublicationController(request: $request, publicationService: $service, staffGuard: $guard);
	}//end controller()

	/**
	 * A caller without chair/secretary authority gets 403 and nothing is published.
	 *
	 * @return void
	 */
	public function testANonStaffCallerIsRefusedAndNothingIsPublished(): void {
		$service = $this->createMock(originalClassName: PublicationService::class);
		$service->expects(self::never())->method('publish');

		$response = $this->controller(outcome: PublicationStaffGuard::FORBIDDEN, service: $service)->publish();

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
	}//end testANonStaffCallerIsRefusedAndNothingIsPublished()

	/**
	 * An unauthenticated caller gets 401 and nothing is published.
	 *
	 * @return void
	 */
	public function testAnUnauthenticatedCallerIsRefusedAndNothingIsPublished(): void {
		$service = $this->createMock(originalClassName: PublicationService::class);
		$service->expects(self::never())->method('publish');

		$response = $this->controller(outcome: PublicationStaffGuard::ALLOWED, service: $service, uid: null)->publish();

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
	}//end testAnUnauthenticatedCallerIsRefusedAndNothingIsPublished()
}//end class
