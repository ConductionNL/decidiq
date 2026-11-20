<?php

/**
 * Unit tests for the FillDecisionDecidingBody repair step: its failure paths and paging.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/insight-decisions-report-by-period-and-body/specs/dashboard/spec.md#requirement-req-drp-001-a-decision-names-the-body-that-took-it
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Repair;

use OCA\Decidiq\Repair\FillDecisionDecidingBody;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A repair step that cannot read or write leaves the decisions as they are,
 * reports what it filled, and reads each meeting only once.
 *
 * @covers \OCA\Decidiq\Repair\FillDecisionDecidingBody
 * @uses \OCA\Decidiq\Listener\DecisionDecidingBodyListener
 *
 * @spec openspec/changes/insight-decisions-report-by-period-and-body/specs/dashboard/spec.md#requirement-req-drp-001-a-decision-names-the-body-that-took-it
 */
class FillDecisionDecidingBodyTest extends TestCase {

	/**
	 * An entity double carrying a record.
	 *
	 * @param array<string, mixed> $row The record.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn($row);
		return $entity;
	}//end entity()

	/**
	 * An output double expecting exactly this info line.
	 *
	 * @param string $line The expected line.
	 *
	 * @return IOutput
	 */
	private function outputSaying(string $line): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($line);
		return $output;
	}//end outputSaying()

	/**
	 * The step names itself for `occ maintenance:repair`.
	 *
	 * @return void
	 */
	public function testItNamesItself(): void {
		$step = new FillDecisionDecidingBody($this->createMock(ObjectServiceInterface::class), $this->createMock(LoggerInterface::class));

		self::assertSame('Fill the deciding body of decisions from their meeting', $step->getName());
	}//end testItNamesItself()

	/**
	 * Decisions that cannot be read fill nothing, are logged, and the run still reports.
	 *
	 * @return void
	 */
	public function testUnreadableDecisionsFillNothing(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->once())->method('findAll')->willThrowException(new RuntimeException('register missing'));
		$objects->expects($this->never())->method('find');
		$objects->expects($this->never())->method('saveObject');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'Decidiq: FillDecisionDecidingBody found no decisions',
			['exception' => 'register missing']
		);

		(new FillDecisionDecidingBody($objects, $logger))->run($this->outputSaying('FillDecisionDecidingBody: 0 decision(s) got their deciding body.'));
	}//end testUnreadableDecisionsFillNothing()

	/**
	 * A meeting that cannot be read, or is gone, gives its decisions no body.
	 *
	 * @return void
	 */
	public function testAnUnreadableOrMissingMeetingFillsNothing(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturn(
			[
				'results' => [
					$this->entity(['id' => 'd-1', 'meeting' => 'meeting-broken']),
					$this->entity(['id' => 'd-2', 'meeting' => 'meeting-gone']),
				],
			]
		);
		$objects->expects($this->exactly(2))->method('find')->willReturnCallback(
			static function (mixed ...$args): ?ObjectEntity {
				if (array_values($args)[0] === 'meeting-broken') {
					throw new RuntimeException('database gone');
				}

				return null;
			}
		);
		$objects->expects($this->never())->method('saveObject');

		(new FillDecisionDecidingBody($objects, $this->createMock(LoggerInterface::class)))
			->run($this->outputSaying('FillDecisionDecidingBody: 0 decision(s) got their deciding body.'));
	}//end testAnUnreadableOrMissingMeetingFillsNothing()

	/**
	 * A decision that cannot be saved is logged and not counted; the next one still is.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsLoggedAndNotCounted(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturn(
			[
				$this->entity(['id' => 'd-locked', 'meeting' => 'meeting-council']),
				$this->entity(['id' => 'd-ok', 'meeting' => 'meeting-council']),
			]
		);
		// One meeting, read once for both decisions.
		$objects->expects($this->once())->method('find')->willReturn($this->entity(['id' => 'meeting-council', 'governanceBody' => ['id' => 'body-council']]));
		$saved = [];
		$objects->expects($this->exactly(2))->method('saveObject')->willReturnCallback(
			function (mixed ...$args) use (&$saved): ObjectEntity {
				[$object, $options] = array_values($args);
				if ($options->uuid === 'd-locked') {
					throw new RuntimeException('object locked');
				}

				$saved[(string)$options->uuid] = $object['decidingBody'];
				return $this->entity($object);
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Decidiq: a decision could not get its deciding body',
			['decision' => 'd-locked', 'error' => 'object locked']
		);

		(new FillDecisionDecidingBody($objects, $logger))->run($this->outputSaying('FillDecisionDecidingBody: 1 decision(s) got their deciding body.'));

		self::assertSame(['d-ok' => 'body-council'], $saved);
	}//end testAFailedSaveIsLoggedAndNotCounted()

	/**
	 * A full page of 500 is followed by the next page; a short page ends the run.
	 *
	 * @return void
	 */
	public function testAFullPageReadsTheNextOne(): void {
		$offsets = [];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->exactly(2))->method('findAll')->willReturnCallback(
			function (mixed ...$args) use (&$offsets): array {
				$config    = array_values($args)[0];
				$offsets[] = [$config['offset'], $config['limit'], $config['filters']];
				if ($config['offset'] === 0) {
					// 500 decisions without a meeting, plus rows that are not records.
					return array_merge(array_fill(0, 500, ['id' => 'no-meeting']), ['not a record']);
				}

				return [['id' => 'd-last', 'meeting' => ['id' => 'meeting-college']]];
			}
		);
		$objects->expects($this->once())->method('find')->willReturn($this->entity(['governanceBody' => 'body-college']));
		$objects->expects($this->once())->method('saveObject')->willReturnCallback(
			function (mixed ...$args): ObjectEntity {
				[$object, $options] = array_values($args);
				self::assertSame(['decidiq', 'decision', 'd-last'], [$options->target->register, $options->target->schema, $options->uuid]);
				self::assertSame('body-college', $object['decidingBody']);
				return $this->entity($object);
			}
		);

		(new FillDecisionDecidingBody($objects, $this->createMock(LoggerInterface::class)))
			->run($this->outputSaying('FillDecisionDecidingBody: 1 decision(s) got their deciding body.'));

		$filters = ['register' => 'decidiq', 'schema' => 'decision'];
		self::assertSame([[0, 500, $filters], [500, 500, $filters]], $offsets);
	}//end testAFullPageReadsTheNextOne()
}//end class
