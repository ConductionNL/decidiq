<?php

/**
 * Tests: the PDF conversion job's guards, broken-off conversions and recording failures.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\BackgroundJob
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

namespace OCA\Decidiq\Tests\Unit\BackgroundJob;

use OCA\Decidiq\BackgroundJob\ConvertPaperToPdfJob;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\BackgroundJob\ConvertPaperToPdfJob
 * @uses   \OCA\Decidiq\Support\FleetAppId
 *
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-002-a-failed-or-impossible-conversion-is-visible-and-the-original-stays
 */
class ConvertPaperToPdfJobGuardsTest extends TestCase {

	/**
	 * The meeting the paper belongs to.
	 *
	 * @var string
	 */
	private const MEETING = '0c9e1f7a-2b3d-4e5f-8a6b-7c8d9e0f1a2b';

	/**
	 * Objects handed to saveObject(), with the schema they were saved under.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * File ids looked up in the root folder.
	 *
	 * @var list<int>
	 */
	private array $lookedUp = [];

	/**
	 * Warnings logged.
	 *
	 * @var list<string>
	 */
	private array $warnings = [];

	/**
	 * Calls made on the converter.
	 *
	 * @var \ArrayObject<int, string>
	 */
	private \ArrayObject $conversions;

	/**
	 * Fresh recorders per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->conversions = new \ArrayObject();
	}//end setUp()

	/**
	 * A paper whose folder lookups throw, so the newer-PDF check gives up.
	 *
	 * @return File
	 */
	private function paperInUnreadableFolder(): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Raadsvoorstel.docx');
		$file->method('getParent')->willThrowException(new RuntimeException('storage offline'));
		return $file;
	}//end paperInUnreadableFolder()

	/**
	 * A paper without a PDF beside it.
	 *
	 * @return File
	 */
	private function paper(): File {
		$parent = $this->createMock(Folder::class);
		$parent->method('nodeExists')->willReturn(false);
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Raadsvoorstel.docx');
		$file->method('getParent')->willReturn($parent);
		return $file;
	}//end paper()

	/**
	 * Build the job.
	 *
	 * @param mixed                    $node   What file id 4711 resolves to.
	 * @param array<string, mixed>     $result What the converter returns.
	 * @param ObjectEntity|null|string $object The stored object, or 'throw' to fail the lookup.
	 *
	 * @return ConvertPaperToPdfJob
	 */
	private function job(mixed $node, array $result, ObjectEntity|null|string $object): ConvertPaperToPdfJob {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getFirstNodeById')->willReturnCallback(function (int $id) use ($node): mixed {
			$this->lookedUp[] = $id;
			return $node;
		});

		$converter = new class($this->conversions, $result) {
			/**
			 * @param \ArrayObject<int, string> $calls  Names converted.
			 * @param array<string, mixed>      $result What to return.
			 */
			public function __construct(private \ArrayObject $calls, private array $result) {
			}

			/**
			 * The shape of filinq's PdfConversionService::convertToPdfReporting().
			 *
			 * @param File $source The source.
			 *
			 * @return array<string, mixed>
			 */
			public function convertToPdfReporting(File $source): array {
				$this->calls[] = $source->getName();
				return $this->result;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($converter);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		if ($object === 'throw') {
			$objectService->method('find')->willThrowException(new RuntimeException('register unavailable'));
		} else {
			$objectService->method('find')->willReturn($object);
		}

		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], mixed $register=null, mixed $schema=null): ObjectEntity {
				$this->saved[] = [(string)$schema, $object];
				return $this->createMock(ObjectEntity::class);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string|\Stringable $message): void {
			$this->warnings[] = (string)$message;
		});

		return new ConvertPaperToPdfJob($this->createMock(ITimeFactory::class), $container, $root, $objectService, $logger);
	}//end job()

	/**
	 * A stored meeting with the given data.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity
	 */
	private function stored(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end stored()

	/**
	 * The queued argument is unpacked and converted for its meeting.
	 *
	 * @return void
	 */
	public function testTheQueuedArgumentIsConvertedForItsMeeting(): void {
		$job = $this->job($this->paper(), ['file' => new class {
			/**
			 * @return string
			 */
			public function getId(): string {
				return '88';
			}
		}], $this->stored(['id' => self::MEETING]));

		(new ReflectionMethod($job, 'run'))->invoke($job, ['fileId' => '4711', 'objectId' => self::MEETING, 'schema' => 'meeting']);

		$this->assertSame([4711], $this->lookedUp);
		$this->assertCount(1, $this->saved);
		$this->assertSame('meeting', $this->saved[0][0]);
		$entry = $this->saved[0][1]['paperRenditions'][0];
		$this->assertSame(88, $entry['pdfFileId']);
		$this->assertSame('', $entry['backend']);
	}//end testTheQueuedArgumentIsConvertedForItsMeeting()

	/**
	 * A non-array argument does nothing at all.
	 *
	 * @return void
	 */
	public function testANonArrayArgumentDoesNothing(): void {
		$job = $this->job($this->paper(), [], $this->stored([]));

		(new ReflectionMethod($job, 'run'))->invoke($job, 'fileId=4711');

		$this->assertSame([], $this->lookedUp);
		$this->assertSame([], $this->saved);
	}//end testANonArrayArgumentDoesNothing()

	/**
	 * A missing file id, object id or a foreign schema is refused before any lookup.
	 *
	 * @return void
	 */
	public function testIncompleteOrForeignInputIsRefusedBeforeAnyLookup(): void {
		$job = $this->job($this->paper(), [], $this->stored([]));

		$job->convert(0, self::MEETING, 'meeting');
		$job->convert(4711, '', 'meeting');
		$job->convert(4711, self::MEETING, 'motion');

		$this->assertSame([], $this->lookedUp);
		$this->assertCount(0, $this->conversions);
	}//end testIncompleteOrForeignInputIsRefusedBeforeAnyLookup()

	/**
	 * A file id that is not a file (a folder, or nothing) is not converted.
	 *
	 * @return void
	 */
	public function testAFileIdThatIsNotAFileIsNotConverted(): void {
		$this->job($this->createMock(Folder::class), [], $this->stored([]))->convert(4711, self::MEETING, 'meeting');
		$this->job(null, [], $this->stored([]))->convert(4711, self::MEETING, 'meeting');

		$this->assertSame([4711, 4711], $this->lookedUp);
		$this->assertCount(0, $this->conversions);
		$this->assertSame([], $this->saved);
	}//end testAFileIdThatIsNotAFileIsNotConverted()

	/**
	 * A converter that hands back no file records a broken-off conversion,
	 * and an unreadable folder does not block the attempt.
	 *
	 * @return void
	 */
	public function testAConversionWithoutAFileIsRecordedAsBrokenOff(): void {
		$this->job($this->paperInUnreadableFolder(), ['backend' => 'office'], $this->stored(['id' => self::MEETING, 'paperRenditions' => ['not-an-entry']]))
			->convert(4711, self::MEETING, 'meeting');

		$this->assertSame(['Raadsvoorstel.docx'], $this->conversions->getArrayCopy());
		$renditions = $this->saved[0][1]['paperRenditions'];
		$this->assertCount(1, $renditions);
		$this->assertSame(ConvertPaperToPdfJob::BROKE_OFF, $renditions[0]['failure']);
		$this->assertArrayHasKey('failedAt', $renditions[0]);
		$this->assertArrayNotHasKey('pdfFileId', $renditions[0]);
		$this->assertSame(['Decidiq: an Office paper was not converted to PDF'], $this->warnings);
	}//end testAConversionWithoutAFileIsRecordedAsBrokenOff()

	/**
	 * When the object is gone nothing is saved.
	 *
	 * @return void
	 */
	public function testAMissingObjectIsNotWritten(): void {
		$this->job($this->paper(), ['backend' => 'office'], null)->convert(4711, self::MEETING, 'meeting');

		$this->assertCount(1, $this->conversions);
		$this->assertSame([], $this->saved);
	}//end testAMissingObjectIsNotWritten()

	/**
	 * A register failure while recording is logged, not thrown.
	 *
	 * @return void
	 */
	public function testARecordingFailureIsLoggedNotThrown(): void {
		$this->job($this->paper(), ['backend' => 'office'], 'throw')->convert(4711, self::MEETING, 'meeting');

		$this->assertSame([], $this->saved);
		$this->assertSame(
			['Decidiq: an Office paper was not converted to PDF', 'Decidiq: the PDF of an Office paper could not be recorded'],
			$this->warnings
		);
	}//end testARecordingFailureIsLoggedNotThrown()
}//end class
