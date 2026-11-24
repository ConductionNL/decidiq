<?php

/**
 * Committee Agenda Placement Test
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
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTime;
use OCA\Decidiq\Service\CommitteeAgendaPlacement;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * A committee stage goes on its body's agenda and returns with the body's decision.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
 */
class CommitteeAgendaPlacementTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var RegisterObjectStore
	 */
	private RegisterObjectStore $store;

	/**
	 * Set up an empty register and the subject with its route.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new class($this->createMock(originalClassName: ObjectServiceInterface::class)) extends RegisterObjectStore {

			/**
			 * Objects per schema, by uuid.
			 *
			 * @var array<string, array<string, array<string, mixed>>>
			 */
			public array $rows = [];

			/**
			 * Counter for new uuids.
			 *
			 * @var integer
			 */
			private int $next = 0;

			/**
			 * Save an object.
			 *
			 * @param string               $schema The schema.
			 * @param array<string, mixed> $object The object.
			 * @param string|null          $uuid   The uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function save(string $schema, array $object, ?string $uuid = null): array {
				$this->next++;
				$uuid = ($uuid ?? ($schema . '-new-' . $this->next));
				$this->rows[$schema][$uuid] = (['id' => $uuid] + $object);
				return $this->rows[$schema][$uuid];
			}

			/**
			 * Patch an object.
			 *
			 * @param string               $schema The schema.
			 * @param array<string, mixed> $data   The fields.
			 * @param string               $uuid   The uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function patch(string $schema, array $data, string $uuid): array {
				$this->rows[$schema][$uuid] = array_merge($this->rows[$schema][$uuid], $data);
				return $this->rows[$schema][$uuid];
			}

			/**
			 * Find one object.
			 *
			 * @param string $schema The schema.
			 * @param string $uuid   The uuid.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $schema, string $uuid): ?array {
				return ($this->rows[$schema][$uuid] ?? null);
			}

			/**
			 * Find the objects whose fields equal every filter.
			 *
			 * @param string               $schema  The schema.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(string $schema, array $filters): array {
				return array_values(
					array_filter(
						($this->rows[$schema] ?? []),
						static fn (array $row): bool => array_intersect_key($row, $filters) == $filters
					)
				);
			}
		};

		$this->seed(schema: 'decision', id: 'dec-1', row: ['title' => 'Subsidie buurthuis']);
		$this->seed(schema: 'decision-stage', id: 'st-1', row: ['decision' => 'dec-1', 'sequence' => 1, 'status' => 'decided', 'stepKind' => 'approval']);
		$this->seed(schema: 'decision-stage', id: 'st-2', row: [
			'decision' => 'dec-1', 'sequence' => 2, 'status' => 'active', 'stepKind' => 'committee',
			'committeeBody' => 'college', 'label' => 'Collegebesluit',
		]);
		$this->seed(schema: 'decision-stage', id: 'st-3', row: ['decision' => 'dec-1', 'sequence' => 3, 'status' => 'pending', 'stepKind' => 'approval']);

	}//end setUp()

	/**
	 * Put a row in the register.
	 *
	 * @param string               $schema The schema.
	 * @param string               $id     The uuid.
	 * @param array<string, mixed> $row    The fields.
	 *
	 * @return void
	 */
	private function seed(string $schema, string $id, array $row): void {
		$this->store->save(schema: $schema, object: $row, uuid: $id);

	}//end seed()

	/**
	 * The service at a fixed "now": 1 March 2026.
	 *
	 * @return CommitteeAgendaPlacement
	 */
	private function placement(): CommitteeAgendaPlacement {
		$clock = $this->createMock(originalClassName: ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new DateTime('2026-03-01T09:00:00+00:00'));

		return new CommitteeAgendaPlacement(store: $this->store, timeFactory: $clock);

	}//end placement()

	/**
	 * A regular meeting of a body.
	 *
	 * @param string $id        The uuid.
	 * @param string $body      The governance body.
	 * @param string $date      The scheduled date.
	 * @param string $lifecycle The lifecycle.
	 * @param string $type      The meeting type.
	 *
	 * @return array<string, mixed>
	 */
	private function meeting(string $id, string $body, string $date, string $lifecycle = 'scheduled', string $type = 'regular'): array {
		$row = ['governanceBody' => $body, 'scheduledDate' => $date, 'lifecycle' => $lifecycle, 'meetingType' => $type];
		$this->seed(schema: 'meeting', id: $id, row: $row);

		return (['id' => $id] + $row);

	}//end meeting()

	/**
	 * The active committee stage goes, last, on the body's next regular open meeting.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
	 *
	 * @return void
	 */
	public function testAnActiveCommitteeStageGoesOnTheBodysNextRegularMeeting(): void {
		$this->meeting(id: 'm-past', body: 'college', date: '2026-02-01T10:00:00+00:00');
		$this->meeting(id: 'm-later', body: 'college', date: '2026-04-01T10:00:00+00:00');
		$this->meeting(id: 'm-next', body: 'college', date: '2026-03-10T10:00:00+00:00');
		$this->meeting(id: 'm-cancelled', body: 'college', date: '2026-03-05T10:00:00+00:00', lifecycle: 'cancelled');
		$this->meeting(id: 'm-extra', body: 'college', date: '2026-03-03T10:00:00+00:00', type: 'extraordinary');
		$this->meeting(id: 'm-raad', body: 'raad', date: '2026-03-02T10:00:00+00:00');
		$this->seed(schema: 'agenda-item', id: 'ai-0', row: ['meeting' => 'm-next', 'orderNumber' => 3]);

		$item = $this->placement()->placeStage(stage: $this->store->find(schema: 'decision-stage', uuid: 'st-2'));

		$this->assertNotNull(actual: $item);
		$this->assertSame(expected: 'm-next', actual: $item['meeting']);
		$this->assertSame(expected: 'Subsidie buurthuis', actual: $item['title']);
		$this->assertSame(expected: 4, actual: $item['orderNumber']);
		$stage = $this->store->find(schema: 'decision-stage', uuid: 'st-2');
		$this->assertSame(expected: $item['id'], actual: $stage['agendaItem']);
		$this->assertFalse(condition: $stage['placementPending']);

		// Placed once: the stage is on an agenda now, so a second save does nothing.
		$this->assertNull(actual: $this->placement()->placeStage(stage: $stage));
		$this->assertCount(expectedCount: 2, haystack: $this->store->findAll(schema: 'agenda-item', filters: []));

	}//end testAnActiveCommitteeStageGoesOnTheBodysNextRegularMeeting()

	/**
	 * Without an open meeting the stage waits, and the body's next created meeting takes it.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
	 *
	 * @return void
	 */
	public function testWithoutAMeetingTheStageWaitsForTheBodysNextMeeting(): void {
		$placement = $this->placement();
		$this->assertNull(actual: $placement->placeStage(stage: $this->store->find(schema: 'decision-stage', uuid: 'st-2')));
		$this->assertTrue(condition: $this->store->find(schema: 'decision-stage', uuid: 'st-2')['placementPending']);
		$this->assertSame(expected: [], actual: $this->store->findAll(schema: 'agenda-item', filters: []));

		// Another body's meeting and a past meeting of this body take nothing.
		$this->assertSame(expected: 0, actual: $placement->placePending(meeting: $this->meeting(id: 'm-raad', body: 'raad', date: '2026-03-10T10:00:00+00:00')));
		$this->assertSame(expected: 0, actual: $placement->placePending(meeting: $this->meeting(id: 'm-old', body: 'college', date: '2026-02-10T10:00:00+00:00')));

		$this->assertSame(expected: 1, actual: $placement->placePending(meeting: $this->meeting(id: 'm-new', body: 'college', date: '2026-03-20T10:00:00+00:00')));
		$stage = $this->store->find(schema: 'decision-stage', uuid: 'st-2');
		$this->assertFalse(condition: $stage['placementPending']);
		$this->assertSame(expected: 'm-new', actual: $this->store->find(schema: 'agenda-item', uuid: $stage['agendaItem'])['meeting']);

	}//end testWithoutAMeetingTheStageWaitsForTheBodysNextMeeting()

	/**
	 * The body's adopted decision on the item closes the stage with outcome, meeting and date,
	 * and the route walks on to the next step.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
	 *
	 * @return void
	 */
	public function testTheBodysDecisionClosesTheStageWithMeetingAndDate(): void {
		$this->store->patch(schema: 'decision-stage', data: ['agendaItem' => 'ai-9'], uuid: 'st-2');
		$this->seed(schema: 'agenda-item', id: 'ai-9', row: ['meeting' => 'm-next']);

		$closed = $this->placement()->closeFromDecision(decision: [
			'id' => 'dec-college', 'agendaItem' => 'ai-9', 'outcome' => 'adopted',
			'meeting' => 'm-next', 'decisionDate' => '2026-03-10T11:30:00+00:00',
		]);

		$this->assertNotNull(actual: $closed);
		$this->assertSame(expected: 'decided', actual: $closed['status']);
		$this->assertSame(expected: 'adopted', actual: $closed['outcome']);
		$this->assertSame(expected: 'm-next', actual: $closed['decidedInMeeting']);
		$this->assertSame(expected: '2026-03-10T11:30:00+00:00', actual: $closed['decidedAt']);
		$this->assertSame(expected: 'active', actual: $this->store->find(schema: 'decision-stage', uuid: 'st-3')['status']);

	}//end testTheBodysDecisionClosesTheStageWithMeetingAndDate()

	/**
	 * A rejected decision ends the route at the committee step; the meeting comes from the item.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
	 *
	 * @return void
	 */
	public function testARejectedDecisionEndsTheRouteAtTheCommitteeStep(): void {
		$this->store->patch(schema: 'decision-stage', data: ['agendaItem' => 'ai-9'], uuid: 'st-2');
		$this->seed(schema: 'agenda-item', id: 'ai-9', row: ['meeting' => 'm-next']);

		$closed = $this->placement()->closeFromDecision(decision: ['agendaItem' => ['id' => 'ai-9'], 'outcome' => 'rejected']);

		$this->assertSame(expected: 'rejected', actual: $closed['outcome']);
		$this->assertSame(expected: 'm-next', actual: $closed['decidedInMeeting']);
		$this->assertSame(expected: '2026-03-01T09:00:00+00:00', actual: $closed['decidedAt']);
		$this->assertSame(expected: 'pending', actual: $this->store->find(schema: 'decision-stage', uuid: 'st-3')['status']);

	}//end testARejectedDecisionEndsTheRouteAtTheCommitteeStep()

	/**
	 * Other stages and other decisions are left alone.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
	 *
	 * @return void
	 */
	public function testOtherStagesAndDecisionsAreLeftAlone(): void {
		$this->meeting(id: 'm-next', body: 'college', date: '2026-03-10T10:00:00+00:00');
		$placement = $this->placement();

		$this->assertNull(actual: $placement->placeStage(stage: $this->store->find(schema: 'decision-stage', uuid: 'st-3')));
		$this->assertNull(actual: $placement->placeStage(stage: ['id' => 'x', 'stepKind' => 'committee', 'status' => 'active']));
		$this->assertFalse(condition: CommitteeAgendaPlacement::awaitsPlacement(stage: ['stepKind' => 'committee', 'status' => 'pending', 'committeeBody' => 'college']));

		$this->store->patch(schema: 'decision-stage', data: ['agendaItem' => 'ai-9'], uuid: 'st-2');
		$this->assertNull(actual: $placement->closeFromDecision(decision: ['agendaItem' => 'ai-9']));
		$this->assertNull(actual: $placement->closeFromDecision(decision: ['agendaItem' => 'ai-other', 'outcome' => 'adopted']));
		$this->assertNull(actual: $placement->closeFromDecision(decision: ['outcome' => 'adopted']));
		$this->assertSame(expected: 'active', actual: $this->store->find(schema: 'decision-stage', uuid: 'st-2')['status']);
		$this->assertSame(expected: [], actual: $this->store->findAll(schema: 'agenda-item', filters: []));

	}//end testOtherStagesAndDecisionsAreLeftAlone()
}//end class
