<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Decidiq\Service\DelegatedAuthorityCheck;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IGroupManager;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The delegated authority check, over a neutral fixture act.
 *
 * The rows go through the REAL RegisterObjectStore over an in-memory object
 * facade that applies the schema and status filters the way OpenRegister
 * does, and every fixture row validates against the real merged
 * bevoegdheidstoedeling schema, so a field the schema does not carry cannot
 * make a test pass.
 *
 * @spec openspec/changes/delegated-authority-check/specs/delegatie-mandaatregister/spec.md
 */
class DelegatedAuthorityCheckTest extends TestCase {

	/**
	 * A row that grants account `alice` the fixture act `sign-fixture-letter`.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private function row(array $overrides=[]): array {
		return array_replace(
			[
				'id'           => 'a-1',
				'type'         => 'mandate',
				'subject'      => 'Sign fixture letters',
				'decision'     => '7c1a8a6e-1f0e-4d3a-9b9e-1d2c3b4a5f60',
				'validFrom'    => '2026-01-01',
				'status'       => 'effective',
				'delegateUser' => 'alice',
				'acts'         => ['sign-fixture-letter'],
			],
			$overrides
		);
	}//end row()

	/**
	 * Build the check over the given rows.
	 *
	 * @param array<int, array<string, mixed>> $rows      The stored toedelingen.
	 * @param array<string, array<int, string>> $members  Group id to member accounts.
	 * @param bool                              $unreadable Whether the facade throws.
	 *
	 * @return DelegatedAuthorityCheck
	 */
	private function check(array $rows, array $members=[], bool $unreadable=false): DelegatedAuthorityCheck {
		$facade = $this->createMock(ObjectServiceInterface::class);
		$facade->method('findAll')->willReturnCallback(
			static function (array $config=[]) use ($rows, $unreadable): array {
				if ($unreadable === true) {
					throw new RuntimeException('OpenRegister unavailable');
				}

				$filters = ($config['filters'] ?? []);
				$out     = [];
				foreach ($rows as $row) {
					if (($filters['schema'] ?? '') !== 'bevoegdheidstoedeling') {
						continue;
					}

					if (isset($filters['status']) === true && ($row['status'] ?? '') !== $filters['status']) {
						continue;
					}

					$out[] = $row;
				}

				return $out;
			}
		);
		$facade->method('find')->willReturnCallback(
			function (int|string $id) use ($rows): ?ObjectEntityInterface {
				foreach ($rows as $row) {
					if (($row['id'] ?? '') === (string)$id) {
						$entity = $this->createMock(ObjectEntityInterface::class);
						$entity->method('jsonSerialize')->willReturn($row);

						return $entity;
					}
				}

				return null;
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => in_array($uid, ($members[$gid] ?? []), true)
		);

		return new DelegatedAuthorityCheck(new RegisterObjectStore($facade), $groups);
	}//end check()

	/**
	 * An account named on an in-force row may perform its act.
	 *
	 * @return void
	 */
	public function testANamedAccountIsAuthorised(): void {
		$answer = $this->check(rows: [$this->row()])->check(actor: 'alice', act: 'sign-fixture-letter');

		self::assertTrue($answer['authorised']);
		self::assertSame('a-1', $answer['allocation']);
		self::assertSame(DelegatedAuthorityCheck::REASON_AUTHORISED, $answer['reason']);
	}//end testANamedAccountIsAuthorised()

	/**
	 * A member of the delegate group is authorised; a non-member is not.
	 *
	 * @return void
	 */
	public function testAGroupGrantsItsMembersOnly(): void {
		$row   = $this->row(['delegateUser' => null, 'delegateGroup' => 'fixture-signers']);
		$check = $this->check(rows: [$row], members: ['fixture-signers' => ['bob']]);

		self::assertTrue($check->check(actor: 'bob', act: 'sign-fixture-letter')['authorised']);
		self::assertSame(
			DelegatedAuthorityCheck::REASON_NO_ALLOCATION,
			$check->check(actor: 'carol', act: 'sign-fixture-letter')['reason']
		);
	}//end testAGroupGrantsItsMembersOnly()

	/**
	 * Another act, an empty act list, or an empty actor or act is refused.
	 *
	 * @return void
	 */
	public function testAnUncoveredActIsRefused(): void {
		$check = $this->check(rows: [$this->row(), $this->row(['id' => 'a-2', 'acts' => []])]);

		self::assertSame(DelegatedAuthorityCheck::REASON_NO_ALLOCATION, $check->check(actor: 'alice', act: 'other-act')['reason']);
		self::assertSame(DelegatedAuthorityCheck::REASON_NO_ACTOR, $check->check(actor: ' ', act: 'sign-fixture-letter')['reason']);
		self::assertSame(DelegatedAuthorityCheck::REASON_NO_ACT, $check->check(actor: 'alice', act: '')['reason']);
	}//end testAnUncoveredActIsRefused()

	/**
	 * A withdrawn row, a row outside its window and an unreadable date cover nothing.
	 *
	 * @return void
	 */
	public function testOnlyAnInForceRowCounts(): void {
		$at = new DateTimeImmutable('2026-06-01');

		foreach ([
			['status' => 'withdrawn'],
			['validFrom' => '2026-07-01'],
			['validTo' => '2026-05-31'],
			['validFrom' => 'not a date'],
		] as $change) {
			$answer = $this->check(rows: [$this->row($change)])->check(actor: 'alice', act: 'sign-fixture-letter', at: $at);
			self::assertFalse($answer['authorised'], json_encode($change));
		}

		$lastDay = $this->check(rows: [$this->row(['validTo' => '2026-06-01'])])
			->check(actor: 'alice', act: 'sign-fixture-letter', at: $at);
		self::assertTrue($lastDay['authorised'], 'the last day of the window is inside it');
	}//end testOnlyAnInForceRowCounts()

	/**
	 * An amount over the ceiling is refused with its own reason; at or under passes.
	 *
	 * @return void
	 */
	public function testTheFinancialCeilingHolds(): void {
		$check = $this->check(rows: [$this->row(['financialCeiling' => 25000])]);

		self::assertTrue($check->check(actor: 'alice', act: 'sign-fixture-letter', amount: 25000.0)['authorised']);
		self::assertTrue($check->check(actor: 'alice', act: 'sign-fixture-letter')['authorised']);
		self::assertSame(
			DelegatedAuthorityCheck::REASON_OVER_CEILING,
			$check->check(actor: 'alice', act: 'sign-fixture-letter', amount: 25000.01)['reason']
		);
	}//end testTheFinancialCeilingHolds()

	/**
	 * A higher-ceiling row still authorises when a lower one is exceeded.
	 *
	 * @return void
	 */
	public function testAnotherRowCanCoverWhatOneExceeds(): void {
		$check = $this->check(
			rows: [
				$this->row(['financialCeiling' => 1000]),
				$this->row(['id' => 'a-2', 'financialCeiling' => 50000]),
			]
		);

		$answer = $check->check(actor: 'alice', act: 'sign-fixture-letter', amount: 5000.0);
		self::assertTrue($answer['authorised']);
		self::assertSame('a-2', $answer['allocation']);
	}//end testAnotherRowCanCoverWhatOneExceeds()

	/**
	 * An ondermandaat counts only while its parent permits it and is in force.
	 *
	 * @return void
	 */
	public function testAnOndermandaatNeedsAPermittingParentInForce(): void {
		$child = $this->row(['id' => 'child', 'parentAllocation' => 'parent']);

		$ok = $this->check(rows: [$child, $this->row(['id' => 'parent', 'delegateUser' => 'dirk', 'subMandatePermitted' => true])]);
		self::assertTrue($ok->check(actor: 'alice', act: 'sign-fixture-letter')['authorised']);

		foreach ([
			['subMandatePermitted' => false],
			['subMandatePermitted' => true, 'status' => 'withdrawn'],
			['subMandatePermitted' => true, 'validTo' => '2020-01-01'],
		] as $parentChange) {
			$parent = $this->row(array_merge(['id' => 'parent', 'delegateUser' => 'dirk'], $parentChange));
			$answer = $this->check(rows: [$child, $parent])->check(actor: 'alice', act: 'sign-fixture-letter');
			self::assertFalse($answer['authorised'], json_encode($parentChange));
		}

		$orphan = $this->check(rows: [$child])->check(actor: 'alice', act: 'sign-fixture-letter');
		self::assertFalse($orphan['authorised'], 'a parent that cannot be found covers nothing');

		$cycle = $this->check(
			rows: [
				$this->row(['id' => 'x', 'parentAllocation' => 'y', 'subMandatePermitted' => true]),
				$this->row(['id' => 'y', 'parentAllocation' => 'x', 'subMandatePermitted' => true]),
			]
		);
		self::assertFalse($cycle->check(actor: 'alice', act: 'sign-fixture-letter')['authorised'], 'a cycle covers nothing');
	}//end testAnOndermandaatNeedsAPermittingParentInForce()

	/**
	 * A register that cannot be read answers that, never "authorised".
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterIsNotAnAuthorisation(): void {
		$answer = $this->check(rows: [$this->row()], unreadable: true)->check(actor: 'alice', act: 'sign-fixture-letter');

		self::assertFalse($answer['authorised']);
		self::assertSame(DelegatedAuthorityCheck::REASON_UNREADABLE, $answer['reason']);
	}//end testAnUnreadableRegisterIsNotAnAuthorisation()

	/**
	 * Every fixture row is one the real merged schema accepts.
	 *
	 * @return void
	 */
	public function testTheFixtureRowsValidateAgainstTheRealSchema(): void {
		$settings = __DIR__ . '/../../../lib/Settings/';
		$schema   = [];
		$files    = array_merge([$settings . 'decidesk_register.json'], (glob($settings . 'register.d/*.json') ?: []));
		foreach ($files as $file) {
			$doc      = json_decode((string)file_get_contents($file), true);
			$fragment = ($doc['components']['schemas']['Bevoegdheidstoedeling'] ?? null);
			if (is_array($fragment) === true) {
				$schema = array_replace_recursive($schema, $fragment);
			}
		}

		$properties = [];
		foreach (['delegateUser', 'delegateGroup', 'acts', 'financialCeiling', 'validFrom', 'validTo', 'status', 'type', 'subMandatePermitted'] as $name) {
			self::assertArrayHasKey($name, $schema['properties'], $name . ' must be declared');
			$definition = $schema['properties'][$name];
			unset($definition['$ref'], $definition['facetable'], $definition['example'], $definition['title'], $definition['description']);
			$properties[$name] = $definition;
		}

		$contract = json_decode(
			(string)json_encode(['type' => 'object', 'properties' => $properties]),
			false
		);
		$validator = new Validator();
		foreach ([
			$this->row(['financialCeiling' => 25000, 'validTo' => '2026-12-31']),
			$this->row(['delegateUser' => 'bob', 'delegateGroup' => 'fixture-signers', 'subMandatePermitted' => true]),
		] as $row) {
			unset($row['id'], $row['decision'], $row['subject']);
			$result = $validator->validate(json_decode((string)json_encode($row), false), $contract);
			self::assertTrue($result->isValid(), (string)json_encode($row));
		}
	}//end testTheFixtureRowsValidateAgainstTheRealSchema()

}//end class
