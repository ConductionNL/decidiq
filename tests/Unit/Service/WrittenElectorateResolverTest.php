<?php

/**
 * Tests for WrittenElectorateResolver.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-001-the-chair-or-secretary-of-a-body-puts-a-decision-to-the-body-in-writing
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Decidiq\Service\WrittenElectorateResolver;
use OCA\Decidiq\Service\WrittenRoundStore;
use OCA\Decidiq\Tests\Unit\Support\WrittenRoundWorld;
use PHPUnit\Framework\TestCase;

/**
 * The snapshot of who must answer a written round.
 *
 * @covers \OCA\Decidiq\Service\WrittenElectorateResolver
 * @uses   \OCA\Decidiq\Service\WrittenRoundStore
 */
class WrittenElectorateResolverTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var WrittenRoundWorld
	 */
	private WrittenRoundWorld $world;

	/**
	 * Set up a body with members of every kind.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->world = new WrittenRoundWorld(test: $this);
		$this->world->put('person', ['id' => 'p-janneke', 'name' => 'Janneke de Bruin', 'nextcloudUserId' => 'janneke']);
		$this->world->put('person', ['id' => 'p-jan', 'name' => 'Jan de Vries', 'nextcloudUserId' => 'jan']);
		$this->world->put('person', ['id' => 'p-mark', 'name' => 'Mark van den Berg', 'nextcloudUserId' => 'mark']);
		$this->world->put('person', ['id' => 'p-obs', 'name' => 'Olga Observer', 'nextcloudUserId' => 'olga']);
		$this->world->put('person', ['id' => 'p-old', 'name' => 'Oud Lid', 'nextcloudUserId' => 'oud']);
		$this->world->put('person', ['id' => 'p-new', 'name' => 'Nieuw Lid', 'nextcloudUserId' => 'nieuw']);
		$this->member(id: 'm-janneke', person: 'p-janneke', role: 'chair', weight: 1);
		$this->member(id: 'm-jan', person: 'p-jan', role: 'secretary', weight: 2);
		$this->member(id: 'm-mark', person: 'p-mark', role: 'member', weight: 1);
		$this->member(id: 'm-obs', person: 'p-obs', role: 'observer', weight: 1);
		$this->member(id: 'm-guest', person: 'p-obs', role: 'guest', weight: 1);
		$this->member(id: 'm-old', person: 'p-old', role: 'member', weight: 1, start: '2020-01-01', end: '2026-03-01');
		$this->member(id: 'm-new', person: 'p-new', role: 'member', weight: 1, start: '2026-04-01');
		$this->world->put('membership', ['id' => 'm-other', 'governanceBody' => 'other-body', 'person' => 'p-mark', 'role' => 'member']);
	}//end setUp()

	/**
	 * Add a membership of the board.
	 *
	 * @param string      $id     Membership id.
	 * @param string      $person Person id.
	 * @param string      $role   Role.
	 * @param float       $weight Voting weight.
	 * @param string|null $start  Start date.
	 * @param string|null $end    End date.
	 *
	 * @return void
	 */
	private function member(string $id, string $person, string $role, float $weight, ?string $start='2025-01-01', ?string $end=null): void {
		$this->world->put('membership', ['id' => $id, 'governanceBody' => 'board', 'person' => $person, 'role' => $role, 'votingWeight' => $weight, 'startDate' => $start, 'endDate' => $end]);
	}//end member()

	/**
	 * The resolver over the world.
	 *
	 * @return WrittenElectorateResolver
	 */
	private function resolver(): WrittenElectorateResolver {
		return new WrittenElectorateResolver(store: new WrittenRoundStore(objectService: $this->world->objectService()));
	}//end resolver()

	/**
	 * Active members except observers and guests, with their user id and weight.
	 *
	 * @return void
	 */
	public function testTheElectorateIsTheActiveMembersExceptObserversAndGuests(): void {
		$snapshot = $this->resolver()->resolve(bodyId: 'board', day: new DateTimeImmutable('2026-03-09T09:00:00Z'));

		$this->assertSame([], $snapshot['unreachable']);
		$this->assertSame(['m-janneke', 'm-jan', 'm-mark'], array_column($snapshot['electorate'], 'membership'));
		$this->assertSame(['janneke', 'jan', 'mark'], array_column($snapshot['electorate'], 'userId'));
		$this->assertSame([1.0, 2.0, 1.0], array_column($snapshot['electorate'], 'votingWeight'));
		$this->assertSame('p-jan', $snapshot['electorate'][1]['person']);
	}//end testTheElectorateIsTheActiveMembersExceptObserversAndGuests()

	/**
	 * A member who joins later is in the snapshot of a later day only.
	 *
	 * @return void
	 */
	public function testTheDayDecidesWhoIsActive(): void {
		$snapshot = $this->resolver()->resolve(bodyId: 'board', day: new DateTimeImmutable('2026-04-02T09:00:00Z'));

		$this->assertSame(['m-janneke', 'm-jan', 'm-mark', 'm-new'], array_column($snapshot['electorate'], 'membership'));
	}//end testTheDayDecidesWhoIsActive()

	/**
	 * A member without a Nextcloud account is named as unreachable, not left out silently.
	 *
	 * @return void
	 */
	public function testAMemberWithoutAnAccountIsNamedUnreachable(): void {
		$this->world->put('person', ['id' => 'p-mark', 'name' => 'Mark van den Berg', 'nextcloudUserId' => '']);

		$snapshot = $this->resolver()->resolve(bodyId: 'board', day: new DateTimeImmutable('2026-03-09T09:00:00Z'));

		$this->assertSame(['Mark van den Berg'], $snapshot['unreachable']);
		$this->assertSame(['m-janneke', 'm-jan'], array_column($snapshot['electorate'], 'membership'));
	}//end testAMemberWithoutAnAccountIsNamedUnreachable()

	/**
	 * The entitlement rule on its own.
	 *
	 * @return void
	 */
	public function testIsEntitled(): void {
		$day = new DateTimeImmutable('2026-03-09');

		$this->assertTrue(WrittenElectorateResolver::isEntitled(membership: ['role' => 'member', 'startDate' => '2026-03-09', 'endDate' => '2026-03-09'], day: $day));
		$this->assertTrue(WrittenElectorateResolver::isEntitled(membership: [], day: $day));
		$this->assertFalse(WrittenElectorateResolver::isEntitled(membership: ['role' => 'guest'], day: $day));
		$this->assertFalse(WrittenElectorateResolver::isEntitled(membership: ['role' => 'member', 'endDate' => '2026-03-08'], day: $day));
		$this->assertFalse(WrittenElectorateResolver::isEntitled(membership: ['role' => 'member', 'startDate' => '2026-03-10'], day: $day));
	}//end testIsEntitled()
}//end class
