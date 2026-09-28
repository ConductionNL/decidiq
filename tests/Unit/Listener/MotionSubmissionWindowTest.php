<?php

/**
 * Tests for the motion submission window (change motions-submission-window).
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

use OCA\Decidiq\AppInfo\Registrar\ObjectListenerRegistrar;
use OCA\Decidiq\Listener\SubmissionDeadlineListener;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A meeting can open submission at a set time; a motion before it is refused,
 * and a window that opens after it closes cannot be saved.
 *
 * The schema half reads the REAL merged register (base plus every register.d
 * fragment, the way RegisterConfigurationLocator merges them) and validates a
 * window payload against the merged Meeting properties with Opis, the
 * validator OpenRegister uses.
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */
class MotionSubmissionWindowTest extends TestCase {

	/**
	 * Build the listener over an in-memory store of meetings.
	 *
	 * @param array<string, array<string, mixed>> $store Objects by id
	 *
	 * @return SubmissionDeadlineListener
	 */
	private function buildListener(array $store): SubmissionDeadlineListener {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id) use ($store): ?ObjectEntity {
				$payload = ($store[(string)$id] ?? null);
				if ($payload === null) {
					return null;
				}

				return $this->entity(row: $payload);
			}
		);

		return new SubmissionDeadlineListener(logger: new NullLogger(), objectService: $objectService);

	}//end buildListener()

	/**
	 * An OR entity double that serialises to the given row.
	 *
	 * @param array<string, mixed> $row Payload
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
	 * An ISO timestamp relative to now.
	 *
	 * @param string $modifier strtotime modifier
	 *
	 * @return string
	 */
	private function at(string $modifier): string {
		return (new \DateTimeImmutable($modifier))->format(\DateTimeInterface::ATOM);

	}//end at()

	/**
	 * A member who submits before the window opens is refused, and the
	 * message names the opening time.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md#requirement-req-subw-002-a-motion-or-amendment-submitted-before-the-window-opens-is-refused
	 *
	 * @return void
	 */
	public function testMotionBeforeTheWindowOpensIsRefused(): void {
		$opens = new \DateTimeImmutable('+2 days');
		$listener = $this->buildListener(
			[
				'meeting-1' => [
					'id'                 => 'meeting-1',
					'submissionOpensAt'  => $opens->format(\DateTimeInterface::ATOM),
					'submissionDeadline' => $this->at('+5 days'),
				],
			]
		);

		$event = new ObjectCreatingEvent($this->entity(row: ['_schemaSlug' => 'decision', 'decisionType' => 'motion', 'meeting' => 'meeting-1']));
		$listener->handle($event);

		self::assertTrue($event->isPropagationStopped());
		$message = (string)$event->getErrors()['message'];
		self::assertStringStartsWith('Submission of motions and amendments for this meeting opens on ', $message);
		self::assertStringContainsString($opens->format('j F Y H:i'), $message);
		self::assertSame($opens->format(DATE_ATOM), $event->getErrors()['submissionOpensAt']);

	}//end testMotionBeforeTheWindowOpensIsRefused()

	/**
	 * An amendment before the window opens is refused too, through its motion.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md#requirement-req-subw-002-a-motion-or-amendment-submitted-before-the-window-opens-is-refused
	 *
	 * @return void
	 */
	public function testAmendmentBeforeTheWindowOpensIsRefused(): void {
		$listener = $this->buildListener(
			[
				'meeting-1' => ['id' => 'meeting-1', 'submissionOpensAt' => $this->at('+1 day')],
				'motion-1'  => ['id' => 'motion-1', 'decisionType' => 'motion', 'meeting' => 'meeting-1'],
			]
		);

		$event = new ObjectCreatingEvent($this->entity(row: ['_schemaSlug' => 'decision', 'decisionType' => 'amendment', 'amends' => 'motion-1']));
		$listener->handle($event);

		self::assertTrue($event->isPropagationStopped());

	}//end testAmendmentBeforeTheWindowOpensIsRefused()

	/**
	 * Inside the window a motion is created.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md#requirement-req-subw-002-a-motion-or-amendment-submitted-before-the-window-opens-is-refused
	 *
	 * @return void
	 */
	public function testMotionInsideTheWindowIsAllowed(): void {
		$listener = $this->buildListener(
			[
				'meeting-1' => [
					'id'                 => 'meeting-1',
					'submissionOpensAt'  => $this->at('-1 day'),
					'submissionDeadline' => $this->at('+1 day'),
				],
			]
		);

		$event = new ObjectCreatingEvent($this->entity(row: ['_schemaSlug' => 'decision', 'decisionType' => 'motion', 'meeting' => 'meeting-1']));
		$listener->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getErrors());

	}//end testMotionInsideTheWindowIsAllowed()

	/**
	 * A window that opens after it closes is refused when a meeting is created.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md#requirement-req-subw-003-a-window-that-opens-after-it-closes-is-refused
	 *
	 * @return void
	 */
	public function testInvertedWindowIsRefusedOnCreate(): void {
		$listener = $this->buildListener([]);
		$event = new ObjectCreatingEvent(
			$this->entity(
				row: [
					'_schemaSlug'        => 'meeting',
					'submissionOpensAt'  => '2026-10-14T09:00:00+00:00',
					'submissionDeadline' => '2026-10-13T12:00:00+00:00',
				]
			)
		);
		$listener->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(SubmissionDeadlineListener::INVERTED_WINDOW_MESSAGE, $event->getErrors()['message']);
		self::assertSame('The submission window opens after it closes.', SubmissionDeadlineListener::INVERTED_WINDOW_MESSAGE);

	}//end testInvertedWindowIsRefusedOnCreate()

	/**
	 * The same check runs when a meeting is updated, reading the NEW object.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md#requirement-req-subw-003-a-window-that-opens-after-it-closes-is-refused
	 *
	 * @return void
	 */
	public function testInvertedWindowIsRefusedOnUpdate(): void {
		$listener = $this->buildListener([]);
		$event = new ObjectUpdatingEvent(
			$this->entity(
				row: [
					'_schemaSlug'        => 'meeting',
					'submissionOpensAt'  => '2026-10-13T12:00:00+00:00',
					'submissionDeadline' => '2026-10-13T12:00:00+00:00',
				]
			),
			$this->entity(row: ['_schemaSlug' => 'meeting'])
		);
		$listener->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(SubmissionDeadlineListener::INVERTED_WINDOW_MESSAGE, $event->getErrors()['message']);

	}//end testInvertedWindowIsRefusedOnUpdate()

	/**
	 * A sane window, or a meeting with only one of the two times, saves.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md#requirement-req-subw-003-a-window-that-opens-after-it-closes-is-refused
	 *
	 * @return void
	 */
	public function testSaneOrHalfWindowSaves(): void {
		$listener = $this->buildListener([]);
		foreach ([
			['submissionOpensAt' => '2026-10-04T09:00:00+00:00', 'submissionDeadline' => '2026-10-13T12:00:00+00:00'],
			['submissionOpensAt' => '2026-10-04T09:00:00+00:00'],
			['submissionDeadline' => '2026-10-13T12:00:00+00:00'],
		] as $row) {
			$event = new ObjectUpdatingEvent($this->entity(row: (['_schemaSlug' => 'meeting'] + $row)));
			$listener->handle($event);
			self::assertFalse($event->isPropagationStopped(), json_encode($row));
		}

	}//end testSaneOrHalfWindowSaves()

	/**
	 * The merged register declares submissionOpensAt on Meeting, and the
	 * window a griffier sets validates against the merged schema with Opis.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md#requirement-req-subw-001-a-meeting-can-open-submission-at-a-set-time
	 *
	 * @return void
	 */
	public function testMergedMeetingSchemaCarriesTheOpeningAndTheSeedValidates(): void {
		$settings = __DIR__ . '/../../../lib/Settings/';
		$meeting = [];
		$files = array_merge([$settings . 'decidesk_register.json'], (glob($settings . 'register.d/*.json') ?: []));
		foreach ($files as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			$fragment = ($doc['components']['schemas']['Meeting'] ?? null);
			if (is_array($fragment) === true) {
				$meeting = array_replace_recursive($meeting, $fragment);
			}
		}

		$opens = ($meeting['properties']['submissionOpensAt'] ?? null);
		self::assertIsArray($opens, 'Meeting.submissionOpensAt must be declared');
		self::assertSame('string', $opens['type']);
		self::assertSame('date-time', $opens['format']);

		// The window a griffier sets in the Planning widget, exactly as the
		// meeting page writes it (ISO 8601 date-time strings).
		$seed = [
			'submissionOpensAt'  => '2026-10-04T09:00:00+02:00',
			'submissionDeadline' => '2026-10-13T12:00:00+02:00',
		];

		// Validate only the two window fields against their merged definitions:
		// the full Meeting schema carries OpenRegister extensions and relation
		// shapes Opis does not resolve on its own.
		$schema = json_decode(
			(string)json_encode(
				[
					'type'       => 'object',
					'properties' => [
						'submissionOpensAt'  => $opens,
						'submissionDeadline' => $meeting['properties']['submissionDeadline'],
					],
				]
			)
		);
		$payload = json_decode(
			(string)json_encode(
				[
					'submissionOpensAt'  => $seed['submissionOpensAt'],
					'submissionDeadline' => $seed['submissionDeadline'],
				]
			)
		);
		$validator = new Validator();
		$validator->parser()->setOption('allowFormats', true);
		$result = $validator->validate($payload, $schema);
		self::assertTrue($result->isValid(), 'The seeded window must validate');

		$bad = json_decode('{"submissionOpensAt":"next tuesday"}');
		self::assertFalse($validator->validate($bad, $schema)->isValid(), 'The validator must reject a non date-time, or it proves nothing');

	}//end testMergedMeetingSchemaCarriesTheOpeningAndTheSeedValidates()

	/**
	 * The listener is actually subscribed to meeting creates and updates, not
	 * only to decision creates: a guard with tests and no subscription never runs.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md#requirement-req-subw-003-a-window-that-opens-after-it-closes-is-refused
	 *
	 * @return void
	 */
	public function testRegistrarSubscribesTheListenerToMeetingSaves(): void {
		$subscribed = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('addServiceListener')->willReturnCallback(
			function (string $event, string $listener) use (&$subscribed): void {
				$subscribed[] = [$event, $listener];
			}
		);

		(new ObjectListenerRegistrar(logger: new NullLogger()))->register($dispatcher);

		self::assertContains([ObjectCreatingEvent::class, SubmissionDeadlineListener::class], $subscribed);
		self::assertContains([ObjectUpdatingEvent::class, SubmissionDeadlineListener::class], $subscribed);

	}//end testRegistrarSubscribesTheListenerToMeetingSaves()
}//end class
