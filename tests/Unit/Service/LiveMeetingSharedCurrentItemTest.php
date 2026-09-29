<?php

/**
 * The shared live meeting (live-meeting-shared-current-item): the meeting
 * holds the current agenda item, and a decision recorded live names its
 * meeting, its item and its type, in a shape the Decision schema accepts.
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
 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-003-a-decision-is-recorded-when-it-is-taken
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\CurrentAgendaItemService;
use OCA\Decidiq\Service\EngagementService;
use OCA\Decidiq\Service\LiveDecisionService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Decidiq\Service\LiveDecisionService
 */
class LiveMeetingSharedCurrentItemTest extends TestCase {

	/**
	 * The merged schema for a slug (base register plus every register.d fragment).
	 *
	 * @param string $slug The schema slug
	 *
	 * @return array<string, mixed>
	 */
	private function mergedSchema(string $slug): array {
		$settings = __DIR__ . '/../../../lib/Settings/';
		$files = array_merge([$settings . 'decidesk_register.json'], (glob($settings . 'register.d/*.json') ?: []));
		$docs = array_map(static fn (string $file): array => (array)json_decode((string)file_get_contents($file), true), $files);
		// A fragment names its schema by slug or, like 67-model-debt-cleanup,
		// only by the component key the base register uses for that slug.
		$names = [];
		foreach ($docs as $doc) {
			foreach (($doc['components']['schemas'] ?? []) as $name => $fragment) {
				if (($fragment['slug'] ?? null) === $slug) {
					$names[$name] = true;
				}
			}
		}

		$schema = [];
		foreach ($docs as $doc) {
			foreach (($doc['components']['schemas'] ?? []) as $name => $fragment) {
				if (($fragment['slug'] ?? null) === $slug || (isset($fragment['slug']) === false && isset($names[$name]) === true)) {
					$schema = array_replace_recursive($schema, $fragment);
				}
			}
		}

		return $schema;
	}//end mergedSchema()

	/**
	 * Validate an object against a merged schema, refusing undeclared keys.
	 *
	 * @param array<string, mixed> $data   The object
	 * @param array<string, mixed> $schema The merged schema
	 *
	 * @return bool
	 */
	private function validates(array $data, array $schema): bool {
		$properties = $schema['properties'];
		foreach ($properties as $key => $property) {
			unset($properties[$key]['$ref'], $properties[$key]['facetable'], $properties[$key]['format']);
		}

		$result = (new Validator())->validate(
			json_decode((string)json_encode($data)),
			json_decode((string)json_encode(['type' => 'object', 'required' => ($schema['required'] ?? []), 'properties' => $properties, 'additionalProperties' => false]))
		);
		return $result->isValid();
	}//end validates()

	/**
	 * Wrap an array as an ObjectEntity double.
	 *
	 * @param array<string, mixed> $data The object
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
	 * The meeting holds the current agenda item, and the meeting the live
	 * screen saves validates.
	 *
	 * @return void
	 */
	public function testTheMeetingHoldsTheCurrentItem(): void {
		$schema = $this->mergedSchema(slug: 'meeting');

		$this->assertArrayHasKey('currentAgendaItem', $schema['properties']);
		$this->assertTrue(
			$this->validates(['title' => 'Raad 14 oktober', 'meetingType' => 'regular', 'scheduledDate' => '2026-10-14T19:30:00+02:00', 'meetingMode' => 'in-person', 'lifecycle' => 'opened', 'currentAgendaItem' => 'item-5'], $schema)
		);
	}//end testTheMeetingHoldsTheCurrentItem()

	/**
	 * Scenario "The secretary records the decision": the decision saved live
	 * names the meeting and the current item, carries its type, and passes
	 * the Decision schema (it wrote an undeclared `relations` key and no
	 * decisionType before).
	 *
	 * @return void
	 */
	public function testALiveDecisionNamesItsMeetingAndItem(): void {
		$saved = [];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('find')->willReturn($this->entity(['id' => 'm-14', 'title' => 'Raad', 'lifecycle' => 'opened']));
		$objectService->method('findAll')->willReturn([$this->entity(['id' => 'min-1', 'meeting' => 'm-14', 'relations' => ['Meeting' => ['m-14']]])]);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null) use (&$saved): ObjectEntity {
				$saved[] = [$schema, $object];
				return $this->entity(['id' => 'd-1', '@self' => ['slug' => 'd-1']]);
			}
		);

		$service = new LiveDecisionService(
			container: $this->createMock(ContainerInterface::class),
			logger: new NullLogger(),
			objectService: $objectService,
		);
		$service->recordDecision(
			'm-14',
			['title' => 'Woningbouwplan', 'text' => 'De raad stemt in.', 'outcome' => 'adopted', 'decisionType' => 'resolution', 'agendaItem' => 'item-5']
		);

		$decisions = array_values(array_filter($saved, static fn (array $call): bool => strtolower((string)$call[0]) === 'decision'));
		$this->assertCount(1, $decisions);
		$this->assertSame('decision', $decisions[0][0]);
		$decision = $decisions[0][1];
		$this->assertSame('m-14', ($decision['meeting'] ?? null));
		$this->assertSame('item-5', ($decision['agendaItem'] ?? null));
		$this->assertSame('resolution', ($decision['decisionType'] ?? null));
		$this->assertTrue($this->validates($decision, $this->mergedSchema(slug: 'decision')), 'The live decision does not pass the Decision schema.');
	}//end testALiveDecisionNamesItsMeetingAndItem()

	/**
	 * Without a type from the dialog the decision is a resolution, and
	 * without an outcome it carries none (the enum has no 'pending').
	 *
	 * @return void
	 */
	public function testALiveDecisionWithoutTypeOrOutcomeStillValidates(): void {
		$saved = [];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('find')->willReturn($this->entity(['id' => 'm-14', 'lifecycle' => 'opened']));
		$objectService->method('findAll')->willReturn([$this->entity(['id' => 'min-1', 'relations' => ['Meeting' => ['m-14']]])]);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return $this->entity(['id' => 'd-1']);
			}
		);

		(new LiveDecisionService(container: $this->createMock(ContainerInterface::class), logger: new NullLogger(), objectService: $objectService))
			->recordDecision('m-14', ['title' => 'Kennisname', 'text' => 'De raad neemt kennis.']);

		$this->assertSame('resolution', ($saved[0]['decisionType'] ?? null));
		$this->assertArrayNotHasKey('outcome', $saved[0]);
		$this->assertTrue($this->validates($saved[0], $this->mergedSchema(slug: 'decision')));
	}//end testALiveDecisionWithoutTypeOrOutcomeStillValidates()

	/**
	 * Scenario "Who spoke on which item": a speech and a question sent from
	 * the live screen keep the agenda item, and the record passes the
	 * EngagementRecord schema.
	 *
	 * @return void
	 */
	public function testSpeechesAndQuestionsKeepTheirItem(): void {
		$stored = null;
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturnCallback(
			function () use (&$stored): array {
				return $stored === null ? [] : [$this->entity($stored)];
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$stored): ObjectEntity {
				$stored = $object;
				return $this->entity($object);
			}
		);

		$service = new EngagementService(logger: new NullLogger(), objectService: $objectService);
		$service->captureEngagement('m-14', 'p-anna', 'speech', ['duration' => 90, 'agendaItem' => 'item-5']);
		$service->captureEngagement('m-14', 'p-anna', 'question', ['agendaItem' => 'item-5']);

		$this->assertSame('item-5', ($stored['speeches'][0]['agendaItem'] ?? null));
		$this->assertSame('item-5', ($stored['questionsRaised'][0]['agendaItem'] ?? null));
		$this->assertTrue($this->validates($stored, $this->mergedSchema(slug: 'engagement-record')));
	}//end testSpeechesAndQuestionsKeepTheirItem()

	/**
	 * Scenario "Members follow the chair": making an item current patches
	 * the meeting with that item, and only that, in a shape the meeting
	 * schema accepts.
	 *
	 * @return void
	 */
	public function testMakingAnItemCurrentSavesItOnTheMeeting(): void {
		$patches = [];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturn($this->entity(['id' => 'item-5', 'meeting' => 'm-14']));
		$objectService->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data, string|int|null $register=null, string|int|null $schema=null) use (&$patches): ObjectEntity {
				$patches[] = [$objectId, $schema, $data];
				return $this->entity($data);
			}
		);

		(new CurrentAgendaItemService(objectService: $objectService))->setCurrentItem(meetingId: 'm-14', itemId: 'item-5');

		$this->assertSame([['m-14', 'meeting', ['currentAgendaItem' => 'item-5']]], $patches);
		$this->assertArrayHasKey('currentAgendaItem', $this->mergedSchema(slug: 'meeting')['properties']);
	}//end testMakingAnItemCurrentSavesItOnTheMeeting()

	/**
	 * An item of another meeting is not made current.
	 *
	 * @return void
	 */
	public function testAnItemOfAnotherMeetingIsNotMadeCurrent(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturn($this->entity(['id' => 'item-9', 'meeting' => 'other-meeting']));
		$objectService->expects($this->never())->method('patchObject');

		$this->expectException(\InvalidArgumentException::class);
		(new CurrentAgendaItemService(objectService: $objectService))->setCurrentItem(meetingId: 'm-14', itemId: 'item-9');
	}//end testAnItemOfAnotherMeetingIsNotMadeCurrent()
}//end class
