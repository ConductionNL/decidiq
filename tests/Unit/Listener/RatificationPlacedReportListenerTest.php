<?php

/**
 * A ratification agenda item placed on a meeting sets the urgent decision's
 * report date, once, through the real OpenRegister object events.
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

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use DateTime;
use OCA\Decidiq\Listener\RatificationPlacedReportListener;
use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\Decidiq\Service\RatifyingBodyReport;
use OCA\Decidiq\Service\UrgencyWriteGrant;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for RatificationPlacedReportListener.
 *
 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once
 */
class RatificationPlacedReportListenerTest extends TestCase {

	/**
	 * OpenRegister's object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The grant, to see it is held during the save.
	 *
	 * @var UrgencyWriteGrant
	 */
	private UrgencyWriteGrant $grant;

	/**
	 * The decisions the listener saved, as [uuid, object, granted].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>, 2: bool}>
	 */
	private array $saved = [];

	/**
	 * The listener under test.
	 *
	 * @var RatificationPlacedReportListener
	 */
	private RatificationPlacedReportListener $listener;

	/**
	 * Build the listener over a mocked object service, on 15 October 2026.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->grant         = new UrgencyWriteGrant();
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options=new ObjectSaveOptions(), bool $_rbac=true, bool $_multitenancy=true): ObjectEntity {
				self::assertSame(expected: ['decidiq', 'decision'], actual: [$options->target->register, $options->target->schema]);
				self::assertFalse(condition: $_rbac, message: 'the report date is an invariant of the procedure, not the saver\'s right');
				$this->saved[] = [(string)$options->uuid, $object, $this->grant->isGranted(decisionId: (string)$options->uuid)];
				return new ObjectEntity();
			}
		);

		$mapper    = new class {
			/**
			 * The schema behind an id.
			 *
			 * @param string|int $id The schema id.
			 *
			 * @return Schema
			 */
			public function find(string|int $id): Schema {
				$slugs = ['41' => 'agenda-item', '60' => 'decision'];
				if (isset($slugs[(string)$id]) === false) {
					throw new RuntimeException('Schema ' . $id . ' does not exist');
				}

				$schema = new Schema();
				$schema->setSlug($slugs[(string)$id]);
				return $schema;
			}
		};
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($mapper);
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-15T10:00:00+02:00'));

		$this->listener = new RatificationPlacedReportListener(
			objectService: $this->objectService,
			schemaResolver: new ListenerSchemaResolver(container: $container, logger: $this->createMock(originalClassName: LoggerInterface::class)),
			report: new RatifyingBodyReport(timeFactory: $time, grant: $this->grant),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * An OpenRegister object entity.
	 *
	 * @param string               $uuid     The uuid.
	 * @param string               $schemaId The schema id.
	 * @param array<string, mixed> $payload  The object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, string $schemaId, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister('7');
		$entity->setSchema($schemaId);
		$entity->setObject($payload);
		return $entity;
	}//end entity()

	/**
	 * The decision the object service finds, read without RBAC.
	 *
	 * @param array<string, mixed> $decision The decision.
	 *
	 * @return void
	 */
	private function decisionIs(array $decision): void {
		$this->objectService->method('find')
			->with('dec-1', new ObjectFindOptions(register: 'decidiq', schema: 'decision'), false, false)
			->willReturn($this->entity(uuid: 'dec-1', schemaId: '60', payload: $decision));
	}//end decisionIs()

	/**
	 * The ratification item placed on a meeting sets the report date, under the grant.
	 *
	 * @return void
	 */
	public function testPlacingTheRatificationSetsTheReportDate(): void {
		$this->decisionIs(decision: ['title' => 'Noodverordening Havenfestival 2026', 'isUrgent' => true]);

		$payload = ['title' => 'Ratification', 'meeting' => 'meet-raad', 'ratifiesDecision' => 'dec-1'];
		$item    = $this->entity(uuid: 'item-1', schemaId: '41', payload: $payload);
		$this->listener->handle(new ObjectCreatedEvent($item));

		self::assertCount(expectedCount: 1, haystack: $this->saved);
		self::assertSame(expected: 'dec-1', actual: $this->saved[0][0]);
		self::assertSame(expected: '2026-10-15', actual: $this->saved[0][1]['reportedToRatifyingBodyAt']);
		self::assertSame(expected: 'Noodverordening Havenfestival 2026', actual: $this->saved[0][1]['title']);
		self::assertTrue(condition: $this->saved[0][2], message: 'saved without the urgency write grant');
		self::assertFalse(condition: $this->grant->isGranted(decisionId: 'dec-1'));
	}//end testPlacingTheRatificationSetsTheReportDate()

	/**
	 * The agenda item comes later: a decision reported on 6 October keeps that date.
	 *
	 * @return void
	 */
	public function testAnEarlierReportDateStays(): void {
		$this->decisionIs(decision: ['isUrgent' => true, 'reportedToRatifyingBodyAt' => '2026-10-06']);

		$old = $this->entity(uuid: 'item-1', schemaId: '41', payload: ['ratifiesDecision' => 'dec-1']);
		$new = $this->entity(uuid: 'item-1', schemaId: '41', payload: ['ratifiesDecision' => 'dec-1', 'meeting' => 'meet-raad']);
		$this->listener->handle(new ObjectUpdatedEvent($new, $old));

		self::assertSame(expected: [], actual: $this->saved);
	}//end testAnEarlierReportDateStays()

	/**
	 * Nothing happens for an item that ratifies nothing, one not yet on a
	 * meeting, an object that is not an agenda item, or a decision that is
	 * not urgent.
	 *
	 * @return void
	 */
	public function testOtherSavesChangeNothing(): void {
		$this->decisionIs(decision: ['isUrgent' => false]);

		$this->listener->handle(new ObjectCreatedEvent($this->entity(uuid: 'item-2', schemaId: '41', payload: ['meeting' => 'meet-raad'])));
		$this->listener->handle(new ObjectCreatedEvent($this->entity(uuid: 'item-3', schemaId: '41', payload: ['ratifiesDecision' => 'dec-1'])));
		$notAnItem = $this->entity(uuid: 'dec-2', schemaId: '60', payload: ['ratifiesDecision' => 'dec-1', 'meeting' => 'm']);
		$this->listener->handle(new ObjectCreatedEvent($notAnItem));
		$asObjects = $this->entity(uuid: 'item-4', schemaId: '41', payload: ['ratifiesDecision' => ['id' => 'dec-1'], 'meeting' => ['id' => 'meet-raad']]);
		$this->listener->handle(new ObjectCreatedEvent($asObjects));

		self::assertSame(expected: [], actual: $this->saved);
	}//end testOtherSavesChangeNothing()

	/**
	 * A failed read is logged and does not break the agenda item's save.
	 *
	 * @return void
	 */
	public function testAFailedReadIsSwallowed(): void {
		$this->objectService->method('find')->willThrowException(new RuntimeException('gone'));

		$item = $this->entity(uuid: 'item-1', schemaId: '41', payload: ['meeting' => 'm', 'ratifiesDecision' => 'dec-1']);
		$this->listener->handle(new ObjectCreatedEvent($item));

		self::assertSame(expected: [], actual: $this->saved);
	}//end testAFailedReadIsSwallowed()
}//end class
