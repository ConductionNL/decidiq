<?php

/**
 * Placing a confidentiality restriction for ratification and recording the
 * ratification, over an in-memory register.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTime;
use OCA\Decidiq\Exception\ConfidentialityImposeRefusedException;
use OCA\Decidiq\Service\ConfidentialityImposer;
use OCA\Decidiq\Service\ConfidentialityRatifier;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for ConfidentialityRatifier.
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-003-bekrachtiging-workflow-with-fail-visible-overdue-flag
 */
class ConfidentialityRatifierTest extends TestCase {

	/**
	 * Objects per schema, by id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $objects = [];

	/**
	 * Every save: [schema, uuid, object, rbac].
	 *
	 * @var list<array{0: string, 1: string|null, 2: array<string, mixed>, 3: bool}>
	 */
	private array $saves = [];

	/**
	 * Schemas whose save throws.
	 *
	 * @var list<string>
	 */
	private array $failingSaves = [];

	/**
	 * Bodies whose authority the caller holds.
	 *
	 * @var list<string>
	 */
	private array $authorityOver = ['body-college'];

	/**
	 * Seed the register: the raad with a past, a closed, a later and a next meeting; a restriction the college imposed.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = [
			'confidentiality-ground' => [
				'ground-ratify' => ['id' => 'ground-ratify', 'requiresRatification' => true],
				'ground-woo' => ['id' => 'ground-woo', 'requiresRatification' => false],
			],
			'meeting' => [
				'm-past' => ['id' => 'm-past', 'governanceBody' => 'body-raad', 'scheduledDate' => '2026-11-12T19:30:00+01:00', 'lifecycle' => 'scheduled'],
				'm-closed' => ['id' => 'm-closed', 'governanceBody' => 'body-raad', 'scheduledDate' => '2026-11-26T19:30:00+01:00', 'lifecycle' => 'closed'],
				'm-later' => ['id' => 'm-later', 'governanceBody' => 'body-raad', 'scheduledDate' => '2027-01-21T19:30:00+01:00', 'lifecycle' => 'scheduled'],
				'm-next' => ['id' => 'm-next', 'governanceBody' => 'body-raad', 'scheduledDate' => '2026-12-17T19:30:00+01:00', 'lifecycle' => 'draft'],
				'm-other' => ['id' => 'm-other', 'governanceBody' => 'body-college', 'scheduledDate' => '2026-11-24T10:00:00+01:00', 'lifecycle' => 'scheduled'],
			],
			'agenda-item' => [
				'ai-1' => ['id' => 'ai-1', 'meeting' => 'm-next', 'title' => 'Opening', 'orderNumber' => 1],
				'ai-7' => ['id' => 'ai-7', 'meeting' => 'm-next', 'title' => 'Rondvraag', 'orderNumber' => 7],
			],
			'digital-document' => ['doc-1' => ['id' => 'doc-1', 'title' => 'Grondexploitatie Havenkwartier.pdf']],
			'decision' => ['dec-ratify' => ['id' => 'dec-ratify', 'title' => 'Bekrachtiging']],
			'confidentiality-restriction' => [
				'r-1' => [
					'id' => 'r-1', 'scope' => 'document', 'targetDocument' => 'doc-1', 'ground' => 'ground-ratify',
					'imposedBy' => 'executive-board', 'imposedByBody' => 'body-college', 'imposedAt' => '2026-11-21T10:00:00+01:00', 'lifecycle' => 'imposed',
				],
			],
		];
	}//end setUp()

	/**
	 * The ratifier over the register.
	 *
	 * @return ConfidentialityRatifier
	 */
	private function ratifier(): ConfidentialityRatifier {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options=null): ?ObjectEntity {
				$row = ($this->objects[(string)($options?->schema ?? '')][(string)$id] ?? null);
				return $row === null ? null : self::entity(row: $row);
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config=[]): array {
				$filters = (array)($config['filters'] ?? []);
				$schema  = (string)($filters['schema'] ?? '');
				unset($filters['register'], $filters['schema']);
				$found = [];
				foreach (($this->objects[$schema] ?? []) as $row) {
					if (array_intersect_assoc($filters, $row) === $filters) {
						$found[] = self::entity(row: $row);
					}
				}

				return $found;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options, bool $_rbac=true): ObjectEntity {
				$schema = (string)$options->target->schema;
				if (in_array($schema, $this->failingSaves, true) === true) {
					throw new RuntimeException('save refused');
				}

				$this->saves[] = [$schema, $options->uuid, $object, $_rbac];
				$id            = ($options->uuid ?? ($schema . '-new'));
				$object['id']  = $id;
				$this->objects[$schema][$id] = $object;
				return self::entity(row: $object);
			}
		);

		$imposer = $this->createMock(originalClassName: ConfidentialityImposer::class);
		$imposer->method('mayImpose')->willReturnCallback(fn (string $userId, string $bodyId): bool => in_array($bodyId, $this->authorityOver, true));
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-21T10:00:00+01:00'));

		return new ConfidentialityRatifier(objectService: $objects, imposer: $imposer, time: $time, logger: new NullLogger());
	}//end ratifier()

	/**
	 * An entity over a row.
	 *
	 * @param array<string, mixed> $row The object.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(array $row): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid((string)($row['id'] ?? ''));
		unset($row['id']);
		$entity->setObject($row);
		return $entity;
	}//end entity()

	/**
	 * The refusal status of a call.
	 *
	 * @param callable $call The call.
	 *
	 * @return int The status, or 0 when nothing was refused.
	 */
	private static function refusal(callable $call): int {
		try {
			$call();
		} catch (ConfidentialityImposeRefusedException $e) {
			return $e->getStatus();
		}

		return 0;
	}//end refusal()

	/**
	 * The restriction lands on the raad's next open meeting, last on the agenda and closed; the deadline is that meeting's date.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-geheimhouding-lands-on-the-confirming-bodys-next-agenda
	 */
	public function testTheRestrictionLandsOnTheRatifyingBodysNextAgenda(): void {
		$result = $this->ratifier()->place(restriction: $this->objects['confidentiality-restriction']['r-1'], params: ['ratifyingBody' => 'body-raad']);

		$this->assertSame('placed', $result['status']);
		$item = $this->objects['agenda-item']['agenda-item-new'];
		$this->assertSame('m-next', $item['meeting']);
		$this->assertSame(8, $item['orderNumber']);
		$this->assertSame('Bekrachtigen geheimhouding Grondexploitatie Havenkwartier', $item['title']);
		$this->assertSame('decision', $item['itemType']);
		$this->assertSame('confidential', $item['confidentiality']);
		$this->assertFalse($item['public']);
		$restriction = $this->objects['confidentiality-restriction']['r-1'];
		$this->assertSame('agenda-item-new', $restriction['ratificationAgendaItem']);
		$this->assertSame('2026-12-17', $restriction['ratificationDeadline']);
		$this->assertSame('imposed', $restriction['lifecycle']);
		$this->assertSame('doc-1', $restriction['targetDocument']);
		$this->assertSame($restriction, $result['restriction']);
		$this->assertFalse($this->saves[0][3], 'The ratifying body\'s agenda is written as the system.');
	}//end testTheRestrictionLandsOnTheRatifyingBodysNextAgenda()

	/**
	 * A given deadline wins over the meeting's date; without a ratifying body the imposing body is used.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-003-bekrachtiging-workflow-with-fail-visible-overdue-flag
	 */
	public function testTheDeadlineCanBeSetAndTheBodyDefaults(): void {
		$result = $this->ratifier()->place(
			restriction: $this->objects['confidentiality-restriction']['r-1'],
			params: ['ratificationDeadline' => '2027-01-31']
		);

		$this->assertSame('placed', $result['status']);
		$this->assertSame('m-other', $this->objects['agenda-item']['agenda-item-new']['meeting']);
		$this->assertSame('2027-01-31', $result['restriction']['ratificationDeadline']);
	}//end testTheDeadlineCanBeSetAndTheBodyDefaults()

	/**
	 * Without an open meeting nothing is placed; a given deadline is still recorded.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-003-bekrachtiging-workflow-with-fail-visible-overdue-flag
	 */
	public function testNoOpenMeetingIsReported(): void {
		$restriction = $this->objects['confidentiality-restriction']['r-1'];

		$result = $this->ratifier()->place(restriction: $restriction, params: ['ratifyingBody' => 'body-ab']);
		$this->assertSame('no-meeting', $result['status']);
		$this->assertSame([], $this->saves);

		$result = $this->ratifier()->place(restriction: $restriction, params: ['ratifyingBody' => 'body-ab', 'ratificationDeadline' => '2027-02-01']);
		$this->assertSame('no-meeting', $result['status']);
		$this->assertSame('2027-02-01', $result['restriction']['ratificationDeadline']);
		$this->assertArrayNotHasKey('ratificationAgendaItem', $result['restriction']);
	}//end testNoOpenMeetingIsReported()

	/**
	 * A ground that needs no ratification places nothing; a failed save is reported, not raised.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-003-bekrachtiging-workflow-with-fail-visible-overdue-flag
	 */
	public function testNothingIsPlacedWhenNotNeededAndFailuresAreReported(): void {
		$restriction = array_merge($this->objects['confidentiality-restriction']['r-1'], ['ground' => 'ground-woo']);
		$this->assertSame('not-needed', $this->ratifier()->place(restriction: $restriction, params: ['ratifyingBody' => 'body-raad'])['status']);
		$this->assertSame([], $this->saves);

		$this->failingSaves = ['agenda-item'];
		$result = $this->ratifier()->place(restriction: $this->objects['confidentiality-restriction']['r-1'], params: ['ratifyingBody' => 'body-raad']);
		$this->assertSame('not-placed', $result['status']);
		$this->assertArrayNotHasKey('ratificationDeadline', $result['restriction']);
	}//end testNothingIsPlacedWhenNotNeededAndFailuresAreReported()

	/**
	 * Recording the ratifying decision moves the restriction to ratified, every other field kept.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-bekrachtigingsbesluit-recorded
	 */
	public function testTheRatificationIsRecorded(): void {
		$restriction = $this->ratifier()->ratify(userId: 'griffier', restrictionId: 'r-1', params: ['ratificationDecision' => ' dec-ratify ']);

		$this->assertSame('ratified', $restriction['lifecycle']);
		$this->assertSame('dec-ratify', $restriction['ratificationDecision']);
		$this->assertSame('2026-11-21T10:00:00+01:00', $restriction['ratificationDate']);
		$this->assertSame('doc-1', $restriction['targetDocument']);
		$this->assertSame('ground-ratify', $restriction['ground']);
		$this->assertFalse($this->saves[0][3]);
	}//end testTheRatificationIsRecorded()

	/**
	 * The ratifying body's secretary may record it too: the body of the meeting that holds the item.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-bekrachtigingsbesluit-recorded
	 */
	public function testTheRatifyingBodyMayRecordIt(): void {
		$this->authorityOver = ['body-raad'];
		$this->objects['confidentiality-restriction']['r-1']['ratificationAgendaItem'] = 'ai-7';

		$this->assertSame('ratified', $this->ratifier()->ratify(userId: 'raadsgriffier', restrictionId: 'r-1', params: ['ratificationDecision' => 'dec-ratify'])['lifecycle']);
	}//end testTheRatifyingBodyMayRecordIt()

	/**
	 * Refusals: 404 unknown, 403 no authority, 400 no decision, 409 not imposed or no ratification needed, 422 unknown decision.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-bekrachtigingsbesluit-recorded
	 */
	public function testRefusals(): void {
		$ratifier = $this->ratifier();
		$ok       = ['ratificationDecision' => 'dec-ratify'];

		$this->assertSame(404, self::refusal(fn () => $ratifier->ratify(userId: 'griffier', restrictionId: 'r-none', params: $ok)));
		$this->assertSame(400, self::refusal(fn () => $ratifier->ratify(userId: 'griffier', restrictionId: 'r-1', params: [])));
		$this->assertSame(422, self::refusal(fn () => $ratifier->ratify(userId: 'griffier', restrictionId: 'r-1', params: ['ratificationDecision' => 'dec-none'])));

		$this->objects['confidentiality-restriction']['r-1']['ground'] = 'ground-woo';
		$this->assertSame(409, self::refusal(fn () => $ratifier->ratify(userId: 'griffier', restrictionId: 'r-1', params: $ok)));

		$this->objects['confidentiality-restriction']['r-1']['ground']    = 'ground-ratify';
		$this->objects['confidentiality-restriction']['r-1']['lifecycle'] = 'ratified';
		$this->assertSame(409, self::refusal(fn () => $ratifier->ratify(userId: 'griffier', restrictionId: 'r-1', params: $ok)));

		$this->authorityOver = [];
		$this->assertSame(403, self::refusal(fn () => $ratifier->ratify(userId: 'member', restrictionId: 'r-1', params: $ok)));
		$this->assertSame([], $this->saves);
	}//end testRefusals()
}//end class
