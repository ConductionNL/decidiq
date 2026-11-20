<?php

/**
 * Tests for ConfidentialityLiftBySweep: thirty days before the end date the
 * lifting goes on the deciding body's next meeting, once, and after the date
 * the restriction reads overdue; its lifecycle never changes (REQ-EMB-022).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-022-the-planned-end-date-puts-the-lifting-on-the-agenda
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Decidiq\BackgroundJob\ConfidentialityLiftBySweep;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * The lifting sweep over an in-memory register.
 *
 * @covers \OCA\Decidiq\BackgroundJob\ConfidentialityLiftBySweep
 * @uses   \OCA\Decidiq\Service\SystemObjectRows
 */
class ConfidentialityLiftBySweepTest extends TestCase {
	/**
	 * The objects per schema.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Every save, in order: schema and object.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $saves = [];

	/**
	 * Whether every save fails, as when the register is unreachable.
	 *
	 * @var bool
	 */
	private bool $failSaves = false;

	/**
	 * Ids whose read throws, as an object the system cannot read.
	 *
	 * @var list<string>
	 */
	private array $unreadable = [];

	/**
	 * The council's ratified restriction on Tarieven grondexploitatie, liftBy 1 July 2027.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->saves = [];
		$this->failSaves = false;
		$this->unreadable = [];
		$this->store = [
			'confidentiality-restriction' => [
				'cr-1' => [
					'id' => 'cr-1', 'lifecycle' => 'ratified', 'liftBy' => '2027-07-01', 'imposedByBody' => 'college',
					'ratificationAgendaItem' => 'ai-ratify', 'targetDocument' => 'doc-1', 'liftingConditions' => 'Na gunning van de grondexploitatie',
				],
				'cr-dissolved' => ['id' => 'cr-dissolved', 'lifecycle' => 'dissolved', 'liftBy' => '2027-06-15', 'imposedByBody' => 'college'],
				'cr-open' => ['id' => 'cr-open', 'lifecycle' => 'imposed', 'imposedByBody' => 'college'],
			],
			'agenda-item' => [
				'ai-ratify' => ['id' => 'ai-ratify', 'meeting' => 'm-oct', 'title' => 'Bekrachtiging geheimhouding', 'orderNumber' => 9],
				'ai-june-1' => ['id' => 'ai-june-1', 'meeting' => 'm-june', 'title' => 'Opening', 'orderNumber' => 1],
				'ai-june-2' => ['id' => 'ai-june-2', 'meeting' => 'm-june', 'title' => 'Vaststelling agenda', 'orderNumber' => 2],
			],
			'meeting' => [
				'm-oct' => ['id' => 'm-oct', 'governanceBody' => 'raad', 'scheduledDate' => '2026-10-15T19:30:00+02:00', 'lifecycle' => 'closed'],
				'm-june' => ['id' => 'm-june', 'governanceBody' => 'raad', 'scheduledDate' => '2027-06-24T19:30:00+02:00', 'lifecycle' => 'scheduled'],
				'm-june-late' => ['id' => 'm-june-late', 'governanceBody' => 'raad', 'scheduledDate' => '2027-06-30T19:30:00+02:00', 'lifecycle' => 'scheduled'],
				'm-college' => ['id' => 'm-college', 'governanceBody' => 'college', 'scheduledDate' => '2027-06-08T10:00:00+02:00', 'lifecycle' => 'scheduled'],
				'm-cancelled' => ['id' => 'm-cancelled', 'governanceBody' => 'raad', 'scheduledDate' => '2027-06-10T19:30:00+02:00', 'lifecycle' => 'cancelled'],
			],
			'digital-document' => ['doc-1' => ['id' => 'doc-1', 'name' => 'Tarieven grondexploitatie.pdf']],
		];
	}//end setUp()

	/**
	 * The service over the store.
	 *
	 * @param int                  $leadDays The lead time setting.
	 * @param LoggerInterface|null $logger   The logger, a null logger when not given.
	 *
	 * @return ConfidentialityLiftBySweep
	 */
	private function service(int $leadDays = 30, ?LoggerInterface $logger = null): ConfidentialityLiftBySweep {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = $config['filters'];
				$rows    = array_values($this->store[$filters['schema']] ?? []);
				unset($filters['register'], $filters['schema']);
				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $field => $value) {
								if (($row[$field] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);
		$objects->method('find')->willReturnCallback(
			function (string|int $id, ?ObjectFindOptions $options = null): ?ObjectEntity {
				if (in_array((string)$id, $this->unreadable, true) === true) {
					throw new RuntimeException('not readable');
				}

				return isset($this->store[($options?->schema)][$id]) ? $this->entity(row: $this->store[($options?->schema)][$id]) : null;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions()): ObjectEntity {
				if ($this->failSaves === true) {
					throw new RuntimeException('register offline');
				}

				$extend = $options->extend ?? [];
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				$object = (array)$object;
				$id     = (string)($uuid ?? ($object['id'] ?? ($schema . '-new-' . (count($this->saves) + 1))));
				$object['id'] = $id;
				$this->store[$schema][$id] = $object;
				$this->saves[] = [$schema, $object];
				return $this->entity(row: $object);
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->with('decidiq', ConfidentialityLiftBySweep::LEAD_DAYS_KEY, 30)->willReturn($leadDays);

		return new ConfidentialityLiftBySweep($objects, $config, ($logger ?? new NullLogger()));
	}//end service()

	/**
	 * An entity serialising to a row, as OpenRegister returns it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($row);

		return $entity;
	}//end entity()

	/**
	 * The saves of one schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function savedIn(string $schema): array {
		return array_values(array_map(static fn (array $save): array => $save[1], array_filter($this->saves, static fn (array $save): bool => $save[0] === $schema)));
	}//end savedIn()

	/**
	 * Thirty days before the end date the lifting goes on the council's 24 June
	 * meeting, after its last item, and the restriction stays ratified.
	 *
	 * @return void
	 */
	public function testThirtyDaysBeforeTheEndDate(): void {
		$result = $this->service()->sweep(today: new DateTimeImmutable('2027-06-01'));

		self::assertSame(['placed' => 1, 'overdue' => 0], $result);
		$items = $this->savedIn(schema: 'agenda-item');
		self::assertCount(1, $items);
		self::assertSame('m-june', $items[0]['meeting']);
		self::assertSame('Opheffen geheimhouding Tarieven grondexploitatie', $items[0]['title']);
		self::assertSame([3, 'decision', 'Na gunning van de grondexploitatie'], [$items[0]['orderNumber'], $items[0]['itemType'], $items[0]['description']]);
		$restriction = $this->store['confidentiality-restriction']['cr-1'];
		self::assertSame([$items[0]['id'], 'preparing', 'ratified'], [$restriction['liftingAgendaItem'], $restriction['liftStatus'], $restriction['lifecycle']]);
	}//end testThirtyDaysBeforeTheEndDate()

	/**
	 * Running again adds no second item.
	 *
	 * @return void
	 */
	public function testRunningAgainAddsNone(): void {
		$service = $this->service();
		$service->sweep(today: new DateTimeImmutable('2027-06-01'));
		$second = $service->sweep(today: new DateTimeImmutable('2027-06-02'));

		self::assertSame(['placed' => 0, 'overdue' => 0], $second);
		self::assertCount(1, $this->savedIn(schema: 'agenda-item'));
	}//end testRunningAgainAddsNone()

	/**
	 * Outside the lead time nothing happens; the lead time is the admin setting.
	 *
	 * @return void
	 */
	public function testOutsideTheLeadTimeNothingHappens(): void {
		self::assertSame(['placed' => 0, 'overdue' => 0], $this->service()->sweep(today: new DateTimeImmutable('2027-05-01')));
		self::assertSame([], $this->saves);

		self::assertSame(['placed' => 1, 'overdue' => 0], $this->service(leadDays: 70)->sweep(today: new DateTimeImmutable('2027-05-01')));
	}//end testOutsideTheLeadTimeNothingHappens()

	/**
	 * Past the end date without a lifting decision the restriction reads
	 * overdue, once, and is still ratified.
	 *
	 * @return void
	 */
	public function testTheDatePasses(): void {
		$service = $this->service();

		self::assertSame(['placed' => 0, 'overdue' => 1], $service->sweep(today: new DateTimeImmutable('2027-07-02')));
		self::assertSame(['placed' => 0, 'overdue' => 0], $service->sweep(today: new DateTimeImmutable('2027-07-03')));
		$restriction = $this->store['confidentiality-restriction']['cr-1'];
		self::assertSame(['overdue', 'ratified'], [$restriction['liftStatus'], $restriction['lifecycle']]);
		self::assertSame([], $this->savedIn(schema: 'agenda-item'));
	}//end testTheDatePasses()

	/**
	 * Without a ratification item the imposing body decides; without a meeting
	 * of that body before the end date nothing is placed.
	 *
	 * @return void
	 */
	public function testTheImposingBodyDecidesWithoutRatification(): void {
		unset($this->store['confidentiality-restriction']['cr-1']['ratificationAgendaItem']);
		$this->service()->sweep(today: new DateTimeImmutable('2027-06-01'));
		self::assertSame('m-college', $this->savedIn(schema: 'agenda-item')[0]['meeting']);

		$this->setUp();
		unset($this->store['meeting']['m-june'], $this->store['meeting']['m-june-late']);
		self::assertSame(['placed' => 0, 'overdue' => 0], $this->service()->sweep(today: new DateTimeImmutable('2027-06-01')), 'a cancelled or past meeting takes no item');
		self::assertSame([], $this->saves);
	}//end testTheImposingBodyDecidesWithoutRatification()

	/**
	 * A negative setting falls back to thirty days.
	 *
	 * @return void
	 */
	public function testANegativeLeadTimeIsThirtyDays(): void {
		self::assertSame(30, $this->service(leadDays: -5)->leadDays());
		self::assertSame(14, $this->service(leadDays: 14)->leadDays());
	}//end testANegativeLeadTimeIsThirtyDays()

	/**
	 * A failing save places nothing, logs why and leaves the restriction as it was.
	 *
	 * @return void
	 */
	public function testAFailedSavePlacesNothingAndIsLogged(): void {
		$this->failSaves = true;
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Decidiq: the lifting of a confidentiality could not be put on the agenda',
			['restriction' => 'cr-1', 'meeting' => 'm-june', 'error' => 'register offline']
		);

		self::assertSame(['placed' => 0, 'overdue' => 0], $this->service(logger: $logger)->sweep(today: new DateTimeImmutable('2027-06-01')));
		self::assertArrayNotHasKey('liftStatus', $this->store['confidentiality-restriction']['cr-1']);
	}//end testAFailedSavePlacesNothingAndIsLogged()

	/**
	 * A failing overdue mark counts nothing and is logged.
	 *
	 * @return void
	 */
	public function testAFailedOverdueMarkCountsNothingAndIsLogged(): void {
		$this->failSaves = true;
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Decidiq: a confidentiality past its end date could not be marked overdue',
			['restriction' => 'cr-1', 'error' => 'register offline']
		);

		self::assertSame(['placed' => 0, 'overdue' => 0], $this->service(logger: $logger)->sweep(today: new DateTimeImmutable('2027-07-02')));
		self::assertArrayNotHasKey('liftStatus', $this->store['confidentiality-restriction']['cr-1']);
	}//end testAFailedOverdueMarkCountsNothingAndIsLogged()

	/**
	 * Without a ratification item or an imposing body no body decides, so nothing is placed.
	 *
	 * @return void
	 */
	public function testWithoutADecidingBodyNothingIsPlaced(): void {
		unset(
			$this->store['confidentiality-restriction']['cr-1']['ratificationAgendaItem'],
			$this->store['confidentiality-restriction']['cr-1']['imposedByBody']
		);

		self::assertSame(['placed' => 0, 'overdue' => 0], $this->service()->sweep(today: new DateTimeImmutable('2027-06-01')));
		self::assertSame([], $this->saves);
	}//end testWithoutADecidingBodyNothingIsPlaced()

	/**
	 * A target that no longer exists names the item after "stukken".
	 *
	 * @return void
	 */
	public function testAMissingTargetNamesTheItemStukken(): void {
		$this->store['confidentiality-restriction']['cr-1']['targetDocument'] = 'doc-gone';

		$this->service()->sweep(today: new DateTimeImmutable('2027-06-01'));

		self::assertSame('Opheffen geheimhouding stukken', $this->savedIn(schema: 'agenda-item')[0]['title']);
	}//end testAMissingTargetNamesTheItemStukken()

	/**
	 * An unreadable ratification item counts as none: the imposing body decides.
	 *
	 * @return void
	 */
	public function testAnUnreadableRatificationItemFallsBackToTheImposingBody(): void {
		$this->unreadable = ['ai-ratify'];

		self::assertSame(['placed' => 1, 'overdue' => 0], $this->service()->sweep(today: new DateTimeImmutable('2027-06-01')));
		self::assertSame('m-college', $this->savedIn(schema: 'agenda-item')[0]['meeting']);
	}//end testAnUnreadableRatificationItemFallsBackToTheImposingBody()
}//end class
