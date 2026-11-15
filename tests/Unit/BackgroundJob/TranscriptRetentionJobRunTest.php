<?php

/**
 * TranscriptRetentionJob run and edge-case unit tests.
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

use DateTimeImmutable;
use OCA\Decidiq\BackgroundJob\TranscriptRetentionJob;
use OCA\Decidiq\Kernel\Contracts\AuditLogServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The daily run, and the cases where retention leaves a transcript alone or
 * degrades gracefully: missing relations, unapproved minutes, bad policy
 * values, failed deletes and a failed audit append.
 *
 * @covers \OCA\Decidiq\BackgroundJob\TranscriptRetentionJob
 */
class TranscriptRetentionJobRunTest extends TestCase {

	/**
	 * The DI container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The object service.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Objects find() returns, keyed by schema then id.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	private array $objects = [];

	/**
	 * Minutes findAll() returns.
	 *
	 * @var array<int,mixed>
	 */
	private array $minutes = [];

	/**
	 * File paths whose delete fails.
	 *
	 * @var string[]
	 */
	private array $failingPaths = [];

	/**
	 * Base names of deleted files.
	 *
	 * @var string[]
	 */
	private array $deleted = [];

	/**
	 * Saved objects with their uuid.
	 *
	 * @var array<int,array{object:array<string,mixed>,uuid:?string}>
	 */
	private array $saved = [];

	/**
	 * Audit appends.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $audits = [];

	/**
	 * Whether the audit append throws.
	 *
	 * @var boolean
	 */
	private bool $auditFails = false;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container     = $this->createMock(ContainerInterface::class);
		$this->logger        = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objects       = [
			'meeting'         => ['m1' => ['id' => 'm1', 'governanceBody' => 'b1']],
			'governance-body' => ['b1' => ['id' => 'b1', 'transcriptRetentionPolicy' => 'delete-both', 'transcriptRetentionDays' => 30]],
		];
		$this->minutes       = [$this->entity(['lifecycle' => 'approved', 'approvedAt' => '2026-01-01T00:00:00Z'])];
		$this->failingPaths  = [];
		$this->deleted       = [];
		$this->saved         = [];
		$this->audits        = [];
		$this->auditFails    = false;

		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ?ObjectEntity {
				if ($id === 'boom') {
					throw new RuntimeException('lookup failed');
				}

				$data = ($this->objects[$schema][$id] ?? null);
				if ($data === null) {
					return null;
				}

				return $this->entity($data);
			}
		);
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				if (($config['schema'] ?? '') === 'minutes') {
					return $this->minutes;
				}

				return [];
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntity {
				$this->saved[] = ['object' => $object, 'uuid' => $uuid];
				return $this->entity($object);
			}
		);

		$this->wireContainer();
	}//end setUp()

	/**
	 * Wire the container to the object, file and audit services.
	 *
	 * @return void
	 */
	private function wireContainer(): void {
		$fileService = $this->getMockBuilder(\stdClass::class)->addMethods(['createFolder'])->getMock();
		$fileService->method('createFolder')->willReturnCallback(
			function (string $dir) {
				$folder = $this->getMockBuilder(\stdClass::class)->addMethods(['get'])->getMock();
				$folder->method('get')->willReturnCallback(
					function (string $name) use ($dir) {
						if (in_array($dir . '/' . $name, $this->failingPaths, true) === true) {
							throw new RuntimeException('not found');
						}

						$node = $this->getMockBuilder(\stdClass::class)->addMethods(['delete'])->getMock();
						$node->method('delete')->willReturnCallback(
							function () use ($name): void {
								$this->deleted[] = $name;
							}
						);
						return $node;
					}
				);
				return $folder;
			}
		);

		$audit = $this->getMockBuilder(\stdClass::class)->addMethods(['append'])->getMock();
		$audit->method('append')->willReturnCallback(
			function (...$args): array {
				if ($this->auditFails === true) {
					throw new RuntimeException('audit store down');
				}

				$this->audits[] = $args;
				return [];
			}
		);

		$this->container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				'OCA\OpenRegister\Service\ObjectService' => $this->objectService,
				'OCA\OpenRegister\Service\FileService' => $fileService,
				AuditLogServiceInterface::class => $audit,
				default => throw new RuntimeException('unexpected ' . $id),
			}
		);
	}//end wireContainer()

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
	 * Build the job under test.
	 *
	 * @return TranscriptRetentionJob
	 */
	private function job(): TranscriptRetentionJob {
		return new TranscriptRetentionJob($this->createMock(ITimeFactory::class), $this->container, $this->logger);
	}//end job()

	/**
	 * Enforce retention for one transcript at a fixed moment.
	 *
	 * @param array<string,mixed> $transcript The transcript
	 * @param string              $now        The moment
	 *
	 * @return string The resulting retention state
	 */
	private function enforce(array $transcript, string $now='2026-06-01T00:00:00Z'): string {
		return $this->job()->enforceForTranscript(
			objectService: $this->objectService,
			transcript: $transcript,
			now: new DateTimeImmutable($now)
		);
	}//end enforce()

	/**
	 * A transcript with both files and a meeting relation.
	 *
	 * @param array<string,mixed> $overrides Fields to override
	 *
	 * @return array<string,mixed>
	 */
	private function transcript(array $overrides=[]): array {
		return array_merge(
			[
				'id'                 => 't1',
				'retentionState'     => 'active',
				'sourceFilePath'     => 'Decidiq/m1/recording.mp3',
				'transcriptFilePath' => 'Decidiq/m1/transcript.txt',
				'relations'          => ['meeting' => 'm1'],
			],
			$overrides
		);
	}//end transcript()

	/**
	 * The job runs once a day.
	 *
	 * @return void
	 */
	public function testTheJobRunsDaily(): void {
		self::assertSame(86400, $this->job()->getInterval());
	}//end testTheJobRunsDaily()

	/**
	 * Without OpenRegister the run is skipped with a warning.
	 *
	 * @return void
	 */
	public function testARunWithoutOpenRegisterIsSkipped(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no such service'));
		$this->logger->expects(self::once())->method('warning')->with(
			'Decidiq TranscriptRetentionJob: OpenRegister unavailable, skipping.',
			['exception' => 'no such service']
		);

		$job = new TranscriptRetentionJob($this->createMock(ITimeFactory::class), $container, $this->logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end testARunWithoutOpenRegisterIsSkipped()

	/**
	 * The run reads done transcripts in every entity shape, and one failing transcript does not stop the rest.
	 *
	 * @return void
	 */
	public function testTheRunEnforcesEveryDoneTranscriptAndLogsFailures(): void {
		$withGetObject = new class () {
			/**
			 * The object data.
			 *
			 * @return array<string,mixed>
			 */
			public function getObject(): array {
				return ['id' => 't3', 'relations' => ['meeting' => 'm1']];
			}
		};

		$objectService = $this->createMock(ObjectService::class);
		$queries       = [];
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use (&$queries, $withGetObject): array {
				$queries[] = $config;
				if ($config['schema'] === 'transcript') {
					return [
						['id' => 't1', 'relations' => ['meeting' => 'm1']],
						$this->entity(['id' => 't2', 'relations' => ['meeting' => 'm1']]),
						$withGetObject,
						new \stdClass(),
						'not an object',
					];
				}

				throw new RuntimeException('minutes query failed');
			}
		);
		$objectService->method('find')->willReturnCallback(fn () => $this->entity(['id' => 'm1']));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$this->logger->expects(self::exactly(3))->method('error')->with(
			'Decidiq TranscriptRetentionJob: enforcement failed for a transcript',
			['exception' => 'minutes query failed']
		);

		$job = new TranscriptRetentionJob($this->createMock(ITimeFactory::class), $container, $this->logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		self::assertSame(
			[
				'register' => 'decidiq',
				'schema'   => 'transcript',
				'filters'  => ['register' => 'decidiq', 'schema' => 'transcript', 'status' => 'done'],
			],
			$queries[0]
		);
		self::assertSame('m1', $queries[1]['filters']['_relations.meeting']);
		self::assertCount(4, $queries);
	}//end testTheRunEnforcesEveryDoneTranscriptAndLogsFailures()

	/**
	 * A purged transcript stays purged without any lookup.
	 *
	 * @return void
	 */
	public function testAPurgedTranscriptIsLeftAlone(): void {
		$this->objectService->expects(self::never())->method('find');

		self::assertSame('purged', $this->enforce($this->transcript(['retentionState' => 'purged'])));
	}//end testAPurgedTranscriptIsLeftAlone()

	/**
	 * Without a resolvable meeting the state is kept and nothing is saved.
	 *
	 * @return void
	 */
	public function testATranscriptWithoutAMeetingIsKept(): void {
		self::assertSame('active', $this->enforce($this->transcript(['relations' => []])));
		self::assertSame('active', $this->enforce($this->transcript(['relations' => ['meeting' => ['id' => 'missing']]])));
		self::assertSame('recording-deleted', $this->enforce($this->transcript(['relations' => ['meeting' => ['boom']], 'retentionState' => 'recording-deleted'])));
		self::assertSame([], $this->saved);
		self::assertSame([], $this->deleted);
	}//end testATranscriptWithoutAMeetingIsKept()

	/**
	 * Minutes that are not approved, or lack a valid approval date, do not start the window.
	 *
	 * @return void
	 */
	public function testMinutesWithoutAValidApprovalDoNotStartTheWindow(): void {
		$this->minutes = [
			['lifecycle' => 'draft', 'approvedAt' => '2020-01-01T00:00:00Z'],
			$this->entity(['lifecycle' => 'approved']),
			['lifecycle' => 'signed', 'approvedAt' => 'not a date'],
		];

		self::assertSame('active', $this->enforce($this->transcript(['meeting' => 'm1', 'relations' => null])));
		self::assertSame([], $this->deleted);
	}//end testMinutesWithoutAValidApprovalDoNotStartTheWindow()

	/**
	 * An unknown policy and a negative day count fall back to delete-both after 30 days.
	 *
	 * @return void
	 */
	public function testAnInvalidBodyPolicyFallsBackToTheDefaults(): void {
		$this->objects['meeting']['m1']          = ['id' => 'm1', 'relations' => ['GovernanceBody' => [['id' => 'b1']]]];
		$this->objects['governance-body']['b1'] = ['id' => 'b1', 'transcriptRetentionPolicy' => 'shred', 'transcriptRetentionDays' => -5];

		self::assertSame('active', $this->enforce($this->transcript(), '2026-01-30T00:00:00Z'));
		self::assertSame([], $this->deleted);

		self::assertSame('purged', $this->enforce($this->transcript(), '2026-01-31T00:00:00Z'));
		self::assertSame(['recording.mp3', 'transcript.txt'], $this->deleted);
	}//end testAnInvalidBodyPolicyFallsBackToTheDefaults()

	/**
	 * A meeting without a governance body uses the default policy.
	 *
	 * @return void
	 */
	public function testAMeetingWithoutABodyUsesTheDefaultPolicy(): void {
		$this->objects['meeting']['m1'] = ['id' => 'm1'];

		$transcript = $this->transcript(['@self' => ['id' => 't9']]);
		unset($transcript['id']);

		self::assertSame('purged', $this->enforce($transcript));
		self::assertSame('t9', $this->saved[0]['uuid']);
		self::assertSame('', $this->saved[0]['object']['sourceFilePath']);
		self::assertSame('', $this->saved[0]['object']['transcriptFilePath']);
	}//end testAMeetingWithoutABodyUsesTheDefaultPolicy()

	/**
	 * A recording that cannot be deleted is logged and the transcript is not saved.
	 *
	 * @return void
	 */
	public function testAFailedDeleteIsLoggedAndNothingIsSaved(): void {
		$this->objects['governance-body']['b1']['transcriptRetentionPolicy'] = 'delete-recording';
		$this->failingPaths = ['Decidiq/m1/recording.mp3'];
		$this->logger->expects(self::once())->method('warning')->with(
			'Decidiq TranscriptRetentionJob: file delete failed',
			['path' => 'Decidiq/m1/recording.mp3', 'error' => 'not found']
		);

		self::assertSame('active', $this->enforce($this->transcript()));
		self::assertSame([], $this->saved);
		self::assertSame([], $this->audits);
	}//end testAFailedDeleteIsLoggedAndNothingIsSaved()

	/**
	 * A transcript whose recording is already gone only loses its text file under delete-both.
	 *
	 * @return void
	 */
	public function testARecordingDeletedTranscriptLosesOnlyItsText(): void {
		$state = $this->enforce($this->transcript(['retentionState' => 'recording-deleted', 'sourceFilePath' => '']));

		self::assertSame('purged', $state);
		self::assertSame(['transcript.txt'], $this->deleted);
		self::assertSame('purged', $this->saved[0]['object']['retentionState']);
		self::assertSame(
			['system:retention', 'transcript-retention-purge', ['m1'], ['policy' => 'delete-both', 'deletedFiles' => ['Decidiq/m1/transcript.txt']]],
			$this->audits[0]
		);
	}//end testARecordingDeletedTranscriptLosesOnlyItsText()

	/**
	 * A failed audit append is logged; the purge itself still stands.
	 *
	 * @return void
	 */
	public function testAFailedAuditIsLogged(): void {
		$this->auditFails = true;
		$this->logger->expects(self::once())->method('warning')->with(
			'Decidiq TranscriptRetentionJob: audit append failed',
			['meetingId' => 'm1', 'error' => 'audit store down']
		);

		self::assertSame('purged', $this->enforce($this->transcript()));
		self::assertCount(1, $this->saved);
	}//end testAFailedAuditIsLogged()
}//end class
