<?php

/**
 * Tests: the full data export is built once and the administrator is told the outcome.
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

use OCA\Decidiq\BackgroundJob\FullExportJob;
use OCA\Decidiq\Service\FullExportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\BackgroundJob\FullExportJob
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
 */
class FullExportJobTest extends TestCase {

	/**
	 * Notifications sent: app, user, object type, object id, subject, parameters.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $sent = [];

	/**
	 * Messages logged at error level.
	 *
	 * @var list<string>
	 */
	private array $errors = [];

	/**
	 * How often the export was built.
	 *
	 * @var int
	 */
	private int $builds = 0;

	/**
	 * Run the job once.
	 *
	 * @param mixed                   $argument The job argument.
	 * @param string|RuntimeException $outcome  The archive name build() returns, or what it throws.
	 *
	 * @return void
	 */
	private function runWith(mixed $argument, string|RuntimeException $outcome): void {
		$export = $this->createMock(FullExportService::class);
		$export->method('build')->willReturnCallback(function () use ($outcome): string {
			$this->builds++;
			if ($outcome instanceof RuntimeException) {
				throw $outcome;
			}

			return $outcome;
		});

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturnCallback(function (): INotification {
			$state = new \ArrayObject();
			$n     = $this->createMock(INotification::class);
			$n->method('setDateTime')->willReturnSelf();
			$n->method('setApp')->willReturnCallback(function (string $app) use ($n, $state): INotification {
				$state['app'] = $app;
				return $n;
			});
			$n->method('setUser')->willReturnCallback(function (string $user) use ($n, $state): INotification {
				$state['user'] = $user;
				return $n;
			});
			$n->method('setObject')->willReturnCallback(function (string $type, string $id) use ($n, $state): INotification {
				$state['type'] = $type;
				$state['id']   = $id;
				return $n;
			});
			$n->method('setSubject')->willReturnCallback(function (string $subject, array $params=[]) use ($n, $state): INotification {
				$state['subject'] = $subject;
				$state['params']  = $params;
				return $n;
			});
			$n->method('getSubjectParameters')->willReturnCallback(fn (): array => $state->getArrayCopy());
			return $n;
		});
		$notifications->method('notify')->willReturnCallback(function (INotification $n): void {
			$this->sent[] = $n->getSubjectParameters();
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string|\Stringable $message): void {
			$this->errors[] = (string)$message;
		});

		$job = new FullExportJob($this->createMock(ITimeFactory::class), $export, $notifications, $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}//end runWith()

	/**
	 * A built export: the administrator is told the archive is ready, by name.
	 *
	 * @return void
	 */
	public function testABuiltExportTellsTheAdministratorItIsReady(): void {
		$this->runWith(['uid' => 'admin'], 'decidiq-export-2026-11-13.zip');

		$this->assertSame(1, $this->builds);
		$this->assertCount(1, $this->sent);
		$this->assertSame('decidiq', $this->sent[0]['app']);
		$this->assertSame('admin', $this->sent[0]['user']);
		$this->assertSame('export', $this->sent[0]['type']);
		$this->assertSame('decidiq-export-2026-11-13.zip', $this->sent[0]['id']);
		$this->assertSame('full_export_ready', $this->sent[0]['subject']);
		$this->assertSame(['title' => 'decidiq-export-2026-11-13.zip'], $this->sent[0]['params']);
		$this->assertSame([], $this->errors);
	}//end testABuiltExportTellsTheAdministratorItIsReady()

	/**
	 * A failed build is logged and the administrator gets the failed notice.
	 *
	 * @return void
	 */
	public function testAFailedBuildIsLoggedAndReportedAsFailed(): void {
		$this->runWith(['uid' => 'admin'], new RuntimeException('disk full'));

		$this->assertCount(1, $this->errors);
		$this->assertStringContainsString('full data export failed', $this->errors[0]);
		$this->assertCount(1, $this->sent);
		$this->assertSame('full_export_failed', $this->sent[0]['subject']);
		$this->assertSame('failed', $this->sent[0]['id']);
		$this->assertSame(['title' => ''], $this->sent[0]['params']);
	}//end testAFailedBuildIsLoggedAndReportedAsFailed()

	/**
	 * A build that returns no name still notifies, about the `failed` object.
	 *
	 * @return void
	 */
	public function testAnUnnamedArchiveIsNotifiedUnderTheFailedObject(): void {
		$this->runWith(['uid' => 'admin'], '');

		$this->assertSame('full_export_ready', $this->sent[0]['subject']);
		$this->assertSame('failed', $this->sent[0]['id']);
	}//end testAnUnnamedArchiveIsNotifiedUnderTheFailedObject()

	/**
	 * Without a user to tell, nothing is built and nobody is notified.
	 *
	 * @return void
	 */
	public function testWithoutAUserNothingIsBuilt(): void {
		$this->runWith(['uid' => ''], 'x.zip');
		$this->runWith([], 'x.zip');
		$this->runWith('admin', 'x.zip');

		$this->assertSame(0, $this->builds);
		$this->assertSame([], $this->sent);
	}//end testWithoutAUserNothingIsBuilt()
}//end class
