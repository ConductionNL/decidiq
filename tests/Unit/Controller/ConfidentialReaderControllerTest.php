<?php

/**
 * The readers routes answer the service's result or its refusal, and pass
 * the number only to the service (portal-voting-record-and-confidential-papers task 3).
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
 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\ConfidentialReaderController;
use OCA\Decidiq\Exception\ConfidentialReaderRefusedException;
use OCA\Decidiq\Service\ConfidentialReaders;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ConfidentialReaderController.
 *
 * @covers \OCA\Decidiq\Controller\ConfidentialReaderController
 * @uses   \OCA\Decidiq\Exception\ConfidentialReaderRefusedException
 */
class ConfidentialReaderControllerTest extends TestCase {

	/**
	 * A controller over a request with these parameters.
	 *
	 * @param ConfidentialReaders  $readers The service.
	 * @param array<string, mixed> $params  The request parameters.
	 *
	 * @return ConfidentialReaderController
	 */
	private function controller(ConfidentialReaders $readers, array $params=[]): ConfidentialReaderController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default=null) => ($params[$key] ?? $default)
		);

		return new ConfidentialReaderController(request: $request, readers: $readers);
	}//end controller()

	/**
	 * A reader added answers 201 with the readers; the body reaches the service as typed.
	 *
	 * @return void
	 */
	public function testAddingAReaderAnswersCreatedWithTheReaders(): void {
		$readers = $this->createMock(ConfidentialReaders::class);
		$readers->expects(self::once())->method('add')
			->with('item-1', 'pieter-bos', 'bsn', '111222333', '')
			->willReturn(['authorisedPersons' => ['pieter-bos'], 'person' => 'pieter-bos']);

		$response = $this->controller(
			readers: $readers,
			params: ['person' => 'pieter-bos', 'identityType' => 'BSN', 'number' => '111222333']
		)->create(id: 'item-1');

		self::assertSame(201, $response->getStatus());
		self::assertSame(['authorisedPersons' => ['pieter-bos'], 'person' => 'pieter-bos'], $response->getData());
		self::assertStringNotContainsString('111222333', json_encode($response->getData()));
	}//end testAddingAReaderAnswersCreatedWithTheReaders()

	/**
	 * A refusal answers its own status and reason.
	 *
	 * @return void
	 */
	public function testARefusalAnswersItsStatusAndReason(): void {
		$readers = $this->createMock(ConfidentialReaders::class);
		$readers->method('add')->willThrowException(new ConfidentialReaderRefusedException(message: 'Make the item confidential first.', status: 409));
		$readers->method('remove')->willThrowException(new ConfidentialReaderRefusedException(message: 'Only the secretariat.', status: 403));

		$added = $this->controller(readers: $readers)->create(id: 'item-1');
		$removed = $this->controller(readers: $readers)->destroy(id: 'item-1', personId: 'pieter-bos');

		self::assertSame(409, $added->getStatus());
		self::assertSame(['message' => 'Make the item confidential first.'], $added->getData());
		self::assertSame(403, $removed->getStatus());
	}//end testARefusalAnswersItsStatusAndReason()

	/**
	 * Removing a reader answers 200 with the readers left.
	 *
	 * @return void
	 */
	public function testRemovingAReaderAnswersTheReadersLeft(): void {
		$readers = $this->createMock(ConfidentialReaders::class);
		$readers->expects(self::once())->method('remove')->with('item-1', 'pieter-bos')->willReturn(['authorisedPersons' => []]);

		$response = $this->controller(readers: $readers)->destroy(id: 'item-1', personId: 'pieter-bos');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['authorisedPersons' => []], $response->getData());
	}//end testRemovingAReaderAnswersTheReadersLeft()
}//end class
