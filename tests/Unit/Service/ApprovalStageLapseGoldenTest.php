<?php

/**
 * Golden runs of the approval-stage lapse sweep.
 *
 * Records every store write, notification and log line the sweep makes, in
 * order, for each declared silence (approve, refuse, escalate with and
 * without a manager, nothing declared) and compares the whole trace with a
 * stored golden. Pinned before applyLapse was split into one step per effect.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec exclude golden test of the lapse sweep, no behaviour of its own
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Decidiq\Service\ApprovalStageActivator;
use OCA\Decidiq\Service\ApprovalStageLapseService;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCA\Decidiq\Service\StageLapsePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * One golden trace per declared silence.
 */
final class ApprovalStageLapseGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/approval-stage-lapse-golden.json';

	/**
	 * Everything the sweep did, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $trace = [];

	/**
	 * The stand-in register.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * The cases: the live stage's overrides and the manager the activator knows.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null}>
	 */
	public static function cases(): array {
		return [
			'nothing declared' => [[], 'b.bakker'],
			'silence approves' => [['onSilence' => StageLapsePolicy::ON_SILENCE_APPROVE], null],
			'silence refuses' => [['onSilence' => StageLapsePolicy::ON_SILENCE_REFUSE], null],
			'silence escalates' => [['onSilence' => StageLapsePolicy::ON_SILENCE_ESCALATE], 'b.bakker'],
			'escalation without a manager' => [['onSilence' => StageLapsePolicy::ON_SILENCE_ESCALATE], null],
		];
	}//end cases()

	/**
	 * The trace of one sweep equals the stored golden.
	 *
	 * @param array<string, mixed> $overrides The live stage's overrides.
	 * @param string|null          $manager   The actor's manager.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testTheSweepTraceIsUnchanged(array $overrides, ?string $manager): void {
		$this->givenARoute($overrides);
		$result = $this->service($manager)->sweep(new DateTimeImmutable('2026-09-18T03:00:00+00:00'));
		$this->trace[] = ['result' => $result, 'rows' => $this->rows];

		$name = (string)$this->dataName();
		$golden = [];
		if (file_exists(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('DECIDIQ_WRITE_GOLDEN') === '1') {
			$golden[$name] = $this->trace;
			file_put_contents(self::GOLDEN, json_encode($golden, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
		}

		self::assertSame($golden[$name] ?? null, json_decode((string)json_encode($this->trace), true));
	}//end testTheSweepTraceIsUnchanged()

	/**
	 * The service over recording doubles.
	 *
	 * @param string|null $manager The manager the activator answers.
	 *
	 * @return ApprovalStageLapseService The service.
	 */
	private function service(?string $manager): ApprovalStageLapseService {
		$store = $this->getMockBuilder(RegisterObjectStore::class)
			->disableOriginalConstructor()
			->onlyMethods(['save', 'patch', 'find', 'findAll'])
			->getMock();
		$store->method('findAll')->willReturnCallback(
			function (string $schema, array $filters): array {
				$this->trace[] = ['findAll' => $schema, 'filters' => $filters];
				return array_values(
					array_filter(
						($this->rows[$schema] ?? []),
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if ((string)($row[$key] ?? '') !== (string)$value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);
		$store->method('patch')->willReturnCallback(
			function (string $schema, array $data, string $uuid): array {
				$this->trace[] = ['patch' => $schema, 'uuid' => $uuid, 'data' => $data];
				foreach (($this->rows[$schema] ?? []) as $index => $row) {
					if ((string)($row['id'] ?? '') === $uuid) {
						$this->rows[$schema][$index] = array_merge($row, $data);
						return $this->rows[$schema][$index];
					}
				}

				return [];
			}
		);
		$store->method('save')->willReturnCallback(
			function (string $schema, array $object): array {
				$this->trace[] = ['save' => $schema, 'object' => $object];
				return $object;
			}
		);

		$activator = $this->getMockBuilder(ApprovalStageActivator::class)
			->disableOriginalConstructor()
			->onlyMethods(['activationPatch', 'substituteOf', 'managerOf'])
			->getMock();
		$activator->method('activationPatch')->willReturn(['status' => 'active']);
		$activator->method('substituteOf')->willReturn(null);
		$activator->method('managerOf')->willReturnCallback(
			function (string $actor) use ($manager): ?string {
				$this->trace[] = ['managerOf' => $actor];
				return $manager;
			}
		);

		$notifier = $this->getMockBuilder(NotificationPreferenceService::class)
			->disableOriginalConstructor()
			->onlyMethods(['dispatch'])
			->getMock();
		$notifier->method('dispatch')->willReturnCallback(
			function (string $personId, string $eventType, string $title, string $message, string $deepLink = ''): int {
				$this->trace[] = ['tell' => $personId, 'event' => $eventType, 'title' => $title, 'message' => $message, 'link' => $deepLink];
				return 1;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->trace[] = [$level => $message, 'context' => $context];
				}
			);
		}

		return new ApprovalStageLapseService(
			store: $store,
			policy: new StageLapsePolicy(),
			activator: $activator,
			logger: $logger,
			notifier: $notifier,
		);
	}//end service()

	/**
	 * A two step route whose first step is overdue.
	 *
	 * @param array<string, mixed> $overrides Overrides on the live stage.
	 *
	 * @return void
	 */
	private function givenARoute(array $overrides): void {
		$this->rows = [
			'decision-stage' => [
				array_merge(
					[
						'id' => 'stage-1',
						'sequence' => 1,
						'status' => 'active',
						'decision' => 'subject-1',
						'note' => 'decision',
						'assignedPerson' => 'j.jansen',
						'activatedAt' => '2026-09-08T12:00:00+00:00',
						'dueAt' => '2026-09-16T12:00:00+00:00',
						'substituteActor' => 's.smit',
					],
					$overrides
				),
				[
					'id' => 'stage-2',
					'sequence' => 2,
					'status' => 'pending',
					'decision' => 'subject-1',
					'note' => 'decision',
					'assignedPerson' => 'd.devries',
				],
			],
		];
	}//end givenARoute()
}//end class
