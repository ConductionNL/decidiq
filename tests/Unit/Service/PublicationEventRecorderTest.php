<?php

/**
 * Publication events are recorded from the agenda, the publication and the paper listener
 * (publication-subscriptions-and-daily-digest, REQ-PSD-002).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\PublicationEventRecorder;
use OCA\Decidiq\Service\SettingsService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Each source records one event with its body, meeting and a one-line summary, and the stored
 * payload validates against the merged publication-event schema.
 *
 * @covers \OCA\Decidiq\Service\PublicationEventRecorder
 * @uses   \OCA\Decidiq\Service\SettingsService
 *
 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-002-agendas-papers-decisions-and-minutes-are-recorded-as-events
 */
final class PublicationEventRecorderTest extends TestCase {

	private const MEETING = '00000000-0000-4000-8000-000000000014';

	private const BODY = '00000000-0000-4000-8000-0000000000aa';

	private const ITEM = '00000000-0000-4000-8000-000000000044';

	/**
	 * Saved events.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * A recorder over a fake object service holding one meeting and one agenda item.
	 *
	 * @param array<string,mixed> $meeting The meeting
	 *
	 * @return PublicationEventRecorder
	 */
	private function recorder(array $meeting): PublicationEventRecorder {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (string $id) use ($meeting): ?ObjectEntity {
				$rows = [
					self::MEETING => $meeting,
					self::ITEM    => ['title' => 'Motie vreemd aan de orde', 'meeting' => self::MEETING],
				];
				if (isset($rows[$id]) === false) {
					return null;
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('getObject')->willReturn($rows[$id]);
				return $entity;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null, bool $_rbac=true): ObjectEntity {
				$this->assertSame('publication-event', $schema);
				$this->assertFalse($_rbac, 'Events are written in system context');
				$this->saved[] = $object;
				return $this->createMock(ObjectEntity::class);
			}
		);

		return new PublicationEventRecorder(objectService: $objects, logger: new NullLogger());
	}//end recorder()

	/**
	 * The council meeting of 14 October, agenda published.
	 *
	 * @return array<string,mixed>
	 */
	private function meeting(): array {
		return ['title' => 'Raadsvergadering 14 oktober', 'governanceBody' => self::BODY, 'agendaPublishedAt' => '2026-10-01T09:00:00+02:00'];
	}//end meeting()

	/**
	 * The griffier adds item 4 to a published agenda: one agenda event "Agenda changed: item 4 added".
	 *
	 * @return void
	 */
	public function testAChangedAgenda(): void {
		$before = [['id' => 'a', 'title' => 'Opening', 'orderNumber' => 1]];
		$after  = [...$before, ['id' => 'b', 'title' => 'Motie vreemd aan de orde', 'orderNumber' => 4]];

		$this->recorder(meeting: $this->meeting())->agendaChanged(meetingId: self::MEETING, meeting: $this->meeting(), versions: [['version' => 1, 'items' => $before], ['version' => 2, 'items' => $after]]);

		$this->assertCount(1, $this->saved);
		$this->assertSame('agenda', $this->saved[0]['kind']);
		$this->assertSame(self::MEETING, $this->saved[0]['meeting']);
		$this->assertSame(self::BODY, $this->saved[0]['governanceBody']);
		$this->assertSame('Agenda changed: item 4 added', $this->saved[0]['summary']);
		$this->assertFalse($this->saved[0]['isPublished'], 'A member-only agenda change is not news for residents');
		$this->assertValidEvent(event: $this->saved[0]);
	}//end testAChangedAgenda()

	/**
	 * Withdrawn and edited items are named too.
	 *
	 * @return void
	 */
	public function testWithdrawnAndEditedItems(): void {
		$before = [['id' => 'a', 'title' => 'Opening', 'orderNumber' => 1], ['id' => 'c', 'title' => 'Rondvraag', 'orderNumber' => null]];

		$this->assertSame('Agenda changed: item Rondvraag withdrawn', PublicationEventRecorder::changeSummary(before: $before, after: [$before[0]]));
		$this->assertSame('Agenda changed: items edited or moved', PublicationEventRecorder::changeSummary(before: $before, after: $before));
	}//end testWithdrawnAndEditedItems()

	/**
	 * A decision published to the public is an event residents may hear of.
	 *
	 * @return void
	 */
	public function testAPublishedDecision(): void {
		$this->recorder(meeting: $this->meeting())->published(
			sourceType: 'decision',
			sourceId: '00000000-0000-4000-8000-0000000000d1',
			source: ['title' => 'Vaststelling Programmabegroting 2026', 'meeting' => self::MEETING],
			bodyId: self::BODY
		);

		$this->assertCount(1, $this->saved);
		$this->assertSame('decision', $this->saved[0]['kind']);
		$this->assertSame('decision', $this->saved[0]['objectType']);
		$this->assertTrue($this->saved[0]['isPublished']);
		$this->assertValidEvent(event: $this->saved[0]);
	}//end testAPublishedDecision()

	/**
	 * A paper added to an item of a published agenda records one paper event on that item.
	 *
	 * @return void
	 */
	public function testAPaperOnAPublishedAgenda(): void {
		$this->recorder(meeting: $this->meeting())->paperAdded(schema: 'agenda-item', objectId: self::ITEM, fileName: 'Motie.pdf');

		$this->assertCount(1, $this->saved);
		$this->assertSame('paper', $this->saved[0]['kind']);
		$this->assertSame('agenda-item', $this->saved[0]['objectType']);
		$this->assertSame(self::MEETING, $this->saved[0]['meeting']);
		$this->assertSame('Paper added: Motie.pdf', $this->saved[0]['summary']);
		$this->assertValidEvent(event: $this->saved[0]);
	}//end testAPaperOnAPublishedAgenda()

	/**
	 * Before the agenda is published a paper is no news.
	 *
	 * @return void
	 */
	public function testAPaperBeforePublicationIsSilent(): void {
		$meeting = $this->meeting();
		unset($meeting['agendaPublishedAt']);

		$this->recorder(meeting: $meeting)->paperAdded(schema: 'meeting', objectId: self::MEETING, fileName: 'Concept.pdf');

		$this->assertSame([], $this->saved);
	}//end testAPaperBeforePublicationIsSilent()

	/**
	 * Validate a stored event with Opis against the merged publication-event schema.
	 *
	 * @param array<string,mixed> $event The saved object
	 *
	 * @return void
	 */
	private function assertValidEvent(array $event): void {
		foreach (SettingsService::shippedRegisterDescriptor()['components']['schemas'] as $schema) {
			if (($schema['slug'] ?? '') !== 'publication-event') {
				continue;
			}

			$properties = array_map(
				static function (array $property): array {
					unset($property['$ref']);
					return $property;
				},
				$schema['properties']
			);
			$result     = (new Validator())->validate(
				json_decode((string)json_encode($event)),
				json_decode((string)json_encode(['type' => 'object', 'properties' => $properties, 'required' => $schema['required'], 'additionalProperties' => false]))
			);
			$this->assertTrue($result->isValid(), 'The event must validate: ' . json_encode($result->error()?->args()));
			return;
		}

		$this->fail('The register has no publication-event schema.');
	}//end assertValidEvent()
}//end class
