<?php

/**
 * Decidiq Ondermandaat Guard Listener Edges Test
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\OndermandaatGuardListener;
use OCA\Decidiq\Service\OndermandaatGuardService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The ondermandaat guard on OpenRegister's pre-save events: a toedeling the
 * guard refuses, or whose chain cannot be checked, is not saved; anything else
 * passes untouched.
 *
 * @covers \OCA\Decidiq\Listener\OndermandaatGuardListener
 * @uses   \OCA\Decidiq\Listener\EntityPayloadReader
 * @uses   \OCA\Decidiq\Service\AnyOfFieldsGuard
 */
class OndermandaatGuardListenerEdgesTest extends TestCase {

	/**
	 * What the guard was asked to check.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $checked = [];

	/**
	 * An entity whose payload is the row.
	 *
	 * @param array<string, mixed> $row  The payload.
	 * @param string|null          $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row, ?string $uuid=null): ObjectEntity {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn($row);
		$entity->method('getUuid')->willReturn($uuid);
		return $entity;
	}//end entity()

	/**
	 * The listener over a guard that records each check and then throws the given failure.
	 *
	 * @param \Throwable|null      $failure What the guard throws, null to allow.
	 * @param LoggerInterface|null $logger  The logger, a quiet one when null.
	 *
	 * @return OndermandaatGuardListener
	 */
	private function listener(?\Throwable $failure=null, ?LoggerInterface $logger=null): OndermandaatGuardListener {
		$guard = $this->createMock(originalClassName: OndermandaatGuardService::class);
		$guard->method('assertAllowed')->willReturnCallback(
			function (array $toedeling) use ($failure): void {
				$this->checked[] = $toedeling;
				if ($failure !== null) {
					throw $failure;
				}
			}
		);

		return new OndermandaatGuardListener(guard: $guard, logger: ($logger ?? $this->createMock(originalClassName: LoggerInterface::class)));
	}//end listener()

	/**
	 * A toedeling the guard allows is checked with its uuid as id and saved untouched.
	 *
	 * @return void
	 */
	public function testAnAllowedToedelingIsCheckedWithItsUuidAndPasses(): void {
		$event = new ObjectCreatingEvent($this->entity(row: ['_schemaSlug' => 'bevoegdheidstoedeling', 'mandaathouder' => 'u-1', 'delegatingBody' => 'college', 'delegateRole' => 'gemeentesecretaris'], uuid: 'td-1'));

		$this->listener()->handle(event: $event);

		$this->assertSame(
			expected: [['_schemaSlug' => 'bevoegdheidstoedeling', 'mandaathouder' => 'u-1', 'delegatingBody' => 'college', 'delegateRole' => 'gemeentesecretaris', 'id' => 'td-1']],
			actual: $this->checked
		);
		$this->assertFalse(condition: $event->isPropagationStopped());
		$this->assertSame(expected: [], actual: $event->getErrors());
	}//end testAnAllowedToedelingIsCheckedWithItsUuidAndPasses()

	/**
	 * An id the row already has is kept.
	 *
	 * @return void
	 */
	public function testAnIdInTheRowIsKept(): void {
		$this->listener()->handle(event: new ObjectCreatingEvent($this->entity(row: ['id' => 'td-own', 'delegatingBody' => 'college', 'delegateRole' => 'gemeentesecretaris'], uuid: 'td-uuid')));

		$this->assertSame(expected: 'td-own', actual: $this->checked[0]['id']);
	}//end testAnIdInTheRowIsKept()

	/**
	 * An update is checked on the new object, not the old one.
	 *
	 * @return void
	 */
	public function testAnUpdateIsCheckedOnTheNewObject(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(row: ['id' => 'td-1', 'mandaathouder' => 'u-new', 'delegatingBody' => 'college', 'delegateRole' => 'gemeentesecretaris']),
			$this->entity(row: ['id' => 'td-1', 'mandaathouder' => 'u-old'])
		);

		$this->listener()->handle(event: $event);

		$this->assertSame(expected: 'u-new', actual: $this->checked[0]['mandaathouder']);
	}//end testAnUpdateIsCheckedOnTheNewObject()

	/**
	 * A chain that cannot be checked is logged and the save refused.
	 *
	 * @return void
	 */
	public function testAChainThatCannotBeCheckedIsLoggedAndRefused(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Decidiq: the ondermandaat chain could not be checked',
			['error' => 'register offline'],
		);
		$event = new ObjectCreatingEvent($this->entity(row: ['schema' => '17', 'delegatingBody' => 'college', 'delegateRole' => 'gemeentesecretaris']));

		$this->listener(failure: new RuntimeException(message: 'register offline'), logger: $logger)->handle(event: $event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(
			expected: ['message' => 'The toedeling was not saved: its ondermandaat chain could not be checked. Try again in a moment.'],
			actual: $event->getErrors()
		);
	}//end testAChainThatCannotBeCheckedIsLoggedAndRefused()

	/**
	 * Any other event is ignored.
	 *
	 * @return void
	 */
	public function testAnyOtherEventIsIgnored(): void {
		$this->listener()->handle(event: new Event());

		$this->assertSame(expected: [], actual: $this->checked);
	}//end testAnyOtherEventIsIgnored()

	/**
	 * A create without an entity is not checked and not refused.
	 *
	 * @return void
	 */
	public function testACreateWithoutAnEntityIsLetThrough(): void {
		$event = new ObjectCreatingEvent(null);

		$this->listener(failure: new RuntimeException(message: 'must not be asked'))->handle(event: $event);

		$this->assertSame(expected: [], actual: $this->checked);
		$this->assertFalse(condition: $event->isPropagationStopped());
	}//end testACreateWithoutAnEntityIsLetThrough()
}//end class
