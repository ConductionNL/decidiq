<?php

/**
 * Unit tests for agenda templates and copying agendas
 * (agenda-templates-and-copy, age-04 and pla-14).
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
 * @spec openspec/specs/agenda-builder/spec.md#requirement-req-atc-001-start-an-agenda-from-a-template
 * @spec openspec/specs/agenda-builder/spec.md#requirement-req-atc-002-copy-items-or-a-whole-agenda-from-an-earlier-meeting
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AgendaItemCopier;
use OCA\Decidiq\Service\AuditLogService;
use OCA\Decidiq\Service\MeetingSeriesService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The council meeting of 7 October has three agenda items; later meetings copy them.
 *
 * @spec openspec/specs/agenda-builder/spec.md#requirement-req-atc-002-copy-items-or-a-whole-agenda-from-an-earlier-meeting
 */
class AgendaTemplatesAndCopyTest extends TestCase {

	/**
	 * The meeting of 14 October that gets the copies.
	 *
	 * @var string
	 */
	private const NEW_MEETING = '6f1c1c38-4d3c-4d0e-9a55-2b8b2b1f0e14';

	/**
	 * The agenda of the meeting of 7 October, out of order, plus one item of
	 * another meeting the store answers too.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function earlierAgenda(): array {
		return [
			['id' => 'i-3', 'meeting' => 'm-07', 'title' => 'Rondvraag', 'itemType' => 'informational', 'orderNumber' => 3],
			['id' => 'i-1', 'meeting' => 'm-07', 'title' => 'Opening', 'itemType' => 'informational', 'orderNumber' => 1, 'isFormality' => true, 'formalityOutcome' => 'adopted-without-debate', 'adoptedAt' => '2026-10-07T19:31:00+02:00'],
			['id' => 'i-2', 'meeting' => 'm-07', 'title' => 'Begroting 2027', 'itemType' => 'decision', 'orderNumber' => 2, 'description' => 'Vaststellen', 'estimatedDuration' => 45, 'type' => '0b6f1d2e-7c1a-4f7e-9a51-3d2c1b0a9e11', 'typeFields' => ['bedrag' => 100], 'parentItem' => null],
			['id' => 'x-1', 'meeting' => 'm-99', 'title' => 'Ander overleg', 'itemType' => 'discussion', 'orderNumber' => 1],
		];
	}//end earlierAgenda()

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
		$schema = [];
		foreach ($files as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			foreach (($doc['components']['schemas'] ?? []) as $name => $fragment) {
				if (($fragment['slug'] ?? $name) === $slug) {
					$schema = array_replace_recursive($schema, $fragment);
				}
			}
		}

		return $schema;
	}//end mergedSchema()

	/**
	 * Validate an object against a merged schema, without its OpenRegister keywords.
	 *
	 * @param array<string, mixed> $data   The object
	 * @param array<string, mixed> $schema The merged schema
	 *
	 * @return bool
	 */
	private function validates(array $data, array $schema): bool {
		$strip = static function (mixed $node) use (&$strip): mixed {
			if (is_array($node) === false) {
				return $node;
			}

			unset($node['$ref'], $node['facetable'], $node['x-openregister'], $node['inversedBy']);
			foreach ($node as $key => $value) {
				if (is_array($value) === true && in_array($key, ['required', 'enum'], true) === false) {
					$node[$key] = $strip($value);
				}
			}

			return $node;
		};
		$properties = $strip($schema['properties']);
		$result = (new Validator())->validate(
			json_decode((string)json_encode($data)),
			json_decode((string)json_encode(['type' => 'object', 'required' => ($schema['required'] ?? []), 'properties' => $properties, 'additionalProperties' => false]))
		);
		return $result->isValid();
	}//end validates()

	/**
	 * A template is a named, ordered list of items; a meeting type can name
	 * its default template.
	 *
	 * @spec openspec/specs/agenda-builder/spec.md#requirement-req-atc-001-start-an-agenda-from-a-template
	 *
	 * @return void
	 */
	public function testATemplateIsANamedListOfItems(): void {
		$template = $this->mergedSchema(slug: 'agenda-template');
		$this->assertNotSame([], $template, 'An agenda-template schema is declared');
		$this->assertTrue(
			$this->validates(
				['name' => 'Raadsvergadering', 'items' => [['title' => 'Opening', 'itemType' => 'informational', 'isFormality' => true], ['title' => 'Besluiten', 'itemType' => 'decision', 'estimatedDuration' => 30]]],
				$template
			)
		);
		$this->assertFalse($this->validates(['name' => 'Zonder titel', 'items' => [['itemType' => 'decision']]], $template));
		$this->assertFalse($this->validates(['items' => []], $template));

		$type = $this->mergedSchema(slug: 'meeting-type');
		$this->assertArrayHasKey('defaultAgendaTemplate', $type['properties']);

		$registerSchemas = [];
		foreach ((glob(__DIR__ . '/../../../lib/Settings/register.d/*.json') ?: []) as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			$registerSchemas = array_merge($registerSchemas, ($doc['components']['registers']['decidiq']['schemas'] ?? []));
		}

		$this->assertContains('agenda-template', $registerSchemas);
	}//end testATemplateIsANamedListOfItems()

	/**
	 * A copied item keeps title, kind, description, duration and type, lands
	 * after the last item of the new meeting in the earlier order, and
	 * validates against the real AgendaItem schema; its outcome does not come along.
	 *
	 * @return void
	 */
	public function testCopiedItemsKeepWhatTheyAreAndLandAfterTheLastItem(): void {
		$payloads = (new AgendaItemCopier())->payloads(items: $this->earlierAgenda(), fromMeetingId: 'm-07', toMeetingId: self::NEW_MEETING, startAt: 5);

		$this->assertSame(['Opening', 'Begroting 2027', 'Rondvraag'], array_column($payloads, 'title'));
		$this->assertSame([5, 6, 7], array_column($payloads, 'orderNumber'));
		$this->assertSame(self::NEW_MEETING, $payloads[1]['meeting']);
		$this->assertSame('Vaststellen', $payloads[1]['description']);
		$this->assertSame(45, $payloads[1]['estimatedDuration']);
		$this->assertSame('0b6f1d2e-7c1a-4f7e-9a51-3d2c1b0a9e11', $payloads[1]['type']);
		$this->assertTrue($payloads[0]['isFormality']);
		$this->assertArrayNotHasKey('formalityOutcome', $payloads[0]);
		$this->assertArrayNotHasKey('adoptedAt', $payloads[0]);
		$this->assertArrayNotHasKey('id', $payloads[0]);

		$schema = $this->mergedSchema(slug: 'agenda-item');
		foreach ($payloads as $payload) {
			$this->assertTrue($this->validates($payload, $schema), 'Copied item validates: ' . json_encode($payload));
		}
	}//end testCopiedItemsKeepWhatTheyAreAndLandAfterTheLastItem()

	/**
	 * Scenario: a series generated from a meeting gives each new meeting its agenda.
	 *
	 * @return void
	 */
	public function testEachMeetingOfASeriesGetsTheAgenda(): void {
		$saved = [];
		$meeting = ['id' => 'm-07', 'title' => 'Gemeenteraad', 'scheduledDate' => '2026-10-07T19:30:00+02:00', 'lifecycle' => 'scheduled'];
		$agenda = $this->earlierAgenda();

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, mixed ...$rest) use ($meeting): ?ObjectEntity {
				if ($id !== 'm-07') {
					return null;
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($meeting);
				$entity->method('getObject')->willReturn($meeting);
				return $entity;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($agenda): array {
				if (($config['filters']['schema'] ?? '') !== 'agenda-item') {
					return [];
				}

				return array_map(
					function (array $row): ObjectEntity {
						$entity = $this->createMock(ObjectEntity::class);
						$entity->method('jsonSerialize')->willReturn($row);
						$entity->method('getObject')->willReturn($row);
						return $entity;
					},
					$agenda
				);
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null) use (&$saved): ObjectEntity {
				$uuid = ($uuid ?? ($schema . '-' . (count($saved) + 1)));
				$row = array_merge(['id' => $uuid], $object);
				$saved[] = ['schema' => $schema, 'row' => $row];
				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($row);
				$entity->method('getObject')->willReturn($row);
				return $entity;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$result = (new MeetingSeriesService($container, new NullLogger(), $this->createMock(AuditLogService::class)))->generateSeries(
			'm-07',
			['frequency' => 'weekly', 'interval' => 1, 'until' => '2026-10-21'],
			'griffier'
		);

		$this->assertTrue($result['success']);
		$newMeetings = array_values(array_filter($saved, static fn (array $s): bool => $s['schema'] === 'meeting' && $s['row']['id'] !== 'm-07'));
		$this->assertCount(2, $newMeetings);
		foreach ($newMeetings as $new) {
			$items = array_values(array_filter($saved, static fn (array $s): bool => $s['schema'] === 'agenda-item' && $s['row']['meeting'] === $new['row']['id']));
			$this->assertSame(['Opening', 'Begroting 2027', 'Rondvraag'], array_map(static fn (array $s): string => $s['row']['title'], $items));
		}
	}//end testEachMeetingOfASeriesGetsTheAgenda()
}//end class
