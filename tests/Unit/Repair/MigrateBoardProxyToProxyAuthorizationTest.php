<?php

/**
 * Unit tests for MigrateBoardProxyToProxyAuthorization repair step.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/model-debt-cleanup-code/migration.md#migrateboardproxytoproxyauthorization
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Repair;

use OCA\Decidesk\Repair\MigrateBoardProxyToProxyAuthorization;
use OCA\Decidesk\Service\ParticipantToPersonMembershipResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for MigrateBoardProxyToProxyAuthorization.
 *
 * @spec openspec/changes/model-debt-cleanup-code/migration.md#migrateboardproxytoproxyauthorization
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
		$entity = $this->createMock(ObjectEntity::class);
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
	 * @param array<int, array<string, mixed>> &$saved Captured saveObject() calls
	 *
	 * @return MigrateBoardProxyToProxyAuthorization
	 */
	private function makeStep(
		array $sourceRows,
		array $targetRows,
		array $crosswalk,
		array $knownMeetings,
		array &$saved = [],
	): MigrateBoardProxyToProxyAuthorization {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($sourceRows, $targetRows): array {
				$schema = ($config['filters']['schema'] ?? '');
				if ($schema === 'board-proxy') {
					return array_map(fn (array $r) => $this->entity($r), $sourceRows);
				}

				if ($schema === 'proxy-authorization') {
					return array_map(fn (array $r) => $this->entity($r), $targetRows);
				}

				return [];
			}
		);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) use ($knownMeetings) {
				if ($schema === 'meeting' && in_array($id, $knownMeetings, true) === true) {
					return $this->entity(['id' => $id]);
				}

				return null;
			}
		);

		$savedRef = &$saved;
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null) use (&$savedRef) {
				$row = array_merge(['id' => 'pa-' . count($savedRef)], $object);
				$savedRef[] = $row;
				return $this->entity($row);
			}
		);

		$resolver = $this->createMock(ParticipantToPersonMembershipResolver::class);
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
			logger: $this->createMock(LoggerInterface::class),
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

		$step->run(output: $this->createMock(IOutput::class));

		$this->assertCount(1, $saved);
		$this->assertSame('person-grantor', $saved[0]['grantor']);
		$this->assertSame('person-holder', $saved[0]['holder']);
		$this->assertSame('meeting-1', $saved[0]['meeting']);
		$this->assertSame('unsigned', $saved[0]['signatureStatus']);
		$this->assertSame('active', $saved[0]['proxyStatus']);

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

		$step->run(output: $this->createMock(IOutput::class));

		$this->assertCount(0, $saved);

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

		$step->run(output: $this->createMock(IOutput::class));

		$this->assertCount(0, $saved);

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
				['id' => 'pa-existing', 'grantor' => 'person-grantor', 'holder' => 'person-holder', 'meeting' => 'meeting-1', 'proxyStatus' => 'active', 'signatureStatus' => 'unsigned'],
			],
			crosswalk: [
				'participant-grantor' => ['person' => 'person-grantor', 'membership' => 'membership-grantor'],
				'participant-holder' => ['person' => 'person-holder', 'membership' => 'membership-holder'],
			],
			knownMeetings: ['meeting-1'],
			saved: $saved,
		);

		$step->run(output: $this->createMock(IOutput::class));

		$this->assertCount(0, $saved, 'A matching proxy-authorization already exists — no duplicate is created');

	}//end testRunIsIdempotentAgainstExistingTargetRow()

	/**
	 * getName() is descriptive.
	 *
	 * @return void
	 */
	public function testGetNameIsDescriptive(): void {
		$step = new MigrateBoardProxyToProxyAuthorization(
			objectService: $this->createMock(ObjectServiceInterface::class),
			resolver: $this->createMock(ParticipantToPersonMembershipResolver::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertStringContainsString('board-proxy', $step->getName());
		$this->assertStringContainsString('proxy-authorization', $step->getName());

	}//end testGetNameIsDescriptive()
}//end class
