<?php

/**
 * MailReplyHandler unit tests.
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

use OCA\Decidiq\BackgroundJob\MailReplyHandler;
use OCA\Decidiq\Service\MailVoteReplyProcessor;
use OCA\Decidiq\Service\MailVoteSigner;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The timed job processes open voting rounds only when vote-by-email is on,
 * and delegates signing and keyword parsing to its collaborators.
 *
 * @covers \OCA\Decidiq\BackgroundJob\MailReplyHandler
 */
class MailReplyHandlerTest extends TestCase {

	/**
	 * The app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The signer.
	 *
	 * @var MailVoteSigner&MockObject
	 */
	private MailVoteSigner $signer;

	/**
	 * The reply processor.
	 *
	 * @var MailVoteReplyProcessor&MockObject
	 */
	private MailVoteReplyProcessor $processor;

	/**
	 * The object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig     = $this->createMock(IAppConfig::class);
		$this->logger        = $this->createMock(LoggerInterface::class);
		$this->signer        = $this->createMock(MailVoteSigner::class);
		$this->processor     = $this->createMock(MailVoteReplyProcessor::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
	}//end setUp()

	/**
	 * Build the job under test.
	 *
	 * @return MailReplyHandler
	 */
	private function job(): MailReplyHandler {
		return new MailReplyHandler(
			time: $this->createMock(ITimeFactory::class),
			appConfig: $this->appConfig,
			logger: $this->logger,
			signer: $this->signer,
			processor: $this->processor,
			objectService: $this->objectService,
		);
	}//end job()

	/**
	 * Run the job.
	 *
	 * @param MailReplyHandler $job The job
	 *
	 * @return void
	 */
	private function runJob(MailReplyHandler $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * Build an ObjectEntity double serialising to the given data.
	 *
	 * @param array<string,mixed> $data The data
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * The job is scheduled every five minutes.
	 *
	 * @return void
	 */
	public function testTheJobRunsEveryFiveMinutes(): void {
		self::assertSame(300, $this->job()->getInterval());
	}//end testTheJobRunsEveryFiveMinutes()

	/**
	 * With vote-by-email off nothing is read or processed.
	 *
	 * @return void
	 */
	public function testNothingRunsWhileEmailVotingIsOff(): void {
		$this->appConfig->expects(self::once())->method('getValueString')
			->with('decidiq', 'email_voting_enabled', '0')
			->willReturn('0');
		$this->objectService->expects(self::never())->method('findAll');
		$this->processor->expects(self::never())->method('processRound');

		$this->runJob($this->job());
	}//end testNothingRunsWhileEmailVotingIsOff()

	/**
	 * Open rounds are processed by uuid, falling back to id, and rounds without either are skipped.
	 *
	 * @return void
	 */
	public function testOpenRoundsAreProcessedByTheirId(): void {
		$this->appConfig->method('getValueString')->willReturn('1');
		$this->objectService->expects(self::once())->method('setRegister')->with('decidiq');
		$this->objectService->expects(self::once())->method('setSchema')->with('voting-round');
		$this->objectService->expects(self::once())->method('findAll')
			->with(['filters' => ['closedAt' => null, 'openedAt' => ['!=' => null]]])
			->willReturn(
				[
					$this->entity(['uuid' => 'round-uuid', 'id' => 'ignored']),
					$this->entity(['id' => 'round-id']),
					$this->entity(['title' => 'no id']),
				]
			);

		$seen = [];
		$this->processor->expects(self::exactly(2))->method('processRound')->willReturnCallback(
			function (object $objectService, array $round, string $roundId) use (&$seen): void {
				self::assertSame($this->objectService, $objectService);
				$seen[] = [$roundId, $round];
			}
		);
		$this->logger->expects(self::never())->method('error');

		$this->runJob($this->job());

		self::assertSame(
			[
				['round-uuid', ['uuid' => 'round-uuid', 'id' => 'ignored']],
				['round-id', ['id' => 'round-id']],
			],
			$seen
		);
	}//end testOpenRoundsAreProcessedByTheirId()

	/**
	 * A failure while processing is logged, not thrown.
	 *
	 * @return void
	 */
	public function testAFailureIsLogged(): void {
		$this->appConfig->method('getValueString')->willReturn('1');
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('register down'));
		$this->logger->expects(self::once())->method('error')
			->with('Decidiq: MailReplyHandler failed', ['error' => 'register down']);

		$this->runJob($this->job());
	}//end testAFailureIsLogged()

	/**
	 * The HMAC is computed by the signer with the same arguments.
	 *
	 * @return void
	 */
	public function testComputeMailHmacDelegatesToTheSigner(): void {
		$this->signer->expects(self::once())->method('compute')
			->with('participant-1', 'round-1', '2026-01-01T00:00:00Z')
			->willReturn('abc123');

		self::assertSame('abc123', $this->job()->computeMailHmac('participant-1', 'round-1', '2026-01-01T00:00:00Z'));
	}//end testComputeMailHmacDelegatesToTheSigner()

	/**
	 * A mail entry is signed by the signer and returned as it signed it.
	 *
	 * @return void
	 */
	public function testSignMailEntryReturnsTheSignedEntry(): void {
		$entry = ['participantId' => 'participant-1', 'timestamp' => '2026-01-01T00:00:00Z'];
		$this->signer->expects(self::once())->method('sign')
			->with($entry, 'round-1')
			->willReturn($entry + ['hmac' => 'abc123']);

		self::assertSame($entry + ['hmac' => 'abc123'], $this->job()->signMailEntry($entry, 'round-1'));
	}//end testSignMailEntryReturnsTheSignedEntry()

	/**
	 * Keyword parsing is the processor's answer, including no answer.
	 *
	 * @return void
	 */
	public function testParseVoteKeywordDelegatesToTheProcessor(): void {
		$this->processor->method('parseVoteKeyword')->willReturnMap(
			[
				["Voor\n\nGroet", 'for'],
				['maybe', null],
			]
		);

		$job = $this->job();
		self::assertSame('for', $job->parseVoteKeyword("Voor\n\nGroet"));
		self::assertNull($job->parseVoteKeyword('maybe'));
	}//end testParseVoteKeywordDelegatesToTheProcessor()
}//end class
