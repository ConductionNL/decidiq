<?php

/**
 * Unit tests for GoverningDocumentSuccession.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service\GoverningDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\GoverningDocument;

use DateTimeImmutable;
use OCA\Decidiq\Service\GoverningDocument\GoverningDocumentSuccession;
use OCA\Decidiq\Service\GoverningDocumentConsolidationService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A version in effect moves to replaced once a later version of its document holds.
 *
 * @covers \OCA\Decidiq\Service\GoverningDocument\GoverningDocumentSuccession
 *
 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-003-version-immutability-once-effective
 */
class GoverningDocumentSuccessionTest extends TestCase {

	/**
	 * A version in effect.
	 *
	 * @param string $id        The version id.
	 * @param string $document  The document id.
	 * @param string $effective The effective date.
	 *
	 * @return array<string, mixed>
	 */
	private static function version(string $id, string $document, string $effective): array {
		return ['id' => $id, 'document' => $document, 'effectiveDate' => $effective, 'status' => 'in-effect', 'notary' => 'mr. A. de Vries'];
	}//end version()

	/**
	 * Version 2 is superseded on the day version 3 takes effect, not before.
	 *
	 * @return void
	 */
	public function testThePredecessorIsSupersededOnceTheSuccessorHolds(): void {
		$versions = [
			self::version(id: 'v2', document: 'doc-1', effective: '2025-01-01'),
			self::version(id: 'v3', document: 'doc-1', effective: '2027-03-01'),
		];

		self::assertSame(expected: [], actual: GoverningDocumentSuccession::superseded(versions: $versions, today: '2027-02-28'));
		self::assertSame(expected: ['v2'], actual: GoverningDocumentSuccession::superseded(versions: $versions, today: '2027-03-01'));
	}//end testThePredecessorIsSupersededOnceTheSuccessorHolds()

	/**
	 * Every earlier version in effect is superseded; other documents and unusable rows are left alone.
	 *
	 * @return void
	 */
	public function testOnlyEarlierVersionsOfTheSameDocumentAreSuperseded(): void {
		$versions = [
			self::version(id: 'v1', document: 'doc-1', effective: '2020-01-01'),
			self::version(id: 'v2', document: 'doc-1', effective: '2024-01-01'),
			self::version(id: 'v3', document: 'doc-1', effective: '2026-01-01'),
			self::version(id: 'w1', document: 'doc-2', effective: '2021-01-01'),
			['id' => 'x1', 'document' => 'doc-1', 'status' => 'in-effect'],
			['id' => 'd1', 'document' => 'doc-1', 'effectiveDate' => '2019-01-01', 'status' => 'draft'],
			['document' => ['id' => 'doc-1'], 'effectiveDate' => '2018-01-01', 'status' => 'in-effect'],
		];

		self::assertSame(expected: ['v1', 'v2'], actual: GoverningDocumentSuccession::superseded(versions: $versions, today: '2026-11-27'));
	}//end testOnlyEarlierVersionsOfTheSameDocumentAreSuperseded()

	/**
	 * The run patches only the state, as the system, and reads only versions in effect.
	 *
	 * @return void
	 */
	public function testTheRunPatchesOnlyTheState(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->expects(self::once())->method('findAll')
			->with(
				self::callback(
					callback: static fn (array $config): bool => ($config['filters'] ?? [])
						=== ['register' => 'decidiq', 'schema' => GoverningDocumentConsolidationService::VERSION_SCHEMA, 'status' => 'in-effect']
				)
			)
			->willReturn(
				[
					'results' => [
						self::version(id: 'v2', document: 'doc-1', effective: '2025-01-01'),
						self::version(id: 'v3', document: 'doc-1', effective: '2026-01-01'),
					],
				]
			);
		$patches = [];
		$entity  = $this->createMock(originalClassName: ObjectEntityInterface::class);
		$objects->method('patchObject')->willReturnCallback(
			static function (ObjectPatch $patch, bool $_rbac=true, bool $_multitenancy=true) use (&$patches, $entity): ObjectEntityInterface {
				$patches[] = [$patch->objectId, $patch->data, $patch->target->schema, $_rbac, $_multitenancy];
				return $entity;
			}
		);

		$result = (new GoverningDocumentSuccession(objectService: $objects, logger: $this->createMock(originalClassName: LoggerInterface::class)))
			->settle(today: new DateTimeImmutable('2026-11-27'));

		self::assertSame(expected: ['replaced' => 1, 'failed' => 0], actual: $result);
		self::assertSame(
			expected: [['v2', ['status' => 'replaced'], GoverningDocumentConsolidationService::VERSION_SCHEMA, false, false]],
			actual: $patches
		);
	}//end testTheRunPatchesOnlyTheState()

	/**
	 * A refused patch is counted and logged; the run goes on.
	 *
	 * @return void
	 */
	public function testARefusedPatchIsCountedAndLogged(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('findAll')->willReturn(
			[
				self::version(id: 'v1', document: 'doc-1', effective: '2020-01-01'),
				self::version(id: 'v2', document: 'doc-1', effective: '2026-01-01'),
			]
		);
		$objects->method('patchObject')->willThrowException(new RuntimeException('refused'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects(self::once())->method('error');

		$result = (new GoverningDocumentSuccession(objectService: $objects, logger: $logger))->settle(today: new DateTimeImmutable('2026-11-27'));

		self::assertSame(expected: ['replaced' => 0, 'failed' => 1], actual: $result);
	}//end testARefusedPatchIsCountedAndLogged()
}//end class
