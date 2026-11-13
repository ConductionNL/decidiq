<?php

/**
 * GET /api/governing-documents/{id}/in-force: date validation, the read guard and the answer.
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
 * @spec openspec/changes/governing-documents-register
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\Decidiq\Controller\GoverningDocumentController;
use OCA\Decidiq\Kernel\Contracts\GoverningDocumentConsolidationServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Controller\GoverningDocumentController
 */
class GoverningDocumentControllerTest extends TestCase {

	private GoverningDocumentConsolidationServiceInterface&MockObject $consolidation;

	protected function setUp(): void {
		parent::setUp();
		$this->consolidation = $this->createMock(GoverningDocumentConsolidationServiceInterface::class);
	}//end setUp()

	private function controller(bool $loggedIn = true): GoverningDocumentController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($loggedIn ? $this->createMock(IUser::class) : null);

		return new GoverningDocumentController($this->createMock(IRequest::class), $this->consolidation, $session);
	}//end controller()

	public function testAMemberReadsTheVersionInForceOnADate(): void {
		$this->consolidation->method('readableDocument')->with('doc-1')->willReturn(['id' => 'doc-1']);
		$this->consolidation->expects(self::once())->method('inForceOn')
			->with('doc-1', self::callback(static fn (DateTimeImmutable $d): bool => $d->format('Y-m-d') === '2021-06-15'))
			->willReturn(['id' => 'v1', 'versionNumber' => 1, 'effectiveDate' => '1990-05-01', 'status' => 'replaced', 'notary' => 'mr. C. van Dam']);

		$response = $this->controller()->inForce('doc-1', '2021-06-15');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			[
				'document' => 'doc-1',
				'date' => '2021-06-15',
				'version' => ['id' => 'v1', 'versionNumber' => 1, 'effectiveDate' => '1990-05-01', 'expiryDate' => null, 'status' => 'replaced', 'determinedBy' => null],
			],
			$response->getData()
		);
	}//end testAMemberReadsTheVersionInForceOnADate()

	public function testNoVersionInForceAnswersANullVersion(): void {
		$this->consolidation->method('readableDocument')->willReturn(['id' => 'doc-1']);
		$this->consolidation->method('inForceOn')->willReturn(null);

		$response = $this->controller()->inForce('doc-1', '1985-01-01');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertNull($response->getData()['version']);
	}//end testNoVersionInForceAnswersANullVersion()

	/**
	 * @return array<string, array{string}>
	 */
	public static function malformedDates(): array {
		return [
			'not a date' => ['yesterday'],
			'no such day' => ['2021-02-30'],
			'a time attached' => ['2021-06-15T00:00:00'],
			'day first' => ['15-06-2021'],
		];
	}//end malformedDates()

	/**
	 * @dataProvider malformedDates
	 */
	public function testAMalformedDateIsRefusedBeforeAnythingIsRead(string $date): void {
		$this->consolidation->expects(self::never())->method('readableDocument');
		$this->consolidation->expects(self::never())->method('inForceOn');

		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->inForce('doc-1', $date)->getStatus());
	}//end testAMalformedDateIsRefusedBeforeAnythingIsRead()

	public function testADocumentTheCallerCannotReadIsNotFoundAndNoVersionIsRead(): void {
		$this->consolidation->method('readableDocument')->willReturn(null);
		$this->consolidation->expects(self::never())->method('inForceOn');

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller()->inForce('doc-1', '2021-06-15')->getStatus());
	}//end testADocumentTheCallerCannotReadIsNotFoundAndNoVersionIsRead()

	public function testWithoutADateItAnswersForToday(): void {
		$this->consolidation->method('readableDocument')->willReturn(['id' => 'doc-1']);
		$this->consolidation->method('inForceOn')->willReturn(null);

		$response = $this->controller()->inForce('doc-1');

		self::assertSame((new DateTimeImmutable('today'))->format('Y-m-d'), $response->getData()['date']);
	}//end testWithoutADateItAnswersForToday()

	public function testAnAnonymousCallerIsRefused(): void {
		$this->consolidation->expects(self::never())->method('readableDocument');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(loggedIn: false)->inForce('doc-1', '2021-06-15')->getStatus());
	}//end testAnAnonymousCallerIsRefused()
}//end class
