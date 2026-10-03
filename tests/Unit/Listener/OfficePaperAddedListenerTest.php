<?php

/**
 * Tests: an Office paper added to a meeting or agenda item queues one PDF conversion.
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

use OCA\Decidiq\AppInfo\Registrar\CrossAppEventRegistrar;
use OCA\Decidiq\BackgroundJob\ConvertPaperToPdfJob;
use OCA\Decidiq\Listener\OfficePaperAddedListener;
use OCA\Decidiq\Service\PublicationEventRecorder;
use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Decidiq\Listener\OfficePaperAddedListener
 * @uses   \OCA\Decidiq\Service\ListenerSchemaResolver
 * @uses   \OCA\Decidiq\AppInfo\Registrar\CrossAppEventRegistrar
 * @uses   \OCA\Decidiq\AppInfo\Registrar\FilesEventRegistrar
 * @uses   \OCA\Decidiq\AppInfo\Registrar\FlowNodeRegistrar
 * @uses   \OCA\Decidiq\AppInfo\OpenRegisterAutoloader
 * @uses   \OCA\Decidiq\AppInfo\Registrar\TaskProcessingEventRegistrar
 *
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-001-an-office-paper-added-to-a-meeting-or-agenda-item-is-converted-to-pdf
 */
class OfficePaperAddedListenerTest extends TestCase {

	/**
	 * The uuid of the agenda item Begroting 2027.
	 *
	 * @var string
	 */
	private const ITEM = '5b1f3c2a-8d4e-4f6a-9b7c-0e1d2f3a4b5c';

	/**
	 * Jobs the listener queued.
	 *
	 * @var list<array{0: string, 1: mixed}>
	 */
	private array $queued = [];

	/**
	 * Papers the listener reported to the publication event recorder: [schema, objectId, fileName].
	 *
	 * @var list<array{0: string, 1: string, 2: string}>
	 */
	private array $papers = [];

	/**
	 * A file double in a folder of the given name.
	 *
	 * @param string $name   The file name.
	 * @param string $folder The parent folder's name.
	 *
	 * @return File
	 */
	private function file(string $name, string $folder): File {
		$parent = $this->createMock(Folder::class);
		$parent->method('getName')->willReturn($folder);
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn(4711);
		$file->method('getParent')->willReturn($parent);
		return $file;
	}//end file()

	/**
	 * The listener over a register that holds the given objects.
	 *
	 * @param array<string, string> $objects Uuid to schema slug.
	 * @param bool                  $enabled The convert_office_papers switch.
	 *
	 * @return OfficePaperAddedListener
	 */
	private function listener(array $objects, bool $enabled=true): OfficePaperAddedListener {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id) use ($objects): ?ObjectEntity {
				if (isset($objects[$id]) === false) {
					return null;
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('getSchema')->willReturn($objects[$id]);
				$entity->method('getObject')->willReturn(['id' => $id]);
				return $entity;
			}
		);

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function (mixed $job, mixed $argument=null): void {
				$this->queued[] = [$job, $argument];
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$stored    = 'false';
		if ($enabled === true) {
			$stored = 'true';
		}

		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default='') use ($stored): string {
				if ($key === 'convert_office_papers') {
					return $stored;
				}

				return $default;
			}
		);

		$resolver = new ListenerSchemaResolver($this->createMock(ContainerInterface::class), new NullLogger());

		$recorder = $this->createMock(PublicationEventRecorder::class);
		$recorder->method('paperAdded')->willReturnCallback(
			function (string $schema, string $objectId, string $fileName): void {
				$this->papers[] = [$schema, $objectId, $fileName];
			}
		);

		return new OfficePaperAddedListener($objectService, $jobList, $appConfig, $resolver, new NullLogger(), $recorder);
	}//end listener()

	/**
	 * A docx created in an agenda item's folder queues one job with the file and the item.
	 *
	 * @return void
	 */
	public function testAWordPaperOnAnAgendaItemQueuesOneConversion(): void {
		$this->listener([self::ITEM => 'agenda-item'])->handle(new NodeCreatedEvent($this->file('Programmabegroting 2027.docx', self::ITEM)));

		$this->assertSame(
			[[ConvertPaperToPdfJob::class, ['fileId' => 4711, 'objectId' => self::ITEM, 'schema' => 'agenda-item']]],
			$this->queued
		);
	}//end testAWordPaperOnAnAgendaItemQueuesOneConversion()

	/**
	 * A spreadsheet rewritten in a meeting's folder queues a job for the meeting.
	 *
	 * @return void
	 */
	public function testARewrittenSpreadsheetOnAMeetingQueuesAConversion(): void {
		$this->listener([self::ITEM => 'meeting'])->handle(new NodeWrittenEvent($this->file('Bijlage investeringen.xlsx', self::ITEM)));

		$this->assertCount(1, $this->queued);
		$this->assertSame('meeting', $this->queued[0][1]['schema']);
	}//end testARewrittenSpreadsheetOnAMeetingQueuesAConversion()

	/**
	 * A PDF added to an agenda item's folder is reported as a new paper once, and not converted.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-002-agendas-papers-decisions-and-minutes-are-recorded-as-events
	 */
	public function testAPdfPaperIsReportedAsANewPaper(): void {
		$this->listener([self::ITEM => 'agenda-item'])->handle(new NodeCreatedEvent($this->file('Motie vreemd aan de orde.pdf', self::ITEM)));

		$this->assertSame([['agenda-item', self::ITEM, 'Motie vreemd aan de orde.pdf']], $this->papers);
		$this->assertSame([], $this->queued);
	}//end testAPdfPaperIsReportedAsANewPaper()

	/**
	 * An Office paper that will be converted is reported through its PDF, so once; with
	 * conversion off it is reported itself. A rewrite or a file outside decidiq is no new paper.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-002-agendas-papers-decisions-and-minutes-are-recorded-as-events
	 */
	public function testEachPaperIsReportedOnce(): void {
		$this->listener([self::ITEM => 'meeting'])->handle(new NodeCreatedEvent($this->file('Raadsvoorstel.docx', self::ITEM)));
		$this->assertSame([], $this->papers, 'The converted PDF reports it');

		$this->listener([self::ITEM => 'meeting'], enabled: false)->handle(new NodeCreatedEvent($this->file('Raadsvoorstel.docx', self::ITEM)));
		$this->assertSame([['meeting', self::ITEM, 'Raadsvoorstel.docx']], $this->papers);

		$this->papers = [];
		$this->listener([self::ITEM => 'meeting'])->handle(new NodeWrittenEvent($this->file('Motie.pdf', self::ITEM)));
		$this->listener([self::ITEM => 'meeting'])->handle(new NodeCreatedEvent($this->file('Motie.pdf', 'Documents')));
		$this->assertSame([], $this->papers);
	}//end testEachPaperIsReportedOnce()

	/**
	 * A file in a user's own folder, or in a uuid folder no meeting or agenda item owns, is left alone.
	 *
	 * @return void
	 */
	public function testAFileOutsideDecidiqIsLeftAlone(): void {
		$listener = $this->listener([self::ITEM => 'motion']);
		$listener->handle(new NodeCreatedEvent($this->file('Budget.xlsx', 'Documents')));
		$listener->handle(new NodeCreatedEvent($this->file('Budget.xlsx', '0b1f3c2a-8d4e-4f6a-9b7c-0e1d2f3a4b5c')));
		$listener->handle(new NodeCreatedEvent($this->file('Budget.xlsx', self::ITEM)));

		$this->assertSame([], $this->queued);
	}//end testAFileOutsideDecidiqIsLeftAlone()

	/**
	 * A PDF is not an Office paper.
	 *
	 * @return void
	 */
	public function testAPdfIsNotConverted(): void {
		$this->listener([self::ITEM => 'agenda-item'])->handle(new NodeCreatedEvent($this->file('Programmabegroting 2027.pdf', self::ITEM)));

		$this->assertSame([], $this->queued);
	}//end testAPdfIsNotConverted()

	/**
	 * With automatic conversion switched off nothing is queued.
	 *
	 * @return void
	 */
	public function testSwitchedOffQueuesNothing(): void {
		$this->listener([self::ITEM => 'agenda-item'], false)->handle(new NodeCreatedEvent($this->file('Programmabegroting 2027.docx', self::ITEM)));

		$this->assertSame([], $this->queued);
	}//end testSwitchedOffQueuesNothing()

	/**
	 * The app's real registration path subscribes the listener to both Files
	 * events, so a paper added or rewritten reaches it.
	 *
	 * @return void
	 */
	public function testTheAppRegistersTheListenerForNewAndRewrittenFiles(): void {
		$registered = [];
		$context    = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		(new CrossAppEventRegistrar())->register(context: $context);

		$this->assertContains([NodeCreatedEvent::class, OfficePaperAddedListener::class], $registered);
		$this->assertContains([NodeWrittenEvent::class, OfficePaperAddedListener::class], $registered);
	}//end testTheAppRegistersTheListenerForNewAndRewrittenFiles()
}//end class
