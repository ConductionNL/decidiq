<?php

/**
 * In-force resolution, activation ordering and the trace rule of governing-document versions.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
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

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Decidiq\Exception\GoverningDocumentVersionRefusedException;
use OCA\Decidiq\Service\GoverningDocumentConsolidationService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Over the statuten chain of the design: v1 sealed 1990-05-01 and replaced, v2 in effect from 2022-01-01.
 *
 * @covers \OCA\Decidiq\Service\GoverningDocumentConsolidationService
 */
class GoverningDocumentConsolidationServiceTest extends TestCase {

	private const DOC = 'statuten-vng';

	private ObjectServiceInterface&MockObject $objects;

	/**
	 * Every findAll() call: [config, rbac, multitenancy].
	 *
	 * @var array<int, array{0: array, 1: bool, 2: bool}>
	 */
	private array $reads = [];

	/**
	 * The versions findAll() answers.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $versions = [];

	private GoverningDocumentConsolidationService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->versions = self::statutenChain();
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->reads[] = [$config, $_rbac, $_multitenancy];
				return ['results' => $this->versions];
			}
		);
		$this->service = new GoverningDocumentConsolidationService($this->objects);
	}//end setUp()

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function statutenChain(): array {
		return [
			['id' => 'v1', 'document' => self::DOC, 'versionNumber' => 1, 'effectiveDate' => '1990-05-01', 'status' => 'replaced'],
			['id' => 'v2', 'document' => self::DOC, 'versionNumber' => 2, 'effectiveDate' => '2022-01-01', 'status' => 'in-effect', 'determinedBy' => 'besluit-2021'],
		];
	}//end statutenChain()

	/**
	 * A document entity double.
	 *
	 * @param array<string, mixed> $row The document.
	 *
	 * @return ObjectEntity
	 */
	private function document(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($row);
		return $entity;
	}//end document()

	/**
	 * The id of the version resolved on a date, or null.
	 *
	 * @param array<int, array<string, mixed>> $versions The versions.
	 * @param string                           $date     The date.
	 *
	 * @return string|null
	 */
	private static function resolved(array $versions, string $date): ?string {
		return (GoverningDocumentConsolidationService::resolveInForce($versions, $date)['id'] ?? null);
	}//end resolved()

	public function testAHistoricalDateResolvesTheVersionThenInForce(): void {
		self::assertSame('v1', self::resolved(self::statutenChain(), '2021-06-15'));
	}//end testAHistoricalDateResolvesTheVersionThenInForce()

	public function testBeforeTheFirstEffectiveDateNoVersionIsInForce(): void {
		self::assertNull(self::resolved(self::statutenChain(), '1985-01-01'));
	}//end testBeforeTheFirstEffectiveDateNoVersionIsInForce()

	public function testAVersionIsInForceOnItsOwnEffectiveDate(): void {
		self::assertSame('v2', self::resolved(self::statutenChain(), '2022-01-01'));
		self::assertSame('v1', self::resolved(self::statutenChain(), '2021-12-31'));
	}//end testAVersionIsInForceOnItsOwnEffectiveDate()

	public function testAVersionIsNoLongerInForceOnItsExpiryDate(): void {
		$versions = [['id' => 'v1', 'effectiveDate' => '2020-01-01', 'expiryDate' => '2024-01-01', 'status' => 'lapsed']];

		self::assertSame('v1', self::resolved($versions, '2023-12-31'));
		self::assertNull(self::resolved($versions, '2024-01-01'));
	}//end testAVersionIsNoLongerInForceOnItsExpiryDate()

	public function testDraftAndAdoptedVersionsAreNeverInForce(): void {
		$versions = self::statutenChain();
		$versions[] = ['id' => 'v3', 'effectiveDate' => '2024-01-01', 'status' => 'adopted'];
		$versions[] = ['id' => 'v4', 'effectiveDate' => '2025-01-01', 'status' => 'draft'];

		self::assertSame('v2', self::resolved($versions, '2026-01-01'));
	}//end testDraftAndAdoptedVersionsAreNeverInForce()

	public function testAReplacedVersionDoesNotComeBackAfterItsSuccessorExpired(): void {
		$versions = [
			['id' => 'v1', 'effectiveDate' => '2010-01-01', 'status' => 'replaced'],
			['id' => 'v2', 'effectiveDate' => '2015-01-01', 'expiryDate' => '2018-01-01', 'status' => 'lapsed'],
		];

		self::assertNull(self::resolved($versions, '2019-01-01'));
	}//end testAReplacedVersionDoesNotComeBackAfterItsSuccessorExpired()

	public function testInForceOnReadsAsTheCallerAndOnlyThisDocumentsVersions(): void {
		$this->versions[] = ['id' => 'other', 'document' => 'another-doc', 'versionNumber' => 1, 'effectiveDate' => '2023-01-01', 'status' => 'in-effect'];
		$this->objects->method('find')->willReturn($this->document(['id' => self::DOC, 'status' => 'in-effect']));

		$version = $this->service->inForceOn(self::DOC, new DateTimeImmutable('2024-01-01'));

		self::assertSame('v2', $version['id']);
		self::assertSame([['filters' => ['register' => 'decidiq', 'schema' => 'governing-document-versie', 'document' => self::DOC]], true, true], $this->reads[0]);
	}//end testInForceOnReadsAsTheCallerAndOnlyThisDocumentsVersions()

	public function testALapsedDocumentHasNoVersionInForce(): void {
		$this->objects->method('find')->willReturn($this->document(['id' => self::DOC, 'status' => 'lapsed']));

		self::assertNull($this->service->inForceOn(self::DOC, new DateTimeImmutable('2024-01-01')));
	}//end testALapsedDocumentHasNoVersionInForce()

	public function testADocumentTheCallerCannotReadIsNull(): void {
		$this->objects->expects(self::exactly(2))->method('find')
			->with(self::DOC, self::anything(), self::anything(), 'decidiq', 'governing-document', true)
			->willThrowException(new DoesNotExistException('nope'));

		self::assertNull($this->service->readableDocument(self::DOC));
		self::assertNull($this->service->inForceOn(self::DOC, new DateTimeImmutable('2024-01-01')));
		self::assertSame([], $this->reads, 'No version is read for a document the caller cannot read');
	}//end testADocumentTheCallerCannotReadIsNull()

	public function testAnAmendmentWithoutItsEnactingDecisionIsRefused(): void {
		$this->expectException(GoverningDocumentVersionRefusedException::class);
		$this->expectExceptionMessage('determinedBy');

		$this->service->assertAcceptable(['document' => self::DOC, 'versionNumber' => 3, 'status' => 'draft']);
	}//end testAnAmendmentWithoutItsEnactingDecisionIsRefused()

	public function testAConstitutiveFirstVersionMayOmitTheDecision(): void {
		$this->versions = [];

		$this->service->assertAcceptable(['document' => 'splitsingsakte', 'versionNumber' => 1, 'status' => 'draft', 'deedDate' => '2005-05-20']);

		self::assertSame([['filters' => ['register' => 'decidiq', 'schema' => 'governing-document-versie', 'document' => 'splitsingsakte']], false, false], $this->reads[0]);
	}//end testAConstitutiveFirstVersionMayOmitTheDecision()

	public function testOnlyDraftSiblingsDoNotMakeAVersionAnAmendment(): void {
		$this->versions = [['id' => 'd1', 'document' => self::DOC, 'versionNumber' => 1, 'status' => 'draft']];

		$this->service->assertAcceptable(['document' => self::DOC, 'versionNumber' => 2, 'status' => 'draft']);
		$this->addToAssertionCount(1);
	}//end testOnlyDraftSiblingsDoNotMakeAVersionAnAmendment()

	public function testADuplicateVersionNumberIsRefused(): void {
		$this->expectException(GoverningDocumentVersionRefusedException::class);
		$this->expectExceptionMessage('already has a version 2');

		$this->service->assertAcceptable(['document' => self::DOC, 'versionNumber' => 2, 'status' => 'draft', 'determinedBy' => 'besluit-x']);
	}//end testADuplicateVersionNumberIsRefused()

	public function testUpdatingAVersionDoesNotCollideWithItself(): void {
		$this->service->assertAcceptable(['id' => 'v2', 'document' => self::DOC, 'versionNumber' => 2, 'effectiveDate' => '2022-01-01', 'status' => 'in-effect', 'determinedBy' => 'besluit-2021']);
		$this->addToAssertionCount(1);
	}//end testUpdatingAVersionDoesNotCollideWithItself()

	public function testPuttingAVersionInEffectOutOfOrderIsRefused(): void {
		$this->expectException(GoverningDocumentVersionRefusedException::class);
		$this->expectExceptionMessage('2021-06-01 is not after 2022-01-01');

		$this->service->assertAcceptable(['id' => 'v3', 'document' => self::DOC, 'versionNumber' => 3, 'effectiveDate' => '2021-06-01', 'status' => 'in-effect', 'determinedBy' => 'besluit-x']);
	}//end testPuttingAVersionInEffectOutOfOrderIsRefused()

	public function testTheSameEffectiveDateAsTheLatestSealedIsRefused(): void {
		$this->expectException(GoverningDocumentVersionRefusedException::class);

		$this->service->assertAcceptable(['id' => 'v3', 'document' => self::DOC, 'versionNumber' => 3, 'effectiveDate' => '2022-01-01', 'status' => 'in-effect', 'determinedBy' => 'besluit-x']);
	}//end testTheSameEffectiveDateAsTheLatestSealedIsRefused()

	public function testPuttingAVersionInEffectAfterTheLatestIsAccepted(): void {
		$this->service->assertAcceptable(['id' => 'v3', 'document' => self::DOC, 'versionNumber' => 3, 'effectiveDate' => '2025-01-01', 'status' => 'in-effect', 'determinedBy' => 'besluit-x']);
		$this->addToAssertionCount(1);
	}//end testPuttingAVersionInEffectAfterTheLatestIsAccepted()

	public function testADraftWithAnEarlierDateIsNotYetRefused(): void {
		$this->service->assertAcceptable(['id' => 'v3', 'document' => self::DOC, 'versionNumber' => 3, 'effectiveDate' => '2021-06-01', 'status' => 'draft', 'determinedBy' => 'besluit-x']);
		$this->addToAssertionCount(1);
	}//end testADraftWithAnEarlierDateIsNotYetRefused()

	public function testPuttingInEffectWithoutADateIsRefused(): void {
		$this->expectException(GoverningDocumentVersionRefusedException::class);
		$this->expectExceptionMessage('no effective date');

		$this->service->assertAcceptable(['id' => 'v3', 'document' => self::DOC, 'versionNumber' => 3, 'status' => 'in-effect', 'determinedBy' => 'besluit-x']);
	}//end testPuttingInEffectWithoutADateIsRefused()

	public function testUnreadableSiblingsRefuseTheSave(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willThrowException(new RuntimeException('database gone'));

		$this->expectException(GoverningDocumentVersionRefusedException::class);
		(new GoverningDocumentConsolidationService($objects))->assertAcceptable(['document' => self::DOC, 'versionNumber' => 3]);
	}//end testUnreadableSiblingsRefuseTheSave()
}//end class
