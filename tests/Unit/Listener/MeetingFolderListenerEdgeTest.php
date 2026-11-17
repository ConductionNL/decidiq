<?php

/**
 * Edge tests for MeetingFolderListener: an event without an entity, and a
 * meeting whose data carries no id.
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
 * @spec openspec/specs/nextcloud-integration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Decidiq\Kernel\Contracts\MeetingFolderServiceInterface;
use OCA\Decidiq\Listener\MeetingFolderListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Decidiq\Listener\MeetingFolderListener
 * @uses   \OCA\Decidiq\Listener\EntityPayloadReader
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class MeetingFolderListenerEdgeTest extends TestCase {

	/**
	 * A created event without an entity asks nothing of the resolver or the folder service.
	 *
	 * @return void
	 */
	public function testAnEventWithoutAnEntityDoesNothing(): void {
		$folders = $this->createMock(MeetingFolderServiceInterface::class);
		$folders->expects(self::never())->method('ensureMeetingFolders');
		$resolver = $this->createMock(ListenerSchemaResolverInterface::class);
		$resolver->expects(self::never())->method('matchesSchema');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');

		(new MeetingFolderListener($folders, $resolver, $logger))->handle(new ObjectCreatedEvent(null));
	}//end testAnEventWithoutAnEntityDoesNothing()

	/**
	 * A meeting whose data has no id gets the entity's uuid before its folders are made.
	 *
	 * @return void
	 */
	public function testAMeetingWithoutAnIdIsNamedByItsUuid(): void {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn(['title' => 'Raadsvergadering 15 oktober']);

		$resolver = $this->createMock(ListenerSchemaResolverInterface::class);
		$resolver->expects(self::once())->method('matchesSchema')
			->with($entity, 'meeting', ['title' => 'Raadsvergadering 15 oktober'])
			->willReturn(true);
		$resolver->expects(self::once())->method('readValue')->with($entity, 'getUuid')->willReturn('meeting-uuid-1');

		$folders = $this->createMock(MeetingFolderServiceInterface::class);
		$folders->expects(self::once())->method('ensureMeetingFolders')
			->with(['title' => 'Raadsvergadering 15 oktober', 'id' => 'meeting-uuid-1'])
			->willReturn('/Decidiq/Raadsvergadering 15 oktober');

		(new MeetingFolderListener($folders, $resolver, $this->createMock(LoggerInterface::class)))->handle(new ObjectCreatedEvent($entity));
	}//end testAMeetingWithoutAnIdIsNamedByItsUuid()
}//end class
