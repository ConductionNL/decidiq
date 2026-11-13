<?php

/**
 * Tests for how the submission deadline listener finds the schema and the
 * meeting of an object being saved.
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

use OCA\Decidiq\Listener\SubmissionDeadlineListener;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The schema comes from the row, else the entity's getters; the meeting from
 * a string, an object reference or a relation, and for an amendment through
 * its parent motion. Whatever cannot be resolved lets the save through.
 *
 * @covers \OCA\Decidiq\Listener\SubmissionDeadlineListener
 * @uses   \OCA\Decidiq\Listener\SubmissionWindow
 * @uses   \OCA\Decidiq\Listener\EntityPayloadReader
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */
class SubmissionDeadlineListenerResolutionTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The ids the listener looked up, in order.
	 *
	 * @var array<int, string>
	 */
	private array $lookedUp = [];

	/**
	 * Set up an object service that answers from the given payloads.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->lookedUp = [];
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
	}//end setUp()

	/**
	 * The listener over an object store holding the given payloads.
	 *
	 * @param array<string, array<string, mixed>> $store Payloads by id
	 *
	 * @return SubmissionDeadlineListener
	 */
	private function listener(array $store = []): SubmissionDeadlineListener {
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id) use ($store): ?ObjectEntity {
				$this->lookedUp[] = (string)$id;
				if (isset($store[(string)$id]) === false) {
					return null;
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($store[(string)$id]);
				return $entity;
			}
		);

		return new SubmissionDeadlineListener(logger: new NullLogger(), objectService: $this->objectService);
	}//end listener()

	/**
	 * A creating event over an entity whose payload is the given row.
	 *
	 * @param array<string, mixed> $row The payload
	 * @param string|null $schema What the entity's getSchema() answers
	 *
	 * @return ObjectCreatingEvent
	 */
	private function creating(array $row, ?string $schema = null): ObjectCreatingEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('getSchema')->willReturn($schema);
		return new ObjectCreatingEvent($entity);
	}//end creating()

	/**
	 * A meeting whose deadline has passed.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function lateMeeting(): array {
		return ['meeting-1' => ['submissionDeadline' => '2020-01-01T00:00:00+00:00']];
	}//end lateMeeting()

	/**
	 * Assert the event was refused because the deadline passed.
	 *
	 * @param ObjectCreatingEvent $event The event
	 *
	 * @return void
	 */
	private function assertRefusedAsLate(ObjectCreatingEvent $event): void {
		self::assertTrue($event->isPropagationStopped());
		self::assertSame(SubmissionDeadlineListener::REJECTION_MESSAGE, $event->getErrors()['message']);
	}//end assertRefusedAsLate()

	/**
	 * An event without an object is left alone.
	 *
	 * @return void
	 */
	public function testAnEventWithoutAnObjectIsIgnored(): void {
		$listener = $this->listener();
		$this->objectService->expects($this->never())->method('find');
		$event = new ObjectCreatingEvent(null);

		$listener->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnEventWithoutAnObjectIsIgnored()

	/**
	 * Without a schema in the row, the entity's getSchema() names it.
	 *
	 * @return void
	 */
	public function testTheSchemaGetterNamesTheSchemaWhenTheRowDoesNot(): void {
		$event = $this->creating(['decisionType' => 'motion', 'meeting' => 'meeting-1'], schema: 'Decision');

		$this->listener($this->lateMeeting())->handle($event);

		$this->assertRefusedAsLate($event);
		self::assertSame(['meeting-1'], $this->lookedUp);
	}//end testTheSchemaGetterNamesTheSchemaWhenTheRowDoesNot()

	/**
	 * A schema slug getter is read before a schema getter.
	 *
	 * @return void
	 */
	public function testTheSchemaSlugGetterIsReadFirst(): void {
		$entity = new class extends ObjectEntity {
			/**
			 * @return array<string, string>
			 */
			public function getObject(): array {
				return ['submissionOpensAt' => '2026-05-02T00:00:00+00:00', 'submissionDeadline' => '2026-05-01T00:00:00+00:00'];
			}

			/**
			 * @return string
			 */
			public function getSchemaSlug(): string {
				return 'MEETING';
			}

			/**
			 * @return string
			 */
			public function getSchema(): string {
				return 'decision';
			}
		};
		$event = new ObjectCreatingEvent($entity);

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['message' => SubmissionDeadlineListener::INVERTED_WINDOW_MESSAGE], $event->getErrors());
	}//end testTheSchemaSlugGetterIsReadFirst()

	/**
	 * An object that names no schema anywhere is left alone.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutAnySchemaIsIgnored(): void {
		$event = $this->creating(['decisionType' => 'motion', 'meeting' => 'meeting-1'], schema: '');

		$this->listener($this->lateMeeting())->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $this->lookedUp);
	}//end testAnObjectWithoutAnySchemaIsIgnored()

	/**
	 * A motion that names no meeting has no window and is allowed.
	 *
	 * @return void
	 */
	public function testAMotionWithoutAMeetingIsAllowed(): void {
		$event = $this->creating(
			['_schemaSlug' => 'decision', 'decisionType' => 'motion', 'meeting' => ['id' => ''], 'relations' => [['schema' => 'agenda', 'id' => 'a-1'], 'not-a-relation']]
		);

		$this->listener($this->lateMeeting())->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $this->lookedUp);
	}//end testAMotionWithoutAMeetingIsAllowed()

	/**
	 * A meeting reference given as an object is read by id, else by uuid.
	 *
	 * @return void
	 */
	public function testAMeetingObjectReferenceIsReadByIdThenUuid(): void {
		foreach ([['id' => 'meeting-1'], ['uuid' => 'meeting-1']] as $reference) {
			$this->setUp();
			$event = $this->creating(['_schemaSlug' => 'decision', 'decisionType' => 'motion', 'meeting' => $reference]);

			$this->listener($this->lateMeeting())->handle($event);

			$this->assertRefusedAsLate($event);
			self::assertSame(['meeting-1'], $this->lookedUp);
		}
	}//end testAMeetingObjectReferenceIsReadByIdThenUuid()

	/**
	 * Without a meeting property, the first meeting relation names it.
	 *
	 * @return void
	 */
	public function testAMeetingRelationNamesTheMeeting(): void {
		$event = $this->creating(
			[
				'_schemaSlug' => 'decision',
				'decisionType' => 'motion',
				'relations' => [['schema' => 'meeting', 'id' => ''], ['schema' => 'meeting', 'uuid' => 'meeting-1']],
			]
		);

		$this->listener($this->lateMeeting())->handle($event);

		$this->assertRefusedAsLate($event);
		self::assertSame(['meeting-1'], $this->lookedUp);
	}//end testAMeetingRelationNamesTheMeeting()

	/**
	 * A motion of a meeting that cannot be found has no window and is allowed.
	 *
	 * @return void
	 */
	public function testAMotionOfAnUnknownMeetingIsAllowed(): void {
		$event = $this->creating(['_schemaSlug' => 'decision', 'decisionType' => 'motion', 'meeting' => 'meeting-gone']);

		$this->listener($this->lateMeeting())->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame(['meeting-gone'], $this->lookedUp);
	}//end testAMotionOfAnUnknownMeetingIsAllowed()

	/**
	 * An amendment that names no parent motion is allowed without a lookup.
	 *
	 * @return void
	 */
	public function testAnAmendmentWithoutAParentMotionIsAllowed(): void {
		$event = $this->creating(['_schemaSlug' => 'decision', 'decisionType' => 'amendment', 'meeting' => 'meeting-1']);

		$this->listener($this->lateMeeting())->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $this->lookedUp);
	}//end testAnAmendmentWithoutAParentMotionIsAllowed()

	/**
	 * An amendment whose parent motion cannot be found is allowed.
	 *
	 * @return void
	 */
	public function testAnAmendmentOfAMissingMotionIsAllowed(): void {
		$event = $this->creating(['_schemaSlug' => 'decision', 'decisionType' => 'amendment', 'amends' => 'motion-gone']);

		$this->listener($this->lateMeeting())->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame(['motion-gone'], $this->lookedUp);
	}//end testAnAmendmentOfAMissingMotionIsAllowed()

	/**
	 * An amendment reaches its meeting through a decision relation to its motion.
	 *
	 * @return void
	 */
	public function testAnAmendmentFindsItsMotionThroughADecisionRelation(): void {
		$store = $this->lateMeeting() + ['motion-1' => ['meeting' => ['id' => 'meeting-1']]];
		$event = $this->creating(
			['_schemaSlug' => 'decision', 'decisionType' => 'amendment', 'relations' => [['schema' => 'decision', 'id' => 'motion-1']]]
		);

		$this->listener($store)->handle($event);

		$this->assertRefusedAsLate($event);
		self::assertSame(['motion-1', 'meeting-1'], $this->lookedUp);
	}//end testAnAmendmentFindsItsMotionThroughADecisionRelation()
}//end class
