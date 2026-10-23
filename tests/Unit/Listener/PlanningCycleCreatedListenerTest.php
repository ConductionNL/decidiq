<?php

/**
 * Tests for PlanningCycleGenerator and PlanningCycleCreatedListener
 * (planning-cycle-generate-from-template, pla-12).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/planning-cycle-generate-from-template/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\AppInfo\Registrar\ObjectListenerRegistrar;
use OCA\Decidiq\Listener\PlanningCycleCreatedListener;
use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\Decidiq\Service\PlanningCycleGenerator;
use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A cycle made from a template gets its steps, in order, once.
 *
 * @spec openspec/changes/planning-cycle-generate-from-template/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps
 */
final class PlanningCycleCreatedListenerTest extends TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $saved = [];

	/** @var array<int, mixed> */
	private array $existingSteps = [];

	/**
	 * The municipal template as the example set ships it.
	 *
	 * @return array<string, mixed>
	 */
	private function municipalTemplate(): array {
		$profile = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/profiles/municipality.json'), true);
		foreach (($profile['x-openregister']['seedData']['objects']['planning-cycle-template'] ?? []) as $template) {
			if (($template['slug'] ?? '') === 'municipal-pc-cyclus') {
				return $template;
			}
		}

		self::fail('The municipal template is missing from the example set.');
	}//end municipalTemplate()

	/**
	 * The listener with a fake ObjectService.
	 *
	 * @param array<string, mixed>|null $template The template find() returns
	 *
	 * @return PlanningCycleCreatedListener
	 */
	private function listener(?array $template): PlanningCycleCreatedListener {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(fn (): array => $this->existingSteps);
		$objects->method('find')->willReturnCallback(
			function () use ($template): ?ObjectEntity {
				if ($template === null) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid('tpl-1');
				$entity->setObject($template);
				return $entity;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				return new ObjectEntity();
			}
		);

		$mapper = new class {
			/**
			 * Resolve a schema by id.
			 *
			 * @param string|int $id Schema id.
			 *
			 * @return Schema
			 */
			public function find(string|int $id): Schema {
				$slugs = ['61' => 'planning-cycle', '93' => 'meeting'];
				if (isset($slugs[(string)$id]) === false) {
					throw new RuntimeException('Schema ' . $id . ' does not exist');
				}

				$schema = new Schema();
				$schema->setSlug($slugs[(string)$id]);
				return $schema;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		return new PlanningCycleCreatedListener(
			objectService: $objects,
			generator: new PlanningCycleGenerator(),
			schemaResolver: new ListenerSchemaResolver(container: $container, logger: $this->createMock(LoggerInterface::class)),
			logger: new NullLogger()
		);
	}//end listener()

	/**
	 * A created cycle.
	 *
	 * @param array<string, mixed> $data     The cycle data
	 * @param string               $schemaId The schema id
	 *
	 * @return ObjectCreatedEvent
	 */
	private function created(array $data, string $schemaId = '61'): ObjectCreatedEvent {
		$entity = new ObjectEntity();
		$entity->setUuid('cycle-2027');
		$entity->setRegister('7');
		$entity->setSchema($schemaId);
		$entity->setObject($data);
		return new ObjectCreatedEvent($entity);
	}//end created()

	public function testCycle2027FromTheMunicipalTemplateGetsItsStepsInOrder(): void {
		$template = $this->municipalTemplate();
		$this->listener(template: $template)->handle($this->created(data: ['name' => 'P&C 2027', 'year' => 2027, 'governanceBody' => 'gb-1', 'template' => 'tpl-1']));

		self::assertCount(count($template['steps']), $this->saved);
		self::assertSame(range(1, count($template['steps'])), array_column($this->saved, 'sequence'));
		self::assertSame(array_column($template['steps'], 'label'), array_column($this->saved, 'label'));

		$begroting = $this->saved[array_search('begroting', array_column($this->saved, 'stepType'), true)];
		self::assertSame('2027-09-15', $begroting['deliveryDeadline']);
		self::assertSame('2027-10-20', $begroting['committeeDate']);
		self::assertSame(2028, $begroting['concernsYear']);
		self::assertSame('planned', $begroting['status']);
		self::assertSame('cycle-2027', $begroting['cycle']);

		foreach ($this->saved as $step) {
			self::assertSame([], MergedRegisterSchema::violations(slug: 'planning-cycle-step', payload: $step), json_encode($step));
		}
	}//end testCycle2027FromTheMunicipalTemplateGetsItsStepsInOrder()

	public function testACycleThatAlreadyHasStepsGetsNoSecondSet(): void {
		$this->existingSteps = [new ObjectEntity()];
		$this->listener(template: $this->municipalTemplate())->handle($this->created(data: ['name' => 'P&C 2027', 'year' => 2027, 'template' => 'tpl-1']));
		self::assertSame([], $this->saved);
	}//end testACycleThatAlreadyHasStepsGetsNoSecondSet()

	public function testACycleWithoutATemplateOrOfAnotherSchemaIsLeftAlone(): void {
		$this->listener(template: $this->municipalTemplate())->handle($this->created(data: ['name' => 'P&C 2027', 'year' => 2027]));
		$this->listener(template: $this->municipalTemplate())->handle($this->created(data: ['title' => 'x', 'year' => 2027, 'template' => 'tpl-1'], schemaId: '93'));
		$this->listener(template: null)->handle($this->created(data: ['name' => 'P&C 2027', 'year' => 2027, 'template' => 'gone']));
		self::assertSame([], $this->saved);
	}//end testACycleWithoutATemplateOrOfAnotherSchemaIsLeftAlone()

	public function testADefaultThatIsNotADayInTheYearIsLeftOut(): void {
		$generator = new PlanningCycleGenerator();
		self::assertSame('2028-02-29', $generator->resolveDate(monthDay: '02-29', year: 2028));
		self::assertNull($generator->resolveDate(monthDay: '02-29', year: 2027));
		self::assertNull($generator->resolveDate(monthDay: 'spring', year: 2027));
		self::assertNull($generator->resolveDate(monthDay: null, year: 2027));

		$steps = $generator->stepsFor(cycleId: 'c', year: 2027, template: ['steps' => [['stepType' => 'decharge', 'deliveryDeadline' => null], ['label' => '']]]);
		self::assertCount(1, $steps);
		self::assertSame('decharge', $steps[0]['label']);
		self::assertArrayNotHasKey('deliveryDeadline', $steps[0]);
	}//end testADefaultThatIsNotADayInTheYearIsLeftOut()

	public function testTheRegistrarSubscribesTheListenerToCycleCreates(): void {
		$subscribed = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('addServiceListener')->willReturnCallback(
			function (string $event, string $listener) use (&$subscribed): void {
				$subscribed[] = [$event, $listener];
			}
		);

		(new ObjectListenerRegistrar(logger: new NullLogger()))->register($dispatcher);

		self::assertContains([ObjectCreatedEvent::class, PlanningCycleCreatedListener::class], $subscribed);
	}//end testTheRegistrarSubscribesTheListenerToCycleCreates()
}//end class
