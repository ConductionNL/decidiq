<?php

/**
 * SendMeetingFileJob unit tests.
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

use OCA\Decidiq\BackgroundJob\SendMeetingFileJob;
use OCA\Decidiq\Service\CaseSystemExchangeService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The queued job resends a failed record, or sends a meeting's file, never both.
 *
 * @covers \OCA\Decidiq\BackgroundJob\SendMeetingFileJob
 */
class SendMeetingFileJobTest extends TestCase {

	/**
	 * The case system exchange.
	 *
	 * @var CaseSystemExchangeService|MockObject
	 */
	private CaseSystemExchangeService $exchange;

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
		$this->exchange = $this->createMock(CaseSystemExchangeService::class);
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
		$job = new SendMeetingFileJob(time: $this->createMock(ITimeFactory::class), exchange: $this->exchange, logger: $this->logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}//end runJob()

	/**
	 * A record is resent, and the meeting is not sent as well.
	 *
	 * @return void
	 */
	public function testARecordIsResent(): void {
		$this->exchange->expects(self::once())->method('resend')->with('record-1')->willReturn(['id' => 'record-1']);
		$this->exchange->expects(self::never())->method('send');
		$this->logger->expects(self::once())->method('info')->with('Decidiq: failed case system documents sent again', ['record' => 'record-1']);

		$this->runJob(['record' => 'record-1', 'meeting' => 'meeting-1']);
	}//end testARecordIsResent()

	/**
	 * A meeting's file is sent as the named user, else as the system.
	 *
	 * @return void
	 */
	public function testAMeetingFileIsSent(): void {
		$sent = [];
		$this->exchange->expects(self::exactly(2))->method('send')->willReturnCallback(
			static function (string $meetingId, string $userId) use (&$sent): array {
				$sent[] = [$meetingId, $userId];

				return [['id' => 'a'], ['id' => 'b']];
			}
		);
		$this->logger->expects(self::exactly(2))->method('info')->with('Decidiq: meeting file sent to the case system', ['meeting' => 'meeting-1', 'records' => 2]);

		$this->runJob(['meeting' => 'meeting-1', 'uid' => 'griffier']);
		$this->runJob(['meeting' => 'meeting-1']);

		self::assertSame([['meeting-1', 'griffier'], ['meeting-1', 'system']], $sent);
	}//end testAMeetingFileIsSent()

	/**
	 * Without a record or meeting nothing is sent or logged.
	 *
	 * @return void
	 */
	public function testNothingNamedSendsNothing(): void {
		$this->exchange->expects(self::never())->method('resend');
		$this->exchange->expects(self::never())->method('send');
		$this->logger->expects(self::never())->method('info');

		$this->runJob(['record' => '', 'meeting' => '']);
		$this->runJob(null);
	}//end testNothingNamedSendsNothing()

	/**
	 * A failure is logged with the exception, not thrown.
	 *
	 * @return void
	 */
	public function testAFailureIsLogged(): void {
		$failure = new RuntimeException('case system down');
		$this->exchange->method('send')->willThrowException($failure);
		$this->logger->expects(self::once())->method('error')->with('Decidiq: sending the meeting file to the case system failed', ['exception' => $failure]);

		$this->runJob(['meeting' => 'meeting-1']);
	}//end testAFailureIsLogged()
}//end class
