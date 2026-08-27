<?php

/**
 * Unit tests for MigrateBoardProxyToProxyAuthorization repair step.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/migration.md#ocadecideskrepairmigrateboardproxytoproxyauthorization
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Repair;

use OCA\Decidiq\Repair\MigrateBoardProxyToProxyAuthorization;
use OCA\Decidiq\Service\ParticipantToPersonMembershipResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for MigrateBoardProxyToProxyAuthorization.
 *
 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/migration.md#ocadecideskrepairmigrateboardproxytoproxyauthorization
 */
class MigrateBoardProxyToProxyAuthorizationTest extends TestCase {

	/**
	 * Wrap a plain array as an ObjectEntity double.
	 *
	 * @param array<string, mixed> $data The object payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * Build a step wired to fixture board-proxy source rows, existing
	 * proxy-authorization rows (for the idempotency index), a resolvable
	 * meeting, and a crosswalk resolver double.
	 *
	 * @param array<int, array<string, mixed>> $sourceRows Fixture board-proxy rows
	 * @param array<int, array<string, mixed>> $targetRows Fixture pre-existing proxy-authorization rows
	 * @param array<string, array{person: string, membership: string}|null> $crosswalk Participant -> Person/Membership map
	 * @param array<string> $knownMeetings Meeting ids that resolve as existing
	 * @param array<int, array<string, mixed>> $saved Captured saveObject() calls
	 * @param boolean $sourceFindAllThrows Whether findAll() should throw for the board-proxy schema
	 * @param boolean $targetFindAllThrows Whether findAll() should throw for the proxy-authorization schema
	 * @param array<int, object> $rawSourceEntities Raw entity doubles appended to the board-proxy findAll() result, bypassing entity()
	 * @param array<int, object> $rawTargetEntities Raw entity doubles appended to the proxy-authorization findAll() result, bypassing entity()
	 * @param array<int, string> $saveObjectThrowsForGrantor Grantor person ids for which saveObject() should throw
	 *
	 * @return MigrateBoardProxyToProxyAuthorization
	 */
	private function makeStep(
		array $sourceRows,
		array $targetRows,
		array $crosswalk,
		array $knownMeetings,
		array &$saved = [],
		bool $sourceFindAllThrows = false,
		bool $targetFindAllThrows = false,
		array $rawSourceEntities = [],
		array $rawTargetEntities = [],
		array $saveObjectThrowsForGrantor = [],
	): MigrateBoardProxyToProxyAuthorization {
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use (
				$sourceRows,
				$targetRows,
				$sourceFindAllThrows,
				$targetFindAllThrows,
				$rawSourceEntities,
				$rawTargetEntities
			): array {
				$schema = ($config['filters']['schema'] ?? '');
				if ($schema === 'board-proxy') {
					if ($sourceFindAllThrows === true) {
						throw new \RuntimeException('board-proxy schema unavailable');
					}

					return array_merge(array_map(fn (array $r) => $this->entity(data: $r), $sourceRows), $rawSourceEntities);
				}

				if ($schema === 'proxy-authorization') {
					if ($targetFindAllThrows === true) {
						throw new \RuntimeException('proxy-authorization schema unavailable');
					}

					return array_merge(array_map(fn (array $r) => $this->entity(data: $r), $targetRows), $rawTargetEntities);
				}

				return [];
			}
		);
		$objectService->method('find')->willReturnCallback(
			function (
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				string|int|null $register = null,
				string|int|null $schema = null,
			) use ($knownMeetings) {
				if ($schema === 'meeting' && in_array($id, $knownMeetings, true) === true) {
					return $this->entity(data: ['id' => $id]);
				}

				return null;
			}
		);

		$savedRef = &$saved;
		$objectService->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$savedRef, $saveObjectThrowsForGrantor) {
				if (in_array(($object['grantor'] ?? null), $saveObjectThrowsForGrantor, true) === true) {
					throw new \RuntimeException('save failed for grantor ' . (string)($object['grantor'] ?? ''));
				}

				$row = array_merge(['id' => 'pa-' . count($savedRef)], $object);
				$savedRef[] = $row;
				return $this->entity(data: $row);
			}
		);

		$resolver = $this->createMock(originalClassName: ParticipantToPersonMembershipResolver::class);
		$resolver->method('resolve')->willReturnCallback(
			static function (string $participantId) use ($crosswalk): ?array {
				if (array_key_exists($participantId, $crosswalk) === true) {
					return $crosswalk[$participantId];
				}

				return null;
			}
		);

		return new MigrateBoardProxyToProxyAuthorization(
			objectService: $objectService,
			resolver: $resolver,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end makeStep()

	/**
	 * A resolvable board-proxy row creates exactly one proxy-authorization
	 * object with signatureStatus unsigned and proxyStatus copied verbatim.
	 *
	 * @return void
	 */
	public function testRunMigratesResolvableRow(): void {
		$saved = [];
		$step = $this->makeStep(
			sourceRows: [
				[
					'id' => 'bp-1',
					'grantorIntegration' => 'participant-grantor',
					'holderIntegration' => 'participant-holder',
					'meetingIntegration' => 'meeting-1',
					'proxyStatus' => 'active',
				],
			],
			targetRows: [],
			crosswalk: [
				'participant-grantor' => ['person' => 'person-grantor', 'membership' => 'membership-grantor'],
				'participant-holder' => ['person' => 'person-holder', 'membership' => 'membership-holder'],
			],
			knownMeetings: ['meeting-1'],
			saved: $saved,
		);

		$step->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertCount(expectedCount: 1, haystack: $saved);
		$this->assertSame(expected: 'person-grantor', actual: $saved[0]['grantor']);
		$this->assertSame(expected: 'person-holder', actual: $saved[0]['holder']);
		$this->assertSame(expected: 'meeting-1', actual: $saved[0]['meeting']);
		$this->assertSame(expected: 'unsigned', actual: $saved[0]['signatureStatus']);
		$this->assertSame(expected: 'active', actual: $saved[0]['proxyStatus']);

	}//end testRunMigratesResolvableRow()

	/**
	 * A board-proxy row whose meeting cannot be resolved is skipped: no
	 * object is created and the source row is left alone (never asserted
	 * mutated here since the source rows array is never written to).
	 *
	 * @return void
	 */
	public function testRunSkipsRowWithUnresolvableMeeting(): void {
		$saved = [];
		$step = $this->makeStep(
			sourceRows: [
				[
					'id' => 'bp-2',
					'grantorIntegration' => 'participant-grantor',
					'holderIntegration' => 'participant-holder',
					'meetingIntegration' => 'meeting-missing',
					'proxyStatus' => 'active',
				],
			],
			targetRows: [],
			crosswalk: [
				'participant-grantor' => ['person' => 'person-grantor', 'membership' => 'membership-grantor'],
				'participant-holder' => ['person' => 'person-holder', 'membership' => 'membership-holder'],
			],
			knownMeetings: [],
			saved: $saved,
		);

		$step->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertCount(expectedCount: 0, haystack: $saved);

	}//end testRunSkipsRowWithUnresolvableMeeting()

	/**
	 * A board-proxy row whose grantor cannot be resolved through the
	 * crosswalk is skipped, not migrated with a blank grantor.
	 *
	 * @return void
	 */
	public function testRunSkipsRowWithUnresolvableGrantor(): void {
		$saved = [];
		$step = $this->makeStep(
			sourceRows: [
				[
					'id' => 'bp-3',
					'grantorIntegration' => 'participant-unknown',
					'holderIntegration' => 'participant-holder',
					'meetingIntegration' => 'meeting-1',
					'proxyStatus' => 'active',
				],
			],
			targetRows: [],
			crosswalk: [
				'participant-holder' => ['person' => 'person-holder', 'membership' => 'membership-holder'],
			],
			knownMeetings: ['meeting-1'],
			saved: $saved,
		);

		$step->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertCount(expectedCount: 0, haystack: $saved);

	}//end testRunSkipsRowWithUnresolvableGrantor()

	/**
	 * A second run is a no-op for a row already migrated: a
	 * proxy-authorization object with the same (grantor, holder, meeting)
	 * triple already exists, so no duplicate is created.
	 *
	 * @return void
	 */
	public function testRunIsIdempotentAgainstExistingTargetRow(): void {
		$saved = [];
		$step = $this->makeStep(
			sourceRows: [
				[
					'id' => 'bp-4',
					'grantorIntegration' => 'participant-grantor',
					'holderIntegration' => 'participant-holder',
					'meetingIntegration' => 'meeting-1',
					'proxyStatus' => 'active',
				],
			],
			targetRows: [
				[
					'id' => 'pa-existing',
					'grantor' => 'person-grantor',
					'holder' => 'person-holder',
					'meeting' => 'meeting-1',
					'proxyStatus' => 'active',
					'signatureStatus' => 'unsigned',
				],
			],
			crosswalk: [
				'participant-grantor' => ['person' => 'person-grantor', 'membership' => 'membership-grantor'],
				'participant-holder' => ['person' => 'person-holder', 'membership' => 'membership-holder'],
			],
			knownMeetings: ['meeting-1'],
			saved: $saved,
		);

		$step->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertCount(expectedCount: 0, haystack: $saved, message: 'A matching proxy-authorization already exists — no duplicate is created');

	}//end testRunIsIdempotentAgainstExistingTargetRow()

	/**
	 * A board-proxy row whose holder cannot be resolved through the
	 * crosswalk is skipped, not migrated with a blank holder.
	 *
	 * @return void
	 */
	public function testRunSkipsRowWithUnresolvableHolder(): void {
		$saved = [];
		$step = $this->makeStep(
			sourceRows: [
				[
					'id' => 'bp-5',
					'grantorIntegration' => 'participant-grantor',
					'holderIntegration' => 'participant-unknown',
					'meetingIntegration' => 'meeting-1',
					'proxyStatus' => 'active',
				],
			],
			targetRows: [],
			crosswalk: [
				'participant-grantor' => ['person' => 'person-grantor', 'membership' => 'membership-grantor'],
			],
			knownMeetings: ['meeting-1'],
			saved: $saved,
		);

		$step->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertCount(expectedCount: 0, haystack: $saved);

	}//end testRunSkipsRowWithUnresolvableHolder()

	/**
	 * A board-proxy row carrying neither 'id' nor 'uuid' is skipped before
	 * any crosswalk resolution: the fixture below makes the grantor,
	 * holder, and meeting all fully resolvable, so if the empty-sourceId
	 * guard were ever removed the row would migrate and this assertion
	 * would fail.
	 *
	 * @return void
	 */
	public function testRunSkipsRowWithEmptySourceId(): void {
		$saved = [];
		$step = $this->makeStep(
			sourceRows: [
				[
					'grantorIntegration' => 'participant-grantor',
					'holderIntegration' => 'participant-holder',
					'meetingIntegration' => 'meeting-1',
					'proxyStatus' => 'active',
				],
			],
			targetRows: [],
			crosswalk: [
				'participant-grantor' => ['person' => 'person-grantor', 'membership' => 'membership-grantor'],
				'participant-holder' => ['person' => 'person-holder', 'membership' => 'membership-holder'],
			],
			knownMeetings: ['meeting-1'],
			saved: $saved,
		);

		$step->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertCount(expectedCount: 0, haystack: $saved);

	}//end testRunSkipsRowWithEmptySourceId()

	/**
	 * An entity that offers neither jsonSerialize() nor getObject() cannot
	 * be normalised to an array; it must be skipped without a fatal error
	 * and without producing a saved row.
	 *
	 * @return void
	 */
	public function testRunSkipsUnparseableSourceEntity(): void {
		$saved = [];
		$step = $this->makeStep(
			sourceRows: [],
			targetRows: [],
			crosswalk: [
				'participant-grantor' => ['person' => 'person-grantor', 'membership' => 'membership-grantor'],
				'participant-holder' => ['person' => 'person-holder', 'membership' => 'membership-holder'],
			],
			knownMeetings: ['meeting-1'],
			saved: $saved,
			rawSourceEntities: [new \stdClass()],
		);

		$step->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertCount(expectedCount: 0, haystack: $saved);

	}//end testRunSkipsUnparseableSourceEntity()

	/**
	 * When objectService::findAll() throws for the board-proxy schema,
	 * run() must swallow the exception and return having created nothing,
	 * rather than letting the throwable propagate out of the repair step.
	 *
	 * @return void
	 */
	public function testRunSwallowsSourceFindAllException(): void {
		$saved = [];
		$step = $this->makeStep(
			sourceRows: [],
			targetRows: [],
			crosswalk: [],
			knownMeetings: [],
			saved: $saved,
			sourceFindAllThrows: true,
		);

		$step->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertCount(expectedCount: 0, haystack: $saved);

	}//end testRunSwallowsSourceFindAllException()

	/**
	 * When objectService::saveObject() throws for one resolved row,
	 * saveMigratedRow() must report that row as not migrated (via
	 * IOutput::warning()) but processing must continue: a second, healthy
	 * row after the failing one must still be saved.
	 *
	 * @return void
	 */
	public function testRunContinuesAfterOneSaveFailure(): void {
		$saved = [];
		$step = $this->makeStep(
			sourceRows: [
				[
					'id' => 'bp-fail',
					'grantorIntegration' => 'participant-fail-grantor',
					'holderIntegration' => 'participant-holder',
					'meetingIntegration' => 'meeting-1',
					'proxyStatus' => 'active',
				],
				[
					'id' => 'bp-ok',
					'grantorIntegration' => 'participant-ok-grantor',
					'holderIntegration' => 'participant-holder',
					'meetingIntegration' => 'meeting-1',
					'proxyStatus' => 'active',
				],
			],
			targetRows: [],
			crosswalk: [
				'participant-fail-grantor' => ['person' => 'person-fail', 'membership' => 'membership-fail'],
				'participant-ok-grantor' => ['person' => 'person-ok', 'membership' => 'membership-ok'],
				'participant-holder' => ['person' => 'person-holder', 'membership' => 'membership-holder'],
			],
			knownMeetings: ['meeting-1'],
			saved: $saved,
			saveObjectThrowsForGrantor: ['person-fail'],
		);

		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('warning');

		$step->run(output: $output);

		$this->assertCount(expectedCount: 1, haystack: $saved);
		$this->assertSame(expected: 'person-ok', actual: $saved[0]['grantor']);

	}//end testRunContinuesAfterOneSaveFailure()

	/**
	 * When objectService::findAll() throws for the proxy-authorization
	 * schema, buildTargetIndex() must come back empty rather than crash
	 * run() — a normally-resolvable source row must still migrate
	 * successfully despite the index build failing.
	 *
	 * @return void
	 */
	public function testRunToleratesTargetIndexBuildFailure(): void {
		$saved = [];
		$step = $this->makeStep(
			sourceRows: [
				[
					'id' => 'bp-6',
					'grantorIntegration' => 'participant-grantor',
					'holderIntegration' => 'participant-holder',
					'meetingIntegration' => 'meeting-1',
					'proxyStatus' => 'active',
				],
			],
			targetRows: [],
			crosswalk: [
				'participant-grantor' => ['person' => 'person-grantor', 'membership' => 'membership-grantor'],
				'participant-holder' => ['person' => 'person-holder', 'membership' => 'membership-holder'],
			],
			knownMeetings: ['meeting-1'],
			saved: $saved,
			targetFindAllThrows: true,
		);

		$step->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertCount(expectedCount: 1, haystack: $saved);
		$this->assertSame(expected: 'person-grantor', actual: $saved[0]['grantor']);

	}//end testRunToleratesTargetIndexBuildFailure()

	/**
	 * getName() is descriptive.
	 *
	 * @return void
	 */
	public function testGetNameIsDescriptive(): void {
		$step = new MigrateBoardProxyToProxyAuthorization(
			objectService: $this->createMock(originalClassName: ObjectServiceInterface::class),
			resolver: $this->createMock(originalClassName: ParticipantToPersonMembershipResolver::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$this->assertStringContainsString(needle: 'board-proxy', haystack: $step->getName());
		$this->assertStringContainsString(needle: 'proxy-authorization', haystack: $step->getName());

	}//end testGetNameIsDescriptive()
}//end class
