<?php

/**
 * Golden test for MeetingSeriesService::generateSeries.
 *
 * Runs generateSeries over template meetings, patterns and object-service
 * behaviours (lookup failures, missing templates, saves that throw,
 * instances whose id sits under @self,
 * templates with and without agenda items) and records in order every
 * object-service call, every audit entry and every log line, plus what the
 * call returns. The expected values live in
 * tests/fixtures/meeting-series-golden.json; run with
 * RECORD_MEETING_SERIES_GOLDEN=1 to write them, then run again without it.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/meeting-management/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AuditLogService;
use OCA\Decidiq\Service\MeetingSeriesService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use Stringable;

/**
 * Golden test for the series generation of MeetingSeriesService.
 *
 * @covers \OCA\Decidiq\Service\MeetingSeriesService
 * @uses   \OCA\Decidiq\Service\AgendaItemCopier
 */
class MeetingSeriesGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/meeting-series-golden.json';

	/**
	 * Every recorded call of the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * Every case answers as recorded.
	 *
	 * @return void
	 */
	public function testEverySeriesMatchesTheRecording(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->log = [];
			$result = $this->service(case: $case)->generateSeries($case['id'] ?? 'tpl', $case['pattern'], 'anna');
			$actual[$name] = ['returns' => $result, 'calls' => $this->log];
		}

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		if (getenv('RECORD_MEETING_SERIES_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		self::assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testEverySeriesMatchesTheRecording()

	/**
	 * The cases: a template row, a pattern and how the object service behaves.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$template = [
			'id' => 'tpl',
			'title' => 'Gemeenteraad Delft!',
			'scheduledDate' => '2026-04-23T19:30:00+02:00',
			'meetingType' => 'council',
			'meetingMode' => null,
			'location' => 'Raadzaal',
			'governanceBody' => 'body-1',
			'quorumRequired' => 0,
			'isPublic' => false,
			'notes' => 'not copied',
		];
		$weekly = ['frequency' => 'weekly', 'interval' => 2, 'until' => '2026-06-30', 'exceptions' => ['2026-05-07T00:00:00Z']];
		$monthly = ['frequency' => 'monthly', 'interval' => 1, 'until' => '2026-08-31'];
		return [
			'container-throws'  => ['template' => $template, 'pattern' => $weekly, 'container' => 'throw'],
			'find-throws'       => ['template' => $template, 'pattern' => $weekly, 'find' => 'throw'],
			'not-found'         => ['template' => null, 'pattern' => $weekly],
			'no-date'           => ['template' => ['id' => 'tpl', 'title' => 'X'], 'pattern' => $weekly],
			'empty-date'        => ['template' => ['id' => 'tpl', 'scheduledDate' => ''], 'pattern' => $weekly],
			'bad-frequency'     => ['template' => $template, 'pattern' => ['frequency' => 'yearly', 'until' => '2026-12-31']],
			'bad-interval'      => ['template' => $template, 'pattern' => ['frequency' => 'daily', 'interval' => 0, 'until' => '2026-12-31']],
			'no-until'          => ['template' => $template, 'pattern' => ['frequency' => 'daily']],
			'bad-until'         => ['template' => $template, 'pattern' => ['frequency' => 'daily', 'until' => 'soon']],
			'bad-start'         => ['template' => ['scheduledDate' => 'not a date'] + $template, 'pattern' => $weekly],
			'weekly'            => ['template' => $template, 'pattern' => $weekly],
			'monthly-series'    => ['template' => ['series' => 'raad-2026'] + $template, 'pattern' => $monthly, 'items' => true],
			'title-symbols'     => ['template' => ['title' => '!!!'] + $template, 'pattern' => ['frequency' => 'daily', 'interval' => 3, 'until' => '2026-04-30']],
			'no-title'          => ['template' => array_diff_key($template, ['title' => 1]), 'pattern' => ['frequency' => 'daily', 'until' => '2026-04-25']],
			'monthly-items'     => ['template' => $template, 'pattern' => $monthly, 'items' => true],
			'stamp-throws'      => ['template' => $template, 'pattern' => $monthly, 'save' => 'throw-stamp'],
			'instance-throws'   => ['template' => $template, 'pattern' => $monthly, 'save' => 'throw-second'],
			'items-uuid-ids'    => ['template' => $template, 'pattern' => ['frequency' => 'daily', 'until' => '2026-04-25'], 'items' => true, 'save' => 'self-id'],
			'agenda-throws'     => ['template' => $template, 'pattern' => ['frequency' => 'daily', 'until' => '2026-04-24'], 'items' => 'throw'],
			'truncated'         => ['template' => $template, 'pattern' => ['frequency' => 'daily', 'until' => '2026-12-31']],
		];
	}//end cases()

	/**
	 * The service over one case.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return MeetingSeriesService
	 */
	private function service(array $case): MeetingSeriesService {
		$saves = 0;
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (mixed ...$arguments) use ($case): mixed {
				$this->log[] = ['find' => $this->named(ObjectServiceInterface::class, 'find', $arguments)];
				if (($case['find'] ?? '') === 'throw') {
					throw new RuntimeException('lookup down');
				}

				if ($case['template'] === null) {
					return null;
				}

				return $this->entity(row: $case['template']);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (mixed ...$arguments) use ($case, &$saves): mixed {
				$arguments = $this->named(ObjectServiceInterface::class, 'saveObject', $arguments);
				$this->log[] = ['saveObject' => $arguments];
				$saves++;
				$mode = ($case['save'] ?? '');
				if (($mode === 'throw-stamp' && $saves === 1) || ($mode === 'throw-second' && $saves === 3)) {
					throw new RuntimeException('save down');
				}

				$row = ['id' => 'saved-' . $saves] + $arguments['object'];
				if ($mode === 'self-id') {
					$row = ['@self' => ['id' => 'self-' . $saves]] + $arguments['object'];
				}

				return $this->entity(row: $row);
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($case): array {
				$this->log[] = ['findAll' => $config];
				if (($case['items'] ?? false) === 'throw') {
					throw new RuntimeException('items down');
				}

				if (($case['items'] ?? false) === false) {
					return [];
				}

				return [
					['id' => 'item-1', 'title' => 'Opening', 'orderNumber' => 1, 'meeting' => 'tpl'],
					['id' => 'item-2', 'title' => 'Begroting', 'orderNumber' => 2, 'meeting' => 'tpl', 'description' => 'Jaarlijks'],
				];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($case, $objects): object {
				$this->log[] = ['container' => $id];
				if (($case['container'] ?? '') === 'throw') {
					throw new RuntimeException('no object service');
				}

				return $objects;
			}
		);

		$audit = $this->createMock(AuditLogService::class);
		$audit->method('append')->willReturnCallback(
			function (mixed ...$arguments): array {
				$this->log[] = ['audit' => $this->named(AuditLogService::class, 'append', $arguments)];
				return ['success' => true];
			}
		);

		return new MeetingSeriesService($container, $this->logger(), $audit);
	}//end service()

	/**
	 * Map positional mock arguments onto parameter names, leaving out the
	 * ones still at their default.
	 *
	 * @param string       $class     The mocked class
	 * @param string       $method    The method
	 * @param array<mixed> $arguments The arguments received
	 *
	 * @return array<string, mixed>
	 */
	private function named(string $class, string $method, array $arguments): array {
		$named = [];
		foreach ((new ReflectionMethod($class, $method))->getParameters() as $i => $parameter) {
			if (array_key_exists($i, $arguments) === false) {
				break;
			}

			if ($parameter->isDefaultValueAvailable() === true && $parameter->getDefaultValue() === $arguments[$i]) {
				continue;
			}

			$named[$parameter->getName()] = $arguments[$i];
		}

		return $named;
	}//end named()

	/**
	 * A logger that records into the call log.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$log = &$this->log;
		return new class($log) extends AbstractLogger {
			/**
			 * The shared call log.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			private array $log;

			/**
			 * Record into the given log.
			 *
			 * @param array<int, array<string, mixed>> $log The call log
			 */
			public function __construct(array &$log) {
				$this->log = &$log;
			}

			/**
			 * Record one log line.
			 *
			 * @param mixed             $level   The level
			 * @param string|Stringable $message The message
			 * @param array<mixed>      $context The context
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->log[] = ['log' => [$level, (string)$message, $context]];
			}
		};
	}//end logger()

	/**
	 * An entity double; jsonSerialize() marks itself so a read through it shows.
	 *
	 * @param array<string, mixed> $row The row
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn(['viaJson' => true] + $row);
		return $entity;
	}//end entity()
}//end class
