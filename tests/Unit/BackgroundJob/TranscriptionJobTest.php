<?php

/**
 * TranscriptionJob unit tests.
 *
 * @category Tests
 * @package  OCA\Decidiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\BackgroundJob;

use OCA\Decidiq\BackgroundJob\TranscriptionJob;
use OCA\Decidiq\Service\TranscriptionService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The queued job transcribes the transcript it names, and skips one it does not.
 *
 * @covers \OCA\Decidiq\BackgroundJob\TranscriptionJob
 */
class TranscriptionJobTest extends TestCase {

	/**
	 * The transcription service.
	 *
	 * @var TranscriptionService|MockObject
	 */
	private TranscriptionService $service;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->createMock(TranscriptionService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Run the job with an argument.
	 *
	 * @param mixed $argument The job argument
	 *
	 * @return void
	 */
	private function runJob(mixed $argument): void {
		$job = new TranscriptionJob(time: $this->createMock(ITimeFactory::class), transcriptionService: $this->service, logger: $this->logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}//end runJob()

	/**
	 * The named transcript is processed.
	 *
	 * @return void
	 */
	public function testTheNamedTranscriptIsProcessed(): void {
		$this->service->expects(self::once())->method('process')->with('transcript-1')->willReturn([]);
		$this->logger->expects(self::never())->method('warning');

		$this->runJob(['transcriptId' => 'transcript-1']);
	}//end testTheNamedTranscriptIsProcessed()

	/**
	 * Without a transcript id nothing is processed, and the skip is logged.
	 *
	 * @return void
	 */
	public function testAMissingTranscriptIsSkipped(): void {
		$this->service->expects(self::never())->method('process');
		$this->logger->expects(self::exactly(3))->method('warning')->with('Decidiq TranscriptionJob: missing transcriptId argument, skipping.');

		$this->runJob([]);
		$this->runJob(['transcriptId' => '']);
		$this->runJob('transcript-1');
	}//end testAMissingTranscriptIsSkipped()

	/**
	 * A failed run is logged with the transcript, not thrown.
	 *
	 * @return void
	 */
	public function testAFailedRunIsLogged(): void {
		$this->service->method('process')->willThrowException(new RuntimeException('no audio'));
		$this->logger->expects(self::once())->method('error')->with('Decidiq TranscriptionJob: transcription run failed', ['transcriptId' => 'transcript-1', 'exception' => 'no audio']);

		$this->runJob(['transcriptId' => 'transcript-1']);
	}//end testAFailedRunIsLogged()
}//end class
