<?php

/**
 * Tests: a decision names the body that took it, filled from its meeting.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\DecisionDecidingBodyListener;
use OCA\Decidiq\Listener\Registrar\SaveGuardSubscriptions;
use OCA\Decidiq\Repair\FillDecisionDecidingBody;
use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Decidiq\Listener\DecisionDecidingBodyListener
 * @covers \OCA\Decidiq\Repair\FillDecisionDecidingBody
 *
 * @spec openspec/changes/insight-decisions-report-by-period-and-body/specs/dashboard/spec.md#requirement-req-drp-001-a-decision-names-the-body-that-took-it
 */
class DecisionDecidingBodyListenerTest extends TestCase {

	/**
	 * The meetings the doubles know, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const MEETINGS = [
		'meeting-council-14-oct' => ['id' => 'meeting-council-14-oct', 'title' => 'Raadsvergadering 14 oktober', 'governanceBody' => 'body-council'],
		'meeting-college' => ['id' => 'meeting-college', 'title' => 'Collegevergadering', 'governanceBody' => 'body-college'],
		'meeting-without-body' => ['id' => 'meeting-without-body', 'title' => 'Informatieavond'],
	];

	/**
	 * The decisions saved by the repair step, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * An entity double carrying a record.
	 *
	 * @param array<string, mixed> $row The record.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn($row);
		return $entity;
	}//end entity()

	/**
	 * An object service over the known meetings and the given decisions.
	 *
	 * @param list<array<string, mixed>> $decisions Stored decisions.
	 * @param bool                       $broken    Whether reading a meeting fails.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objects(array $decisions=[], bool $broken=false): ObjectServiceInterface {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (mixed ...$args) use ($broken): ?ObjectEntity {
				if ($broken === true) {
					throw new \RuntimeException('database gone');
				}

				$id = (string)array_values($args)[0];
				return isset(self::MEETINGS[$id]) === true ? $this->entity(self::MEETINGS[$id]) : null;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (mixed ...$args) use ($decisions): array {
				$config = array_values($args)[0];
				$page   = array_slice($decisions, (int)($config['offset'] ?? 0), (int)$config['limit']);
				return array_map(fn (array $row): ObjectEntity => $this->entity($row), $page);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (mixed ...$args): ObjectEntity {
				// The double receives every parameter of the stub's signature: object, extend, register, schema, uuid, ...
				[$object, , $register, $schema, $uuid] = array_values($args);
				self::assertSame(['decidiq', 'decision'], [$register, $schema]);
				$this->saved[(string)$uuid] = $object;
				return $this->entity($object);
			}
		);
		return $objects;
	}//end objects()

	/**
	 * A decision recorded from the council meeting gets the council as its body.
	 *
	 * @return void
	 */
	public function testADecisionTakenInACouncilMeetingNamesTheCouncil(): void {
		$event = new ObjectCreatingEvent($this->entity(['_schemaSlug' => 'decision', 'title' => 'Verordening parkeren binnenstad 2027', 'meeting' => 'meeting-council-14-oct']));

		(new DecisionDecidingBodyListener($this->objects(), new NullLogger()))->handle($event);

		self::assertSame(['decidingBody' => 'body-council'], $event->getModifiedData());
	}//end testADecisionTakenInACouncilMeetingNamesTheCouncil()

	/**
	 * A body set by hand stays when the meeting changes.
	 *
	 * @return void
	 */
	public function testABodySetByHandStaysWhenTheMeetingChanges(): void {
		$old   = $this->entity(['_schemaSlug' => 'decision', 'meeting' => 'meeting-council-14-oct', 'decidingBody' => 'body-committee']);
		$event = new ObjectUpdatingEvent($this->entity(['_schemaSlug' => 'decision', 'meeting' => 'meeting-college', 'decidingBody' => 'body-committee']), $old);

		(new DecisionDecidingBodyListener($this->objects(), new NullLogger()))->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testABodySetByHandStaysWhenTheMeetingChanges()

	/**
	 * An update that gives a decision without a body its meeting fills it.
	 *
	 * @return void
	 */
	public function testAnUpdateGivingAMeetingFillsTheBody(): void {
		$old   = $this->entity(['_schemaSlug' => 'decision', 'title' => 'Besluit']);
		$event = new ObjectUpdatingEvent($this->entity(['_schemaSlug' => 'decision', 'title' => 'Besluit', 'meeting' => ['id' => 'meeting-college']]), $old);

		(new DecisionDecidingBodyListener($this->objects(), new NullLogger()))->handle($event);

		self::assertSame(['decidingBody' => 'body-college'], $event->getModifiedData());
	}//end testAnUpdateGivingAMeetingFillsTheBody()

	/**
	 * No meeting, a meeting without a body, an unreadable meeting or another schema fill nothing.
	 *
	 * @return void
	 */
	public function testNothingIsFilledWithoutAMeetingBody(): void {
		$cases = [
			[['_schemaSlug' => 'decision', 'title' => 'Besluit zonder vergadering'], false],
			[['_schemaSlug' => 'decision', 'meeting' => 'meeting-without-body'], false],
			[['_schemaSlug' => 'decision', 'meeting' => 'meeting-unknown'], false],
			[['_schemaSlug' => 'decision', 'meeting' => 'meeting-council-14-oct'], true],
			[['_schemaSlug' => 'agenda-item', 'meeting' => 'meeting-council-14-oct'], false],
		];
		foreach ($cases as [$row, $broken]) {
			$event = new ObjectCreatingEvent($this->entity($row));
			(new DecisionDecidingBodyListener($this->objects(broken: $broken), new NullLogger()))->handle($event);
			self::assertSame([], $event->getModifiedData(), json_encode($row));
		}
	}//end testNothingIsFilledWithoutAMeetingBody()

	/**
	 * The listener is subscribed before create and before update, for decisions only.
	 *
	 * @return void
	 */
	public function testTheListenerIsSubscribedOnCreateAndUpdate(): void {
		$mine = array_values(
			array_filter(
				SaveGuardSubscriptions::ALL,
				static fn (array $guard): bool => $guard['listener'] === DecisionDecidingBodyListener::class
			)
		);

		self::assertSame([ObjectCreatingEvent::class, ObjectUpdatingEvent::class], array_column($mine, 'event'));
		self::assertSame([['decision'], ['decision']], array_column($mine, 'schemas'));
	}//end testTheListenerIsSubscribedOnCreateAndUpdate()

	/**
	 * The decision schema carries the field as an optional, facetable reference to a body.
	 *
	 * @return void
	 */
	public function testTheDecisionCarriesItsDecidingBody(): void {
		$property = (MergedRegisterSchema::forSlug(slug: 'decision')['properties']['decidingBody'] ?? null);

		self::assertIsArray($property);
		self::assertSame(['governance-body', 'uuid', true, true], [$property['$ref'], $property['format'], $property['facetable'], $property['nullable']]);
	}//end testTheDecisionCarriesItsDecidingBody()

	/**
	 * The repair step fills every decision with a meeting once; a second run changes nothing.
	 *
	 * @return void
	 */
	public function testTheRepairFillsExistingDecisionsOnce(): void {
		$decisions = [
			['id' => 'd-1', 'title' => 'Raadsbesluit', 'meeting' => 'meeting-council-14-oct'],
			['id' => 'd-2', 'title' => 'Collegebesluit', 'meeting' => 'meeting-college', '@self' => ['id' => 'd-2']],
			['id' => 'd-3', 'title' => 'Handmatig', 'meeting' => 'meeting-college', 'decidingBody' => 'body-committee'],
			['id' => 'd-4', 'title' => 'Zonder vergadering'],
			['id' => 'd-5', 'title' => 'Zonder orgaan', 'meeting' => 'meeting-without-body'],
		];
		$output = $this->createMock(IOutput::class);

		(new FillDecisionDecidingBody($this->objects($decisions), new NullLogger()))->run($output);

		self::assertSame(['d-1', 'd-2'], array_keys($this->saved));
		self::assertSame('body-council', $this->saved['d-1']['decidingBody']);
		self::assertSame('Raadsbesluit', $this->saved['d-1']['title']);
		self::assertSame('body-college', $this->saved['d-2']['decidingBody']);
		self::assertArrayNotHasKey('@self', $this->saved['d-2']);

		// The second run sees the filled decisions and writes nothing.
		$filled     = array_map(
			fn (array $row): array => array_merge($row, ($this->saved[$row['id']] ?? [])),
			$decisions
		);
		$this->saved = [];
		(new FillDecisionDecidingBody($this->objects($filled), new NullLogger()))->run($output);
		self::assertSame([], $this->saved);
	}//end testTheRepairFillsExistingDecisionsOnce()
}//end class
