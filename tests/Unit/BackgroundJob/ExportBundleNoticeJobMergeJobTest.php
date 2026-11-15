<?php

/**
 * Tests: how the export notice reads filinq's merge job when it is absent, unnamed or unreadable.
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
class ExportBundleNoticeJobMergeJobTest extends TestCase {

	/**
	 * Jobs queued again: [class, argument].
	 *
	 * @var list<array{0: string, 1: mixed}>
	 */
	private array $added = [];

	/**
	 * Subjects of the notices sent.
	 *
	 * @var list<string>
	 */
	private array $subjects = [];

	/**
	 * Times the text pages were removed.
	 *
	 * @var int
	 */
	private int $removals = 0;

	/**
	 * Merge job uuids asked of the repository.
	 *
	 * @var \ArrayObject<int, string>
	 */
	private \ArrayObject $asked;

	/**
	 * Warnings logged.
	 *
	 * @var list<string>
	 */
	private array $warnings = [];

	/**
	 * Fresh recorders per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->asked = new \ArrayObject();
	}//end setUp()

	/**
	 * Run the job once.
	 *
	 * @param string $mergeJob   The merge job uuid in the argument.
	 * @param string $repository 'present', 'absent' or 'broken'.
	 *
	 * @return void
	 */
	private function runWith(string $mergeJob, string $repository): void {
		$store = new class($this->asked, $repository === 'broken') {
			/**
			 * @param \ArrayObject<int, string> $asked  Uuids asked.
			 * @param bool                      $broken Whether find() throws.
			 */
			public function __construct(private \ArrayObject $asked, private bool $broken) {
			}

			/**
			 * Find a merge job.
			 *
			 * @param string $uuid The job.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $uuid): ?array {
				$this->asked[] = $uuid;
				if ($this->broken === true) {
					throw new RuntimeException('database gone away');
				}

				return ['status' => 'done', 'resultFileId' => 900];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($store, $repository): object {
			if ($repository !== 'absent') {
				return $store;
			}

			throw new class ('no ' . $id) extends RuntimeException implements NotFoundExceptionInterface {
			};
		});

		$exports = $this->createMock(ExportBundleWriter::class);
		$exports->method('removePages')->willReturnCallback(function (): void {
			$this->removals++;
		});

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(function (mixed $job, mixed $argument=null): void {
			$this->added[] = [(string)$job, $argument];
		});

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturnCallback(function (): INotification {
			$n = $this->createMock(INotification::class);
			foreach (['setApp', 'setUser', 'setDateTime', 'setObject'] as $m) {
				$n->method($m)->willReturnSelf();
			}

			$n->method('setSubject')->willReturnCallback(function (string $subject) use ($n): INotification {
				$this->subjects[] = $subject;
				return $n;
			});
			return $n;
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string|\Stringable $message): void {
			$this->warnings[] = (string)$message;
		});

		$job = new ExportBundleNoticeJob($this->createMock(ITimeFactory::class), $container, $exports, $jobList, $notifications, $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, ['mergeJob' => $mergeJob, 'uid' => 'griffier', 'name' => 'Moties.pdf', 'pages' => [3], 'attempt' => 5]);
	}//end runWith()

	/**
	 * Without a merge job uuid the store is not asked and the export fails.
	 *
	 * @return void
	 */
	public function testAnUnnamedMergeJobIsNotLookedUpAndFails(): void {
		$this->runWith('', 'present');

		$this->assertCount(0, $this->asked);
		$this->assertSame(['export_bundle_failed'], $this->subjects);
		$this->assertSame(1, $this->removals);
		$this->assertSame([], $this->added);
	}//end testAnUnnamedMergeJobIsNotLookedUpAndFails()

	/**
	 * Without filinq the merge cannot be read and the export fails.
	 *
	 * @return void
	 */
	public function testWithoutFilinqTheExportFails(): void {
		$this->runWith('job-7', 'absent');

		$this->assertSame(['export_bundle_failed'], $this->subjects);
		$this->assertSame([], $this->added);
	}//end testWithoutFilinqTheExportFails()

	/**
	 * A store that throws is logged and treated as still queued: look again,
	 * no notice yet, the text pages stay.
	 *
	 * @return void
	 */
	public function testAnUnreadableMergeJobIsLookedAtAgain(): void {
		$this->runWith('job-7', 'broken');

		$this->assertSame(['job-7'], $this->asked->getArrayCopy());
		$this->assertSame(['Decidiq export: the merge job could not be read'], $this->warnings);
		$this->assertSame([], $this->subjects);
		$this->assertSame(0, $this->removals);
		$this->assertCount(1, $this->added);
		$this->assertSame(ExportBundleNoticeJob::class, $this->added[0][0]);
		$this->assertSame(6, $this->added[0][1]['attempt']);
	}//end testAnUnreadableMergeJobIsLookedAtAgain()

	/**
	 * A readable done job notifies ready (control for the cases above).
	 *
	 * @return void
	 */
	public function testAReadableFinishedJobIsReady(): void {
		$this->runWith('job-7', 'present');

		$this->assertSame(['job-7'], $this->asked->getArrayCopy());
		$this->assertSame(['export_bundle_ready'], $this->subjects);
	}//end testAReadableFinishedJobIsReady()
}//end class
