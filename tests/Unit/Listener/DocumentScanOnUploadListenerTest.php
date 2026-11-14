<?php

/**
 * Unit tests for DocumentScanOnUploadListener and PaperFolderOwner.
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
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-006-admin-settings-for-enforcement-and-scanning
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\BackgroundJob\DocumentAccessibilityScanJob;
use OCA\Decidiq\Listener\DocumentScanOnUploadListener;
use OCA\Decidiq\Listener\OfficePaperAddedListener;
use OCA\Decidiq\Listener\Registrar\CrossAppEventRegistrar;
use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\Decidiq\Service\PaperFolderOwner;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * A PDF added to a meeting or agenda item folder queues one scan, unless
 * scan-on-upload is off.
 *
 * @covers \OCA\Decidiq\Listener\DocumentScanOnUploadListener
 * @covers \OCA\Decidiq\Service\PaperFolderOwner
 * @uses   \OCA\Decidiq\Listener\OfficePaperAddedListener
 * @uses   \OCA\Decidiq\Service\ListenerSchemaResolver
 * @uses   \OCA\Decidiq\Listener\Registrar\CrossAppEventRegistrar
 * @uses   \OCA\Decidiq\Listener\Registrar\FilesEventRegistrar
 * @uses   \OCA\Decidiq\Listener\Registrar\FlowNodeRegistrar
 * @uses   \OCA\Decidiq\AppInfo\OpenRegisterAutoloader
 * @uses   \OCA\Decidiq\Listener\Registrar\TaskProcessingEventRegistrar
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#scenario-scan-on-upload-queues-a-background-scan
 */
class DocumentScanOnUploadListenerTest extends TestCase {

	/**
	 * The uuid of the agenda item Begroting 2027.
	 *
	 * @var string
	 */
	private const ITEM = '5b1f3c2a-8d4e-4f6a-9b7c-0e1d2f3a4b5c';

	/**
	 * The uuid of the council meeting of 14 October.
	 *
	 * @var string
	 */
	private const MEETING = '7c2e4d3b-9e5f-4a7b-8c8d-1f2e3a4b5c6d';

	/**
	 * Jobs the listener queued.
	 *
	 * @var list<array{0: string, 1: mixed}>
	 */
	private array $queued = [];

	/**
	 * A file double in a folder of the given name.
	 *
	 * @param string $name     The file name.
	 * @param string $folder   The parent folder's name.
	 * @param string $mimeType The file's type.
	 *
	 * @return File
	 */
	private function file(string $name, string $folder, string $mimeType='application/octet-stream'): File {
		$parent = $this->createMock(originalClassName: Folder::class);
		$parent->method('getName')->willReturn($folder);
		$file = $this->createMock(originalClassName: File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn(4711);
		$file->method('getMimetype')->willReturn($mimeType);
		$file->method('getParent')->willReturn($parent);
		return $file;
	}//end file()

	/**
	 * The listener over a register holding an agenda item, its meeting and a decision.
	 *
	 * @param string $stored The stored accessibility_scan_on_upload value.
	 *
	 * @return DocumentScanOnUploadListener
	 */
	private function listener(string $stored='true'): DocumentScanOnUploadListener {
		$objects = [
			self::ITEM => ['agenda-item', ['id' => self::ITEM, 'meeting' => self::MEETING]],
			self::MEETING => ['meeting', ['id' => self::MEETING, 'governanceBody' => 'body-raad']],
			'9d3f5e4c-af60-4b8c-9d9e-2a3b4c5d6e7f' => ['decision', ['id' => 'decision']],
		];
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id) use ($objects): ?ObjectEntity {
				if (isset($objects[$id]) === false) {
					return null;
				}

				$entity = $this->createMock(originalClassName: ObjectEntity::class);
				$entity->method('getSchema')->willReturn($objects[$id][0]);
				$entity->method('getObject')->willReturn($objects[$id][1]);
				return $entity;
			}
		);

		$jobList = $this->createMock(originalClassName: IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function (mixed $job, mixed $argument=null): void {
				$this->queued[] = [$job, $argument];
			}
		);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default='') use ($stored): string {
				if ($key === DocumentScanOnUploadListener::CONFIG_KEY) {
					return $stored;
				}

				return $default;
			}
		);

		$resolver = new ListenerSchemaResolver(container: $this->createMock(originalClassName: ContainerInterface::class), logger: new NullLogger());
		$owners   = new PaperFolderOwner(objectService: $objectService, schemaResolver: $resolver, logger: new NullLogger());

		return new DocumentScanOnUploadListener(owners: $owners, jobList: $jobList, appConfig: $appConfig);
	}//end listener()

	/**
	 * Scenario: a PDF uploaded to an agenda item queues a scan naming its meeting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#scenario-scan-on-upload-queues-a-background-scan
	 */
	public function testAPdfOnAnAgendaItemQueuesAScan(): void {
		$this->listener()->handle(event: new NodeCreatedEvent($this->file(name: 'Raadsvoorstel.PDF', folder: self::ITEM)));

		$argument = ['fileId' => 4711, 'objectId' => self::ITEM, 'schema' => 'agenda-item', 'meeting' => self::MEETING];
		self::assertSame(expected: [[DocumentAccessibilityScanJob::class, $argument]], actual: $this->queued);
	}//end testAPdfOnAnAgendaItemQueuesAScan()

	/**
	 * A rewritten PDF on a meeting, known by its type, queues a scan with the meeting's body.
	 *
	 * @return void
	 */
	public function testARewrittenPdfOnAMeetingCarriesItsBody(): void {
		$this->listener()->handle(event: new NodeWrittenEvent($this->file(name: 'stukken', folder: self::MEETING, mimeType: 'application/pdf')));

		$argument = ['fileId' => 4711, 'objectId' => self::MEETING, 'schema' => 'meeting', 'governanceBody' => 'body-raad'];
		self::assertSame(expected: [[DocumentAccessibilityScanJob::class, $argument]], actual: $this->queued);
	}//end testARewrittenPdfOnAMeetingCarriesItsBody()

	/**
	 * Scenario: scan-on-upload off queues nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-006-admin-settings-for-enforcement-and-scanning
	 */
	public function testSwitchedOffQueuesNothing(): void {
		foreach (['false', '0', 'off'] as $stored) {
			$this->listener(stored: $stored)->handle(event: new NodeCreatedEvent($this->file(name: 'a.pdf', folder: self::ITEM)));
		}

		self::assertSame(expected: [], actual: $this->queued);
	}//end testSwitchedOffQueuesNothing()

	/**
	 * Not a PDF, not a paper folder, another schema or another event: nothing is queued.
	 *
	 * @return void
	 */
	public function testAnythingElseQueuesNothing(): void {
		$listener = $this->listener();
		$listener->handle(event: new NodeCreatedEvent($this->file(name: 'notitie.docx', folder: self::ITEM)));
		$listener->handle(event: new NodeCreatedEvent($this->file(name: 'a.pdf', folder: 'Documents')));
		$listener->handle(event: new NodeCreatedEvent($this->file(name: 'a.pdf', folder: '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d')));
		$listener->handle(event: new NodeCreatedEvent($this->file(name: 'a.pdf', folder: '9d3f5e4c-af60-4b8c-9d9e-2a3b4c5d6e7f')));
		$listener->handle(event: new NodeCreatedEvent($this->createMock(originalClassName: Folder::class)));
		$listener->handle(event: new Event());

		self::assertSame(expected: [], actual: $this->queued);
	}//end testAnythingElseQueuesNothing()

	/**
	 * Both a new file and an overwritten one reach the listener, beside the Office paper one.
	 *
	 * @return void
	 */
	public function testTheRegistrarRegistersTheListener(): void {
		$registered = [];
		$context    = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		(new CrossAppEventRegistrar())->register(context: $context);

		self::assertContains(needle: [NodeCreatedEvent::class, DocumentScanOnUploadListener::class], haystack: $registered);
		self::assertContains(needle: [NodeWrittenEvent::class, DocumentScanOnUploadListener::class], haystack: $registered);
		self::assertContains(needle: [NodeCreatedEvent::class, OfficePaperAddedListener::class], haystack: $registered);
	}//end testTheRegistrarRegistersTheListener()
}//end class
