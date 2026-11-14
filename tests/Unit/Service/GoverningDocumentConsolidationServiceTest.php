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

	/**
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public static function frozenFieldEdits(): array {
		return [
			'effective date' => ['effectiveDate', '2022-02-01'],
			'enacting decision' => ['determinedBy', 'besluit-other'],
			'version number' => ['versionNumber', 7],
			'deed date' => ['deedDate', '2021-12-16'],
			'notary' => ['notary', 'mr. B. Jansen'],
			'document' => ['document', 'another-document'],
		];
	}//end frozenFieldEdits()

	/**
	 * @dataProvider frozenFieldEdits
	 */
	public function testAFrozenFieldOfASealedVersionCannotChange(string $field, mixed $value): void {
		$sealed = self::statutenChain()[1] + ['deedDate' => '2021-12-15', 'notary' => 'mr. A. de Wit'];

		$this->expectException(GoverningDocumentVersionRefusedException::class);
		$this->expectExceptionMessage('version 2 is in effect and sealed, so its ' . $field . ' cannot change');

		$this->service->assertEditable($sealed, array_merge($sealed, [$field => $value]));
	}//end testAFrozenFieldOfASealedVersionCannotChange()

	public function testASealedVersionMayMoveOnAndTakeAnExpiryDate(): void {
		$sealed = self::statutenChain()[1];
		$after = array_merge($sealed, ['status' => 'replaced', 'expiryDate' => '2030-01-01', 'notes' => 'Superseded.', 'effectiveDate' => '2022-01-01T00:00:00+00:00', 'versionNumber' => '2']);

		$this->service->assertEditable($sealed, $after);

		self::addToAssertionCount(1);
	}//end testASealedVersionMayMoveOnAndTakeAnExpiryDate()

	public function testADraftVersionMayStillBeEdited(): void {
		$draft = ['id' => 'v3', 'document' => self::DOC, 'versionNumber' => 3, 'effectiveDate' => '2025-01-01', 'status' => 'adopted', 'determinedBy' => 'besluit-x'];

		$this->service->assertEditable($draft, array_merge($draft, ['effectiveDate' => '2025-06-01', 'determinedBy' => 'besluit-y']));

		self::addToAssertionCount(1);
	}//end testADraftVersionMayStillBeEdited()

	/**
	 * A service over a document, its versions and a governing body, read without rights.
	 *
	 * @param array<string, mixed>             $document The document.
	 * @param array<int, array<string, mixed>> $versions Its versions.
	 * @param array<int, array{0: string, 1: bool, 2: bool}> $finds Every find: [schema, files, rbac].
	 *
	 * @return GoverningDocumentConsolidationService
	 */
	private function publicService(array $document, array $versions, array &$finds = []): GoverningDocumentConsolidationService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturn(['results' => $versions]);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null, bool $_rbac = true) use ($document, $versions, &$finds): ?ObjectEntity {
				$finds[] = [(string)$schema, $files, $_rbac];
				if ($schema === 'governance-body') {
					return $this->document(['id' => $id, 'name' => 'Vereniging van Nederlandse Gemeenten']);
				}

				if ($schema === 'governing-document-versie') {
					foreach ($versions as $version) {
						if ($version['id'] === $id) {
							return $this->document($version + ['@self' => ['files' => [['title' => 'statuten-2022.pdf', 'type' => 'application/pdf', 'accessUrl' => 'https://example.test/s/abc', 'path' => '/secret/path']]]]);
						}
					}
				}

				return $id === self::DOC ? $this->document($document) : null;
			}
		);

		return new GoverningDocumentConsolidationService($objects);
	}//end publicService()

	/**
	 * The statuten, public.
	 *
	 * @return array<string, mixed>
	 */
	private static function publicStatuten(): array {
		return ['id' => self::DOC, 'citationTitle' => 'Statuten Vereniging van Nederlandse Gemeenten', 'type' => 'articles-of-association', 'governingBody' => 'vng', 'status' => 'in-force', 'isPublic' => true, 'description' => 'internal'];
	}//end publicStatuten()

	public function testThePublicViewHoldsOnlyThePublicFieldsOfTheVersionInForce(): void {
		$finds = [];
		$versions = [
			['id' => 'v1', 'document' => self::DOC, 'versionNumber' => 1, 'effectiveDate' => '1990-05-01', 'status' => 'replaced', 'notary' => 'mr. C. van Dam', 'deedDate' => '1990-04-12'],
			['id' => 'v2', 'document' => self::DOC, 'versionNumber' => 2, 'effectiveDate' => '2022-01-01', 'status' => 'in-effect', 'determinedBy' => 'besluit-2021', 'notary' => 'mr. A. de Wit', 'notes' => 'Internal note.'],
			['id' => 'v3', 'document' => self::DOC, 'versionNumber' => 3, 'effectiveDate' => '2024-01-01', 'status' => 'draft', 'determinedBy' => 'besluit-2023'],
		];

		$view = $this->publicService(self::publicStatuten(), $versions, $finds)->publicView(self::DOC, new DateTimeImmutable('2025-03-01'));

		self::assertSame(
			[
				'id' => self::DOC,
				'citationTitle' => 'Statuten Vereniging van Nederlandse Gemeenten',
				'type' => 'articles-of-association',
				'governingBody' => 'Vereniging van Nederlandse Gemeenten',
				'currentVersion' => [
					'versionNumber' => 2,
					'effectiveDate' => '2022-01-01',
					'expiryDate' => null,
					'consolidatedText' => [['title' => 'statuten-2022.pdf', 'type' => 'application/pdf', 'accessUrl' => 'https://example.test/s/abc', 'downloadUrl' => null]],
				],
			],
			$view
		);
		$encoded = (string)json_encode($view);
		foreach (['mr. A. de Wit', 'Internal note.', 'besluit-2021', 'v3', 'internal', '/secret/path'] as $absent) {
			self::assertStringNotContainsString($absent, $encoded, $absent);
		}

		self::assertContains(['governing-document-versie', true, false], $finds, 'the files are read from the version in force');
	}//end testThePublicViewHoldsOnlyThePublicFieldsOfTheVersionInForce()

	public function testAnInternalDocumentHasNoPublicView(): void {
		$versions = self::statutenChain();

		self::assertNull($this->publicService(['isPublic' => false] + self::publicStatuten(), $versions)->publicView(self::DOC, new DateTimeImmutable('2025-03-01')));
		self::assertNull($this->publicService(['isPublic' => 'true'] + self::publicStatuten(), $versions)->publicView(self::DOC, new DateTimeImmutable('2025-03-01')));
		$withoutFlag = self::publicStatuten();
		unset($withoutFlag['isPublic']);
		self::assertNull($this->publicService($withoutFlag, $versions)->publicView(self::DOC, new DateTimeImmutable('2025-03-01')));
	}//end testAnInternalDocumentHasNoPublicView()

	public function testAPublicDocumentWithOnlyADraftVersionHasNoPublicView(): void {
		$versions = [['id' => 'v1', 'document' => self::DOC, 'versionNumber' => 1, 'effectiveDate' => '2020-01-01', 'status' => 'draft']];

		self::assertNull($this->publicService(self::publicStatuten(), $versions)->publicView(self::DOC, new DateTimeImmutable('2025-03-01')));
	}//end testAPublicDocumentWithOnlyADraftVersionHasNoPublicView()

	public function testALapsedOrMissingDocumentHasNoPublicView(): void {
		self::assertNull($this->publicService(['status' => 'lapsed'] + self::publicStatuten(), self::statutenChain())->publicView(self::DOC, new DateTimeImmutable('2025-03-01')));
		self::assertNull($this->publicService(self::publicStatuten(), self::statutenChain())->publicView('unknown', new DateTimeImmutable('2025-03-01')));
	}//end testALapsedOrMissingDocumentHasNoPublicView()

	public function testCitingDecisionsListsOnlyDecisionsCitingThisDocument(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects(self::once())->method('setRegister')->with('decidiq')->willReturnSelf();
		$objects->expects(self::once())->method('setSchema')->with('governing-document')->willReturnSelf();
		$objects->expects(self::once())->method('getObjectUsedBy')
			->with(self::DOC, ['_limit' => 200], true, true)
			->willReturn(
				['results' => [
					['id' => 'd1', 'title' => 'Besluit spoedprocedure inkoop', 'decisionDate' => '2025-02-01', 'citesGoverningDocuments' => [['document' => self::DOC, 'article' => 'art. 14 lid 3'], ['document' => 'other', 'article' => 'art. 1']]],
					$this->document(['id' => 'd2', 'title' => 'Besluit statutenwijziging', 'citesGoverningDocuments' => [['document' => ['id' => self::DOC], 'version' => 'v2', 'article' => 'art. 20']]]),
					['id' => 'm1', 'title' => 'A meeting naming the document elsewhere', 'agenda' => [self::DOC]],
					['id' => 'd3', 'title' => 'Citing another document', 'citesGoverningDocuments' => [['document' => 'other']]],
				]]
			);

		$cited = (new GoverningDocumentConsolidationService($objects))->citingDecisions(self::DOC);

		self::assertSame(
			[
				['id' => 'd1', 'title' => 'Besluit spoedprocedure inkoop', 'decisionDate' => '2025-02-01', 'articles' => ['art. 14 lid 3'], 'versions' => []],
				['id' => 'd2', 'title' => 'Besluit statutenwijziging', 'decisionDate' => null, 'articles' => ['art. 20'], 'versions' => ['v2']],
			],
			$cited
		);
	}//end testCitingDecisionsListsOnlyDecisionsCitingThisDocument()

	public function testADecisionWithoutCitationsCitesNothing(): void {
		self::assertSame([], GoverningDocumentConsolidationService::citationsOf(['id' => 'd'], self::DOC));
		self::assertSame([], GoverningDocumentConsolidationService::citationsOf(['citesGoverningDocuments' => 'statuten-vng'], self::DOC));
		self::assertSame([], GoverningDocumentConsolidationService::citationsOf(['citesGoverningDocuments' => [['document' => self::DOC]]], ''));
	}//end testADecisionWithoutCitationsCitesNothing()

	public function testAVersionPutInEffectWritesItsDateOntoItsDocument(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn($this->document(['id' => self::DOC, 'citationTitle' => 'Statuten', 'currentEffectiveDate' => '1990-05-01', '@self' => ['id' => self::DOC]]));
		$saved = [];
		$objects->expects(self::once())->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true) use (&$saved): ObjectEntity {
				$saved = [$object, $register, $schema, $uuid, $_rbac];
				return $this->document([]);
			}
		);

		self::assertTrue((new GoverningDocumentConsolidationService($objects))->recordCurrentEffectiveDate(self::statutenChain()[1]));
		self::assertSame([['id' => self::DOC, 'citationTitle' => 'Statuten', 'currentEffectiveDate' => '2022-01-01'], 'decidiq', 'governing-document', self::DOC, false], $saved);
	}//end testAVersionPutInEffectWritesItsDateOntoItsDocument()

	public function testAVersionNotInEffectOrAnUnchangedDateWritesNothing(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn($this->document(['id' => self::DOC, 'currentEffectiveDate' => '2022-01-01']));
		$objects->expects(self::never())->method('saveObject');
		$service = new GoverningDocumentConsolidationService($objects);

		self::assertFalse($service->recordCurrentEffectiveDate(self::statutenChain()[1]));
		self::assertFalse($service->recordCurrentEffectiveDate(self::statutenChain()[0]));
		self::assertFalse($service->recordCurrentEffectiveDate(['document' => self::DOC, 'status' => 'in-effect']));
	}//end testAVersionNotInEffectOrAnUnchangedDateWritesNothing()

	public function testUnreadableSiblingsRefuseTheSave(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willThrowException(new RuntimeException('database gone'));

		$this->expectException(GoverningDocumentVersionRefusedException::class);
		(new GoverningDocumentConsolidationService($objects))->assertAcceptable(['document' => self::DOC, 'versionNumber' => 3]);
	}//end testUnreadableSiblingsRefuseTheSave()
}//end class
