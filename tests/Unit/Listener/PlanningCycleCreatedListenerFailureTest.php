<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Listener
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\PlanningCycleCreatedListener;
use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\Decidiq\Service\PlanningCycleGenerator;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Step generation never breaks the save that created the cycle, and only a
 * create starts it.
 *
 * @covers \OCA\Decidiq\Listener\PlanningCycleCreatedListener
 * @uses   \OCA\Decidiq\Listener\EntityPayloadReader
 * @uses   \OCA\Decidiq\Service\ListenerSchemaResolver
 */
class PlanningCycleCreatedListenerFailureTest extends TestCase {

	/**
	 * The listener over an object service and a logger.
	 *
	 * @param ObjectServiceInterface $objects   The object service.
	 * @param PlanningCycleGenerator $generator The step generator.
	 * @param LoggerInterface        $logger    The logger.
	 *
	 * @return PlanningCycleCreatedListener
	 */
	private function listener(ObjectServiceInterface $objects, PlanningCycleGenerator $generator, LoggerInterface $logger): PlanningCycleCreatedListener {
		$mapper = new class {
			/**
			 * The planning-cycle schema.
			 *
			 * @param string|int $id The schema id.
			 *
			 * @return Schema
			 */
			public function find(string|int $id): Schema {
				$schema = new Schema();
				$schema->setSlug('planning-cycle');
				return $schema;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		return new PlanningCycleCreatedListener(
			objectService: $objects,
			generator: $generator,
			schemaResolver: new ListenerSchemaResolver(container: $container, logger: new NullLogger()),
			logger: $logger
		);
	}//end listener()

	/**
	 * A cycle entity.
	 *
	 * @return ObjectEntity
	 */
	private function cycle(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('cycle-2027');
		$entity->setRegister('7');
		$entity->setSchema('61');
		$entity->setObject(['name' => 'P&C 2027', 'year' => 2027, 'template' => 'tpl-1']);
		return $entity;
	}//end cycle()

	/**
	 * A failing step save is logged as a warning and swallowed.
	 *
	 * @return void
	 */
	public function testAFailingStepSaveIsLoggedAndSwallowed(): void {
		$template = new ObjectEntity();
		$template->setObject(['steps' => []]);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturn([]);
		$objects->expects($this->once())->method('find')->with('tpl-1', [], false, 'decidiq', 'planning-cycle-template')->willReturn($template);
		$objects->method('saveObject')->willThrowException(new RuntimeException('register read-only'));
		$generator = $this->createMock(PlanningCycleGenerator::class);
		$generator->expects($this->once())->method('stepsFor')->with('cycle-2027', 2027, $this->callback(static fn (array $template): bool => ($template['steps'] ?? null) === []))->willReturn([['title' => 'Kadernota']]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Decidiq: generating the planning cycle steps failed',
			['exception' => 'register read-only']
		);

		$this->listener($objects, $generator, $logger)->handle(new ObjectCreatedEvent($this->cycle()));
	}//end testAFailingStepSaveIsLoggedAndSwallowed()

	/**
	 * An update of a cycle generates nothing.
	 *
	 * @return void
	 */
	public function testAnUpdateGeneratesNothing(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->never())->method('findAll');
		$objects->expects($this->never())->method('saveObject');
		$generator = $this->createMock(PlanningCycleGenerator::class);
		$generator->expects($this->never())->method('stepsFor');

		$this->listener($objects, $generator, $this->createMock(LoggerInterface::class))->handle(new ObjectUpdatedEvent($this->cycle(), $this->cycle()));
	}//end testAnUpdateGeneratesNothing()
}//end class
