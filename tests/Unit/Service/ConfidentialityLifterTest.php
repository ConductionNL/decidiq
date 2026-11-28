<?php

/**
 * Lifting a confidentiality restriction, over an in-memory register.
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
use OCA\Decidiq\Service\ConfidentialityLifter;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for ConfidentialityLifter.
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-004-opheffing-workflow-routing-into-the-normal-publication-machinery
 */
class ConfidentialityLifterTest extends TestCase {

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
	 * Every read: [schema, id].
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $reads = [];

	/**
	 * Ids the caller may not read.
	 *
	 * @var list<string>
	 */
	private array $hiddenFromCaller = [];

	/**
	 * Schemas whose save throws.
	 *
	 * @var list<string>
	 */
	private array $failingSaves = [];

	/**
	 * Seed the register: a ratified restriction on a decision, an imposed one on an item.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = [
			'confidentiality-ground' => [
				'ground-ratify' => ['id' => 'ground-ratify', 'requiresRatification' => true],
				'ground-woo' => ['id' => 'ground-woo', 'requiresRatification' => false],
			],
			'confidentiality-restriction' => [
				'r-dec' => [
					'id' => 'r-dec', 'scope' => 'decision', 'targetDecision' => 'dec-1', 'ground' => 'ground-ratify',
					'imposedBy' => 'body', 'imposedByBody' => 'body-raad', 'imposedAt' => '2026-09-01T10:00:00+02:00',
					'lifecycle' => 'ratified', 'ratificationDecision' => 'dec-ratify',
				],
				'r-item' => [
					'id' => 'r-item', 'scope' => 'item', 'targetAgendaItem' => 'item-1', 'ground' => 'ground-woo',
					'imposedBy' => 'chair', 'imposedByBody' => 'body-raad', 'imposedAt' => '2026-09-01T10:00:00+02:00', 'lifecycle' => 'imposed',
				],
			],
			'decision' => [
				'dec-1' => ['id' => 'dec-1', 'title' => 'Aankoop perceel', 'isPublished' => 'confidential', 'lifecycle' => 'decided'],
				'dec-lift' => ['id' => 'dec-lift', 'title' => 'Opheffing geheimhouding'],
			],
			'agenda-item' => ['item-1' => ['id' => 'item-1', 'title' => 'Grondaankoop', 'confidentiality' => 'confidential', 'public' => false]],
		];
	}//end setUp()

	/**
	 * The lifter over the register.
	 *
	 * @param bool $authorised Whether the caller holds the imposing body's authority.
	 *
	 * @return ConfidentialityLifter
	 */
	private function lifter(bool $authorised=true): ConfidentialityLifter {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options=null, bool $_rbac=true): ?ObjectEntity {
				$schema        = (string)($options?->schema ?? '');
				$this->reads[] = [$schema, (string)$id];
				if ($_rbac === true && in_array((string)$id, $this->hiddenFromCaller, true) === true) {
					return null;
				}

				$row = ($this->objects[$schema][(string)$id] ?? null);
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
				$this->objects[$schema][(string)$options->uuid] = $object;
				return self::entity(row: $object);
			}
		);

		$imposer = $this->createMock(originalClassName: ConfidentialityImposer::class);
		$imposer->method('mayImpose')->willReturnCallback(
			static fn (string $userId, string $bodyId): bool => $authorised && $bodyId === 'body-raad'
		);
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-21T10:00:00+01:00'));

		return new ConfidentialityLifter(objectService: $objects, imposer: $imposer, time: $time, logger: new NullLogger());
	}//end lifter()

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
	 * A ratified restriction on a decision is lifted with its decision, date and condition; the decision returns to internal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-opheffing-recorded-with-conditions
	 */
	public function testLiftingRecordsTheDecisionAndReopensToInternal(): void {
		$result = $this->lifter()->lift(
			userId: 'griffier',
			restrictionId: 'r-dec',
			params: ['dissolutionDecision' => 'dec-lift', 'liftingConditions' => ' na ondertekening contract ']
		);

		$restriction = $result['restriction'];
		$this->assertSame('dissolved', $restriction['lifecycle']);
		$this->assertSame('dec-lift', $restriction['dissolutionDecision']);
		$this->assertSame('2026-11-21', $restriction['liftingDate']);
		$this->assertSame('na ondertekening contract', $restriction['liftingConditions']);
		$this->assertTrue(condition: $restriction['awaitingPublication'], message: 'A lifted decision waits for the normal publication run.');
		// Every other field kept.
		$this->assertSame('dec-ratify', $restriction['ratificationDecision']);
		$this->assertSame('ground-ratify', $restriction['ground']);
		$this->assertSame('reopened', $result['classifier']);
		$decision = $this->objects['decision']['dec-1'];
		$this->assertSame('internal', $decision['isPublished'], 'Lifting never publishes.');
		$this->assertSame('decided', $decision['lifecycle']);
		$this->assertSame(['confidentiality-restriction', 'r-dec', false], [$this->saves[0][0], $this->saves[0][1], $this->saves[0][3]]);
	}//end testLiftingRecordsTheDecisionAndReopensToInternal()

	/**
	 * An imposed restriction whose ground needs no ratification can be lifted directly; the item returns to internal and stays not public.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-opheffing-never-auto-publishes
	 */
	public function testAnImposedRestrictionWithoutRatificationIsLifted(): void {
		$result = $this->lifter()->lift(userId: 'griffier', restrictionId: 'r-item', params: ['dissolutionDecision' => 'dec-lift']);

		$this->assertSame('reopened', $result['classifier']);
		$this->assertArrayNotHasKey('liftingConditions', $result['restriction']);
		$this->assertTrue(condition: $result['restriction']['awaitingPublication']);
		$item = $this->objects['agenda-item']['item-1'];
		$this->assertSame('internal', $item['confidentiality']);
		$this->assertFalse($item['public']);
	}//end testAnImposedRestrictionWithoutRatificationIsLifted()

	/**
	 * An imposed restriction whose ground needs ratification, or whose ground cannot be read, is refused with 409.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-lifecycle-transitions-are-guarded-declaratively
	 */
	public function testRatificationComesFirst(): void {
		$this->objects['confidentiality-restriction']['r-dec']['lifecycle'] = 'imposed';
		$lifter = $this->lifter();

		$this->assertSame(409, self::refusal(fn () => $lifter->lift(userId: 'griffier', restrictionId: 'r-dec', params: ['dissolutionDecision' => 'dec-lift'])));

		$this->objects['confidentiality-restriction']['r-item']['ground'] = 'ground-none';
		$this->assertSame(409, self::refusal(fn () => $lifter->lift(userId: 'griffier', restrictionId: 'r-item', params: ['dissolutionDecision' => 'dec-lift'])));
		$this->assertSame([], $this->saves);
	}//end testRatificationComesFirst()

	/**
	 * A restriction no longer in force cannot be lifted again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-004-opheffing-workflow-routing-into-the-normal-publication-machinery
	 */
	public function testALiftedOrLapsedRestrictionIsRefused(): void {
		$lifter = $this->lifter();
		foreach (['dissolved', 'lapsed'] as $state) {
			$this->objects['confidentiality-restriction']['r-dec']['lifecycle'] = $state;
			$this->assertSame(409, self::refusal(fn () => $lifter->lift(userId: 'griffier', restrictionId: 'r-dec', params: ['dissolutionDecision' => 'dec-lift'])));
		}
	}//end testALiftedOrLapsedRestrictionIsRefused()

	/**
	 * Without the body's authority: 403, and nothing but the restriction is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-004-opheffing-workflow-routing-into-the-normal-publication-machinery
	 */
	public function testACallerWithoutAuthorityIsRefused(): void {
		$lifter = $this->lifter(authorised: false);

		$this->assertSame(403, self::refusal(fn () => $lifter->lift(userId: 'member', restrictionId: 'r-dec', params: ['dissolutionDecision' => 'dec-lift'])));
		$this->assertSame([['confidentiality-restriction', 'r-dec']], $this->reads);
		$this->assertSame([], $this->saves);
	}//end testACallerWithoutAuthorityIsRefused()

	/**
	 * A restriction the caller cannot read is 404; a missing decision is 400; an unknown decision is 422.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-004-opheffing-workflow-routing-into-the-normal-publication-machinery
	 */
	public function testTheRequestAndItsObjectsAreChecked(): void {
		$this->hiddenFromCaller = ['r-item'];
		$lifter = $this->lifter();

		$this->assertSame(404, self::refusal(fn () => $lifter->lift(userId: 'griffier', restrictionId: 'r-item', params: ['dissolutionDecision' => 'dec-lift'])));
		$this->assertSame(404, self::refusal(fn () => $lifter->lift(userId: 'griffier', restrictionId: 'r-none', params: ['dissolutionDecision' => 'dec-lift'])));
		$this->assertSame(400, self::refusal(fn () => $lifter->lift(userId: 'griffier', restrictionId: 'r-dec', params: ['dissolutionDecision' => ' '])));
		$this->assertSame(422, self::refusal(fn () => $lifter->lift(userId: 'griffier', restrictionId: 'r-dec', params: ['dissolutionDecision' => 'dec-none'])));
		$this->assertSame([], $this->saves);
	}//end testTheRequestAndItsObjectsAreChecked()

	/**
	 * Another active restriction on the same target keeps it confidential.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-opheffing-never-auto-publishes
	 */
	public function testAnotherActiveRestrictionKeepsTheTargetClosed(): void {
		$this->objects['confidentiality-restriction']['r-dec-2'] = [
			'id' => 'r-dec-2', 'scope' => 'decision', 'targetDecision' => 'dec-1', 'ground' => 'ground-woo', 'imposedByBody' => 'body-raad', 'lifecycle' => 'imposed',
		];

		$result = $this->lifter()->lift(userId: 'griffier', restrictionId: 'r-dec', params: ['dissolutionDecision' => 'dec-lift']);

		$this->assertSame('still-restricted', $result['classifier']);
		$this->assertSame('confidential', $this->objects['decision']['dec-1']['isPublished']);
		$this->assertSame('dissolved', $this->objects['confidentiality-restriction']['r-dec']['lifecycle']);
	}//end testAnotherActiveRestrictionKeepsTheTargetClosed()

	/**
	 * A target save that fails leaves it confidential and is reported; a paper has nothing to reopen.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-opheffing-never-auto-publishes
	 */
	public function testTheClassifierOutcomeIsReported(): void {
		$this->failingSaves = ['decision'];
		$result = $this->lifter()->lift(userId: 'griffier', restrictionId: 'r-dec', params: ['dissolutionDecision' => 'dec-lift']);
		$this->assertSame('not-updated', $result['classifier']);
		$this->assertSame('confidential', $this->objects['decision']['dec-1']['isPublished']);

		$this->objects['confidentiality-restriction']['r-doc'] = [
			'id' => 'r-doc', 'scope' => 'document', 'targetDocument' => 'doc-1', 'ground' => 'ground-woo', 'imposedByBody' => 'body-raad', 'lifecycle' => 'imposed',
		];
		$result = $this->lifter()->lift(userId: 'griffier', restrictionId: 'r-doc', params: ['dissolutionDecision' => 'dec-lift']);
		$this->assertSame('not-needed', $result['classifier']);
	}//end testTheClassifierOutcomeIsReported()

	/**
	 * Empty conditions are left out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-004-opheffing-workflow-routing-into-the-normal-publication-machinery
	 */
	public function testTheRequestIsValidated(): void {
		$this->assertSame(['dissolutionDecision' => 'dec-lift', 'liftingConditions' => null], ConfidentialityLifter::request(params: ['dissolutionDecision' => 'dec-lift', 'liftingConditions' => '  ']));
		$this->assertSame(400, self::refusal(static fn () => ConfidentialityLifter::request(params: ['dissolutionDecision' => ['x']])));
	}//end testTheRequestIsValidated()
	/**
	 * Only an item or decision waits for publication after lifting: a document is not reopened by lifting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-006-geheimhoudingenregister-overview-and-awaiting-bekrachtiging-kpi
	 */
	public function testOnlyAnItemOrDecisionAwaitsPublication(): void {
		$this->assertTrue(condition: ConfidentialityLifter::awaitsPublication(restriction: ['scope' => 'decision']));
		$this->assertTrue(condition: ConfidentialityLifter::awaitsPublication(restriction: ['scope' => 'item']));
		$this->assertFalse(condition: ConfidentialityLifter::awaitsPublication(restriction: ['scope' => 'document']));
		$this->assertFalse(condition: ConfidentialityLifter::awaitsPublication(restriction: []));
	}//end testOnlyAnItemOrDecisionAwaitsPublication()
}//end class
