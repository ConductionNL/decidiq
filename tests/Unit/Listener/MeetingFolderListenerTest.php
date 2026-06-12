<?php

/**
 * Unit tests for MeetingFolderListener.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Listener
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

namespace OCA\Decidesk\Tests\Unit\Listener;

use OCA\Decidesk\Listener\MeetingFolderListener;
use OCA\Decidesk\Service\MeetingFolderService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the schema filter, event-type filter, and fail-soft contract.
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class MeetingFolderListenerTest extends TestCase
{

    /**
     * Folder service mock.
     *
     * @var MeetingFolderService&MockObject
     */
    private MeetingFolderService&MockObject $folderService;

    /**
     * Listener under test.
     *
     * @var MeetingFolderListener
     */
    private MeetingFolderListener $listener;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->folderService = $this->createMock(MeetingFolderService::class);
        $this->listener      = new MeetingFolderListener(
            folderService: $this->folderService,
            logger: $this->createMock(LoggerInterface::class),
        );

    }//end setUp()

    /**
     * Build an ObjectCreatedEvent mock carrying the given object payload.
     *
     * @param array<string, mixed> $row Object payload (with _schemaSlug)
     *
     * @return ObjectCreatedEvent&MockObject
     */
    private function createdEvent(array $row): ObjectCreatedEvent&MockObject
    {
        $entity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        $entity->method('getObject')->willReturn($row);
        $entity->method('jsonSerialize')->willReturn($row);

        $event = $this->createMock(ObjectCreatedEvent::class);
        $event->method('getObject')->willReturn($entity);
        return $event;

    }//end createdEvent()

    /**
     * Meeting creations trigger the folder service.
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return void
     */
    public function testMeetingCreationTriggersFolders(): void
    {
        $this->folderService->expects(self::once())
            ->method('ensureMeetingFolders')
            ->with(self::callback(static fn (array $m): bool => ($m['title'] ?? '') === 'Q3 Meeting'));

        $this->listener->handle(
            $this->createdEvent(['_schemaSlug' => 'meeting', 'id' => 'meet-1', 'title' => 'Q3 Meeting'])
        );

    }//end testMeetingCreationTriggersFolders()

    /**
     * Other schemas are ignored.
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return void
     */
    public function testOtherSchemasIgnored(): void
    {
        $this->folderService->expects(self::never())->method('ensureMeetingFolders');

        $this->listener->handle(
            $this->createdEvent(['_schemaSlug' => 'decision', 'id' => 'dec-1', 'title' => 'Budget'])
        );

    }//end testOtherSchemasIgnored()

    /**
     * Non-created events are ignored.
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return void
     */
    public function testNonCreatedEventsIgnored(): void
    {
        $this->folderService->expects(self::never())->method('ensureMeetingFolders');

        $this->listener->handle($this->createMock(Event::class));

    }//end testNonCreatedEventsIgnored()

    /**
     * Folder service failures never escape the listener (fail soft).
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return void
     */
    public function testFolderFailureIsSwallowed(): void
    {
        $this->folderService->method('ensureMeetingFolders')
            ->willThrowException(new \RuntimeException('Files down'));

        $this->listener->handle(
            $this->createdEvent(['_schemaSlug' => 'meeting', 'id' => 'meet-1', 'title' => 'Q3 Meeting'])
        );

        // Reaching this point without an exception is the assertion.
        self::assertTrue(condition: true);

    }//end testFolderFailureIsSwallowed()
}//end class
