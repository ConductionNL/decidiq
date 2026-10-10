<?php

/**
 * Tests for the notice that follows a queued PDF export.
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

use OCA\Decidiq\BackgroundJob\ExportBundleNoticeJob;
use OCA\Decidiq\Service\ExportBundleWriter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\BackgroundJob\ExportBundleNoticeJob
 * @uses   \OCA\Decidiq\Support\FleetAppId
 *
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-003-a-large-export-runs-in-the-background-and-says-when-it-is-ready
 */
class ExportBundleNoticeJobTest extends TestCase {

	/**
	 * Jobs added again.
	 *
	 * @var list<array{0:string,1:mixed}>
	 */
	private array $added = [];

	/**
	 * Notifications sent: [subject, parameters, user].
	 *
	 * @var list<array{0:string,1:array,2:string}>
	 */
	private array $sent = [];

	/**
	 * Text pages removed.
	 *
	 * @var list<int>
	 */
	private array $removed = [];

	/**
	 * Run the job once against a merge job in $status.
	 *
	 * @param array<string,mixed>|null $mergeJob filinq's job, null when the store is missing.
	 * @param int                      $attempt  The attempt number.
	 *
	 * @return void
	 */
	private function runWith(?array $mergeJob, int $attempt=0): void {
		$repository = new class ($mergeJob) {
			/**
			 * Constructor.
			 *
			 * @param array|null $job The job.
			 */
			public function __construct(private ?array $job) {
			}//end __construct()

			/**
			 * Find a job.
			 *
			 * @param string $uuid The job.
			 *
			 * @return array|null
			 */
			public function find(string $uuid): ?array {
				return $this->job;
			}//end find()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($repository): object {
				if ($id === 'OCA\Filinq\Service\MergeJobRepository') {
					return $repository;
				}

				throw new class ('no ' . $id) extends RuntimeException implements NotFoundExceptionInterface {
				};
			}
		);

		$exports = $this->createMock(ExportBundleWriter::class);
		$exports->method('removePages')->willReturnCallback(function (string $uid, array $pages): void {
			$this->removed = [...$this->removed, ...$pages];
		});

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(function (mixed $job, mixed $argument=null): void {
			$this->added[] = [(string)$job, $argument];
		});

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturnCallback(function (): INotification {
			$n = $this->createMock(INotification::class);
			$state = new \ArrayObject();
			foreach (['setApp', 'setDateTime', 'setObject'] as $m) {
				$n->method($m)->willReturnSelf();
			}

			$n->method('setUser')->willReturnCallback(function (string $u) use ($n, $state): INotification {
				$state['user'] = $u;
				return $n;
			});
			$n->method('setSubject')->willReturnCallback(function (string $s, array $p=[]) use ($n, $state): INotification {
				$state['subject'] = $s;
				$state['params'] = $p;
				return $n;
			});
			$n->method('getUser')->willReturnCallback(fn (): string => (string)($state['user'] ?? ''));
			$n->method('getSubject')->willReturnCallback(fn (): string => (string)($state['subject'] ?? ''));
			$n->method('getSubjectParameters')->willReturnCallback(fn (): array => (array)($state['params'] ?? []));
			return $n;
		});
		$notifications->method('notify')->willReturnCallback(function (INotification $n): void {
			$this->sent[] = [$n->getSubject(), $n->getSubjectParameters(), $n->getUser()];
		});

		$job = new ExportBundleNoticeJob(
			$this->createMock(ITimeFactory::class),
			$container,
			$exports,
			$jobList,
			$notifications,
			$this->createMock(LoggerInterface::class),
		);

		$run = new ReflectionMethod($job, 'run');
		$run->invoke($job, ['mergeJob' => 'job-2', 'uid' => 'griffier', 'name' => 'Motions 2026-10-20.pdf', 'pages' => [11, 12], 'attempt' => $attempt]);
	}//end runWith()

	/**
	 * A finished merge: the clerk is told, with the file, and the text pages go.
	 *
	 * @return void
	 */
	public function testAFinishedMergeTellsTheClerkWhereTheFileIs(): void {
		$this->runWith(['status' => 'done', 'resultFileId' => 777]);

		self::assertSame([['export_bundle_ready', ['title' => 'Motions 2026-10-20.pdf', 'fileId' => '777'], 'griffier']], $this->sent);
		self::assertSame([11, 12], $this->removed);
		self::assertSame([], $this->added);
	}//end testAFinishedMergeTellsTheClerkWhereTheFileIs()

	/**
	 * A merge still waiting: the job looks again later, and the pages stay.
	 *
	 * @return void
	 */
	public function testAWaitingMergeIsLookedAtAgain(): void {
		$this->runWith(['status' => 'running'], 3);

		self::assertSame([], $this->sent);
		self::assertSame([], $this->removed);
		self::assertSame(ExportBundleNoticeJob::class, $this->added[0][0]);
		self::assertSame(4, $this->added[0][1]['attempt']);
	}//end testAWaitingMergeIsLookedAtAgain()

	/**
	 * A failed merge, or one that never finishes, ends in the failed notice.
	 *
	 * @return void
	 */
	public function testAFailedOrStuckMergeEndsInTheFailedNotice(): void {
		$this->runWith(['status' => 'failed']);
		$this->runWith(['status' => 'queued'], ExportBundleNoticeJob::MAX_ATTEMPTS);

		self::assertSame(['export_bundle_failed', 'export_bundle_failed'], array_column($this->sent, 0));
		self::assertSame([], $this->added);
	}//end testAFailedOrStuckMergeEndsInTheFailedNotice()
}//end class
