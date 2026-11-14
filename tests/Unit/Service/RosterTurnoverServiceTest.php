<?php

/**
 * Unit tests for RosterTurnoverService: the diff only reads, confirmation
 * re-checks against the current state, and no membership is ever written.
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
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTime;
use OCA\Decidiq\Service\Pathway\PathwaySteps;
use OCA\Decidiq\Service\RosterTurnoverService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RosterTurnoverService.
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
 */
#[CoversClass(RosterTurnoverService::class)]
#[UsesClass(PathwaySteps::class)]
class RosterTurnoverServiceTest extends TestCase {

	/**
	 * OpenRegister's object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * Rows per schema.
	 *
	 * @var array<string, list<array<string,mixed>>>
	 */
	private array $data = [];

	/**
	 * Every findAll config, in order.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $reads = [];

	/**
	 * Every saved object as [schema, object].
	 *
	 * @var list<array{0: string, 1: array<string,mixed>}>
	 */
	private array $saved = [];

	/**
	 * The service under test.
	 *
	 * @var RosterTurnoverService
	 */
	private RosterTurnoverService $service;

	/**
	 * A body with three active members (one ending in the future), one
	 * former member, and an open onboarding for a person not yet a member.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->data = [
			'membership'         => [
				['id' => 'm-anna', 'person' => 'p-anna', 'governanceBody' => 'body-1', 'role' => 'member'],
				['id' => 'm-bram', 'person' => ['id' => 'p-bram'], 'governanceBody' => 'body-1', 'role' => 'chair', 'endDate' => '2027-01-01'],
				['id' => 'm-dirk', 'person' => 'p-dirk', 'governanceBody' => 'body-1', 'role' => 'member'],
				['id' => 'm-old', 'person' => 'p-old', 'governanceBody' => 'body-1', 'endDate' => '2026-01-01T00:00:00Z'],
			],
			'person'             => [
				['id' => 'p-anna', 'name' => 'Anna', 'nextcloudUserId' => 'anna'],
				['id' => 'p-bram', 'name' => 'Bram', 'email' => 'bram@example.org'],
				['id' => 'p-dirk', 'name' => 'Dirk', 'email' => 'dirk@example.org'],
				['id' => 'p-old', 'name' => 'Old', 'email' => 'old@example.org'],
				['id' => 'p-fien', 'name' => 'Fien', 'email' => 'fien@example.org'],
			],
			'member-onboarding'  => [
				['id' => 'o-1', 'person' => 'p-fien', 'targetBody' => 'body-1', 'lifecycle' => 'in-execution'],
			],
			'member-offboarding' => [],
		];

		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$this->reads[] = $config;
				$rows = ($this->data[$config['filters']['schema']] ?? []);
				if (isset($config['ids']) === true) {
					$rows = array_values(array_filter($rows, static fn (array $row): bool => in_array($row['id'], $config['ids'], true)));
				}

				foreach (['email', 'nextcloudUserId'] as $field) {
					if (isset($config['filters'][$field]) === true) {
						$rows = array_values(array_filter($rows, static fn (array $row): bool => in_array(($row[$field] ?? null), $config['filters'][$field], true)));
					}
				}

				return $rows;
			}
		);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null): ObjectEntityInterface {
				$this->saved[] = [(string) $schema, $object];
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('getUuid')->willReturn($schema . '-' . count($this->saved));
				return $entity;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-14T10:00:00+00:00'));
		$this->service = new RosterTurnoverService(objectService: $this->objects, time: $time);
	}//end setUp()

	/**
	 * Anna (by account) and Bram (by email, any case) stay; Eva is new;
	 * Dirk is not on the roster and leaves; the former member is ignored;
	 * Fien already has an open onboarding; a row without account or email
	 * is listed as ignored.
	 *
	 * @return void
	 */
	public function testSuggestDiffsTheRosterAgainstTheActiveMembers(): void {
		$result = $this->service->suggest(bodyId: 'body-1', rows: $this->roster());

		$this->assertSame(
			[['key' => 'email:eva@example.org', 'name' => 'Eva', 'email' => 'eva@example.org', 'nextcloudUserId' => '', 'role' => 'member', 'personId' => '']],
			$result['joining']
		);
		$this->assertSame([['membershipId' => 'm-dirk', 'personId' => 'p-dirk', 'name' => 'Dirk', 'role' => 'member']], $result['leaving']);
		$this->assertSame([['name' => 'Nobody', 'reason' => 'no account or email to match on']], $result['ignored']);
		$this->assertSame([], $this->saved);
	}//end testSuggestDiffsTheRosterAgainstTheActiveMembers()

	/**
	 * The diff costs the same few bulk reads however many members there are.
	 *
	 * @return void
	 */
	public function testSuggestReadsInBulk(): void {
		for ($i = 0; $i < 45; $i++) {
			$this->data['membership'][] = ['id' => 'm-' . $i, 'person' => 'p-' . $i, 'governanceBody' => 'body-1'];
			$this->data['person'][]     = ['id' => 'p-' . $i, 'email' => 'member' . $i . '@example.org'];
		}

		$this->service->suggest(bodyId: 'body-1', rows: $this->roster());

		$this->assertCount(6, $this->reads);
		$this->assertSame(['p-anna', 'p-bram', 'p-dirk', 'p-0'], array_slice($this->reads[1]['ids'], 0, 4));
		$this->assertNotContains('p-old', $this->reads[1]['ids']);
	}//end testSuggestReadsInBulk()

	/**
	 * Only confirmed suggestions are created, with one batch label; the
	 * deselected leaver is untouched and no membership is written.
	 *
	 * @return void
	 */
	public function testConfirmCreatesOnlyTheConfirmedSuggestions(): void {
		$this->data['membership'][] = ['id' => 'm-gijs', 'person' => 'p-gijs', 'governanceBody' => 'body-1'];
		$this->data['person'][]     = ['id' => 'p-gijs', 'email' => 'gijs@example.org'];

		$result = $this->service->confirm(
			bodyId: 'body-1',
			rows: $this->roster(),
			joining: ['email:eva@example.org'],
			leaving: ['m-dirk'],
			batch: 'intake-2026',
			endDate: '2026-11-30',
			endReason: ''
		);

		$this->assertSame(['member-onboarding-2'], $result['onboardings']);
		$this->assertSame(['member-offboarding-3'], $result['offboardings']);
		$this->assertSame([], $result['rejected']);
		$this->assertSame(['person', 'member-onboarding', 'member-offboarding'], array_column($this->saved, 0));
		$this->assertSame(['name' => 'Eva', 'email' => 'eva@example.org'], $this->saved[0][1]);

		$onboarding = $this->saved[1][1];
		$this->assertSame('person-1', $onboarding['person']);
		$this->assertSame('body-1', $onboarding['targetBody']);
		$this->assertSame('intake-2026', $onboarding['batch']);
		$this->assertSame('council-turnover-batch', $onboarding['trigger']);
		$this->assertSame('started', $onboarding['lifecycle']);
		$this->assertSame(['open'], array_values(array_unique(array_column($onboarding['steps'], 'status'))));

		$offboarding = $this->saved[2][1];
		$this->assertSame('m-dirk', $offboarding['membership']);
		$this->assertSame('2026-11-30', $offboarding['endDate']);
		$this->assertSame('end-of-term', $offboarding['endReason']);
		$this->assertSame('intake-2026', $offboarding['batch']);
		$this->assertContains('revoke-groups', array_column($offboarding['steps'], 'stepType'));
	}//end testConfirmCreatesOnlyTheConfirmedSuggestions()

	/**
	 * A suggestion that no longer holds when confirmed is rejected, not applied.
	 *
	 * @return void
	 */
	public function testConfirmRejectsASuggestionThatNoLongerHolds(): void {
		$this->data['membership'][] = ['id' => 'm-eva', 'person' => 'p-eva', 'governanceBody' => 'body-1'];
		$this->data['person'][]     = ['id' => 'p-eva', 'email' => 'eva@example.org'];
		$this->data['member-offboarding'][] = ['id' => 'x', 'membership' => 'm-dirk', 'body' => 'body-1', 'lifecycle' => 'started'];

		$result = $this->service->confirm(
			bodyId: 'body-1',
			rows: $this->roster(),
			joining: ['email:eva@example.org'],
			leaving: ['m-dirk'],
			batch: 'intake-2026',
			endDate: '2026-11-30',
			endReason: 'end-of-term'
		);

		$this->assertSame([], $this->saved);
		$this->assertSame(['email:eva@example.org', 'm-dirk'], array_column($result['rejected'], 'suggestion'));
	}//end testConfirmRejectsASuggestionThatNoLongerHolds()

	/**
	 * A batch label is required, and so is a real end date for leavers.
	 *
	 * @return void
	 */
	public function testConfirmValidatesTheBatchAndTheEndDate(): void {
		try {
			$this->service->confirm(bodyId: 'body-1', rows: [], joining: [], leaving: [], batch: ' ', endDate: '', endReason: '');
			$this->fail('An empty batch was accepted.');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame('A batch label is required.', $e->getMessage());
		}

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('An end date (YYYY-MM-DD) is required for leaving members.');
		$this->service->confirm(bodyId: 'body-1', rows: [], joining: [], leaving: ['m-dirk'], batch: 'b', endDate: '2026-02-30', endReason: '');
	}//end testConfirmValidatesTheBatchAndTheEndDate()

	/**
	 * The incoming roster.
	 *
	 * @return list<array<string,string>>
	 */
	private function roster(): array {
		return [
			['name' => 'Anna', 'nextcloudUserId' => 'anna', 'email' => 'anna@example.org'],
			['name' => 'Bram', 'email' => 'BRAM@example.org', 'role' => 'chair'],
			['name' => 'Eva', 'email' => 'eva@example.org'],
			['name' => 'Fien', 'email' => 'fien@example.org'],
			['name' => 'Nobody'],
		];
	}//end roster()

}//end class
