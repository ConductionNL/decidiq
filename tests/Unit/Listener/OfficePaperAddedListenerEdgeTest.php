<?php

/**
 * Edge cases of the office paper listener.
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

use OCA\Decidiq\Listener\OfficePaperAddedListener;
use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\Decidiq\Service\PublicationEventRecorder;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Other events and folders are ignored; a paper whose folder cannot be read,
 * or whose folder names no decidiq object the register can find, is neither
 * converted nor reported, and the failed lookup is logged at debug level.
 *
 * @covers \OCA\Decidiq\Listener\OfficePaperAddedListener
 * @uses   \OCA\Decidiq\Service\ListenerSchemaResolver
 *
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-001-an-office-paper-added-to-a-meeting-or-agenda-item-is-converted-to-pdf
 */
class OfficePaperAddedListenerEdgeTest extends TestCase {

	private const ITEM = '5b1f3c2a-8d4e-4f6a-9b7c-0e1d2f3a4b5c';

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The job list double.
	 *
	 * @var IJobList&MockObject
	 */
	private IJobList&MockObject $jobList;

	/**
	 * The event recorder double.
	 *
	 * @var PublicationEventRecorder&MockObject
	 */
	private PublicationEventRecorder&MockObject $recorder;

	/**
	 * The listener's logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The listener under test.
	 *
	 * @var OfficePaperAddedListener
	 */
	private OfficePaperAddedListener $listener;

	/**
	 * Set up the listener with conversion switched on.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->recorder = $this->createMock(PublicationEventRecorder::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('true');

		$this->listener = new OfficePaperAddedListener(
			$this->objectService,
			$this->jobList,
			$appConfig,
			new ListenerSchemaResolver($this->createMock(ContainerInterface::class), new NullLogger()),
			$this->logger,
			$this->recorder
		);
	}//end setUp()

	/**
	 * Expect that nothing is converted or reported.
	 *
	 * @return void
	 */
	private function expectNothingHappens(): void {
		$this->jobList->expects(self::never())->method('add');
		$this->recorder->expects(self::never())->method('paperAdded');
	}//end expectNothingHappens()

	/**
	 * Other events and created folders are ignored.
	 *
	 * @return void
	 */
	public function testOtherEventsAndFoldersAreIgnored(): void {
		$this->expectNothingHappens();
		$this->objectService->expects(self::never())->method('find');

		$this->listener->handle(new Event());
		$this->listener->handle(new NodeCreatedEvent($this->createMock(Folder::class)));
	}//end testOtherEventsAndFoldersAreIgnored()

	/**
	 * A paper whose folder cannot be read is left alone.
	 *
	 * @return void
	 */
	public function testAPaperWhoseFolderCannotBeReadIsLeftAlone(): void {
		$this->expectNothingHappens();
		$this->objectService->expects(self::never())->method('find');
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('notes.docx');
		$file->method('getParent')->willThrowException(new NotFoundException('gone'));

		$this->listener->handle(new NodeCreatedEvent($file));
	}//end testAPaperWhoseFolderCannotBeReadIsLeftAlone()

	/**
	 * A failed owner lookup is logged at debug level and the paper left alone.
	 *
	 * @return void
	 */
	public function testAFailedOwnerLookupIsLoggedAndThePaperLeftAlone(): void {
		$this->expectNothingHappens();
		$this->objectService->method('find')->willThrowException(new RuntimeException('no such object'));
		$this->logger->expects(self::once())->method('debug')->with(
			'Decidiq: no decidiq object for a new paper folder',
			['folder' => self::ITEM, 'error' => 'no such object']
		);
		$parent = $this->createMock(Folder::class);
		$parent->method('getName')->willReturn(self::ITEM);
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('notes.pdf');
		$file->method('getParent')->willReturn($parent);

		$this->listener->handle(new NodeCreatedEvent($file));
	}//end testAFailedOwnerLookupIsLoggedAndThePaperLeftAlone()
}//end class
