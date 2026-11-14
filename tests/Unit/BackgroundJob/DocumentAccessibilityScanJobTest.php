<?php

/**
 * Unit tests for DocumentAccessibilityScanJob.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\BackgroundJob
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

namespace OCA\Decidiq\Tests\Unit\BackgroundJob;

use OCA\Decidiq\BackgroundJob\DocumentAccessibilityScanJob;
use OCA\Decidiq\Service\DocumentAccessibilityScanService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The queued scan stores a report naming the paper's governance body.
 *
 * @covers \OCA\Decidiq\BackgroundJob\DocumentAccessibilityScanJob
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#scenario-scan-on-upload-queues-a-background-scan
 */
class DocumentAccessibilityScanJobTest extends TestCase {

	/**
	 * The scanner double.
	 *
	 * @var DocumentAccessibilityScanService&MockObject
	 */
	private DocumentAccessibilityScanService&MockObject $scanner;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The job over a register holding meeting-1 of body-raad.
	 *
	 * @return DocumentAccessibilityScanJob
	 */
	private function job(): DocumentAccessibilityScanJob {
		$this->scanner = $this->createMock(originalClassName: DocumentAccessibilityScanService::class);
		$this->logger  = $this->createMock(originalClassName: LoggerInterface::class);
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id): ?ObjectEntity {
				if ($id !== 'meeting-1') {
					throw new RuntimeException('not found');
				}

				$meeting = $this->createMock(originalClassName: ObjectEntity::class);
				$meeting->method('getObject')->willReturn(['id' => 'meeting-1', 'governanceBody' => 'body-raad']);
				return $meeting;
			}
		);

		return new DocumentAccessibilityScanJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			scanner: $this->scanner,
			objectService: $objectService,
			logger: $this->logger
		);
	}//end job()

	/**
	 * An agenda item's paper is scanned with the body of its meeting.
	 *
	 * @return void
	 */
	public function testAnAgendaItemPaperIsScannedWithItsMeetingsBody(): void {
		$job = $this->job();
		$this->scanner->expects(self::once())
			->method('scanFile')
			->with(4711, 'item-1', 'agenda-item', 'body-raad')
			->willReturn(['status' => 'fail']);

		self::assertSame(
			expected: ['status' => 'fail'],
			actual: $job->scan(argument: ['fileId' => 4711, 'objectId' => 'item-1', 'schema' => 'agenda-item', 'meeting' => 'meeting-1'])
		);
	}//end testAnAgendaItemPaperIsScannedWithItsMeetingsBody()

	/**
	 * A meeting's paper carries its body; an unknown meeting leaves the body out.
	 *
	 * @return void
	 */
	public function testTheBodyComesFromTheArgumentOrIsLeftOut(): void {
		$job   = $this->job();
		$calls = [];
		$this->scanner->method('scanFile')->willReturnCallback(
			static function (int $fileId, string $sourceObject, string $sourceSchema, ?string $governanceBody=null) use (&$calls): array {
				$calls[] = [$fileId, $sourceObject, $sourceSchema, $governanceBody];
				return ['status' => 'pass'];
			}
		);

		$job->scan(argument: ['fileId' => 1, 'objectId' => 'meeting-1', 'schema' => 'meeting', 'governanceBody' => 'body-rvc']);
		$job->scan(argument: ['fileId' => 2, 'objectId' => 'item-2', 'schema' => 'agenda-item', 'meeting' => 'meeting-gone']);
		$job->scan(argument: ['fileId' => 3, 'objectId' => 'item-3', 'schema' => 'agenda-item']);

		self::assertSame(
			expected: [
				[1, 'meeting-1', 'meeting', 'body-rvc'],
				[2, 'item-2', 'agenda-item', null],
				[3, 'item-3', 'agenda-item', null],
			],
			actual: $calls
		);
	}//end testTheBodyComesFromTheArgumentOrIsLeftOut()

	/**
	 * A malformed argument scans nothing.
	 *
	 * @return void
	 */
	public function testAMalformedArgumentScansNothing(): void {
		$job = $this->job();
		$this->scanner->expects(self::never())->method('scanFile');

		self::assertNull(actual: $job->scan(argument: ['fileId' => 0, 'objectId' => 'item-1', 'schema' => 'agenda-item']));
		self::assertNull(actual: $job->scan(argument: ['fileId' => 5, 'objectId' => '', 'schema' => 'agenda-item']));
		self::assertNull(actual: $job->scan(argument: ['fileId' => 5, 'objectId' => 'd-1', 'schema' => 'decision']));
	}//end testAMalformedArgumentScansNothing()

	/**
	 * A scan that breaks off is logged and the job does not fail.
	 *
	 * @return void
	 */
	public function testABrokenScanIsLoggedNotThrown(): void {
		$job = $this->job();
		$this->scanner->method('scanFile')->willThrowException(new RuntimeException('disk gone'));
		$this->logger->expects(self::once())
			->method('warning')
			->with('Decidiq: the accessibility scan of a paper broke off', ['fileId' => 9, 'error' => 'disk gone']);

		self::assertNull(actual: $job->scan(argument: ['fileId' => 9, 'objectId' => 'meeting-1', 'schema' => 'meeting']));
	}//end testABrokenScanIsLoggedNotThrown()
}//end class
