<?php

/**
 * The clerk is told why an agenda was not published.
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
use OCA\Decidiq\Exception\ConfidentialityUnreadableException;
use OCA\Decidiq\Service\PublicationService;
use OCA\Decidiq\Service\PublicationStaffGuard;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * When the confidentiality restrictions cannot be read, publishing and
 * rectifying answer 503 with the reason, not a bare server error.
 *
 * @covers \OCA\Decidiq\Controller\PublicationController
 *
 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-pps-002-a-confidential-item-never-reaches-the-public
 */
class PublicationUnreadableRestrictionsTest extends TestCase {

	/**
	 * A controller whose service cannot read the restrictions.
	 *
	 * @return PublicationController
	 */
	private function controller(): PublicationController {
		$unreadable = new ConfidentialityUnreadableException('The agenda was not published: the confidential items could not be checked. Try again in a moment.');
		$service    = $this->createMock(PublicationService::class);
		$service->method('publish')->willThrowException($unreadable);
		$service->method('rectify')->willThrowException($unreadable);

		$guard = $this->createMock(PublicationStaffGuard::class);
		$guard->method('currentUid')->willReturn('griffier');
		$guard->method('checkSource')->willReturn(PublicationStaffGuard::ALLOWED);
		$guard->method('checkRecord')->willReturn(PublicationStaffGuard::ALLOWED);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap(
			[
				['sourceType', '', 'agenda'],
				['sourceId', '', 'meeting-1'],
				['reason', '', 'Title corrected'],
			]
		);

		return new PublicationController($request, $service, $guard);
	}//end controller()

	/**
	 * Publishing answers 503 and says the confidential items could not be checked.
	 *
	 * @return void
	 */
	public function testPublishingSaysWhyItWasRefused(): void {
		$response = $this->controller()->publish();

		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		self::assertStringContainsString('could not be checked', $response->getData()['message']);
	}//end testPublishingSaysWhyItWasRefused()

	/**
	 * Rectifying answers the same way.
	 *
	 * @return void
	 */
	public function testRectifyingSaysWhyItWasRefused(): void {
		$response = $this->controller()->rectify('record-1');

		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		self::assertStringContainsString('could not be checked', $response->getData()['message']);
	}//end testRectifyingSaysWhyItWasRefused()
}//end class
