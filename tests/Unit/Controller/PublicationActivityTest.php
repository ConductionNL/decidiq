<?php

/**
 * Tests: the publish endpoint takes a public meeting as an activity entry.
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
 * @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-004-staff-publish-a-public-meeting-to-the-residents-calendar
 */
class PublicationActivityTest extends TestCase {

	/**
	 * The source types the guard and the service were asked about.
	 *
	 * @var list<string>
	 */
	private array $asked = [];

	/**
	 * A publish request for the activity entry of meeting-1.
	 *
	 * @return PublicationController
	 */
	private function controller(): PublicationController {
		$service = $this->createMock(PublicationService::class);
		$service->method('publish')->willReturnCallback(
			function (string $sourceType): array {
				$this->asked[] = 'service:' . $sourceType;
				return ['record' => ['id' => 'record-1', 'sourceType' => $sourceType]];
			}
		);

		$guard = $this->createMock(PublicationStaffGuard::class);
		$guard->method('currentUid')->willReturn('griffier');
		$guard->method('checkSource')->willReturnCallback(
			function (string $sourceType): string {
				$this->asked[] = 'guard:' . $sourceType;
				return PublicationStaffGuard::ALLOWED;
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap(
			[
				['sourceType', '', 'activity'],
				['sourceId', '', 'meeting-1'],
			]
		);

		return new PublicationController($request, $service, $guard);
	}//end controller()

	/**
	 * An activity publish request reaches the staff check and the service.
	 *
	 * @return void
	 */
	public function testAnActivityIsAcceptedAndCheckedForStaff(): void {
		$response = $this->controller()->publish();

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame(['guard:activity', 'service:activity'], $this->asked);
	}//end testAnActivityIsAcceptedAndCheckedForStaff()
}//end class
