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

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Decidiq\Service\DelegatedAuthorityCheck;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the boundaries of the delegated-authority check: allowed and refused
 * answers in full, the ondermandaat chain depth, unreadable parents and
 * dates, the ceiling edge cases and the allocation id fallback.
 *
 * @spec openspec/changes/delegated-authority-check/specs/delegatie-mandaatregister/spec.md
 */
class DelegatedAuthorityCheckBoundaryTest extends TestCase {

	/**
	 * A row that grants account `alice` the act `sign`.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private function row(array $overrides=[]): array {
		return array_replace(
			['id' => 'a-1', 'validFrom' => '2026-01-01', 'status' => 'effective', 'delegateUser' => 'alice', 'acts' => ['sign']],
			$overrides
		);
	}//end row()

	/**
	 * Build the check over the given rows; finding $throwingId throws.
	 *
	 * @param array<int, array<string, mixed>> $rows       The stored toedelingen.
	 * @param string                           $throwingId An id whose find() throws.
	 *
	 * @return DelegatedAuthorityCheck
	 */
	private function check(array $rows, string $throwingId=''): DelegatedAuthorityCheck {
		$facade = $this->createMock(ObjectServiceInterface::class);
		$facade->method('findAll')->willReturnCallback(
			static fn (array $config=[]): array => array_values(
				array_filter($rows, static fn (array $row): bool => ($row['status'] ?? '') === 'effective')
			)
		);
		$facade->method('find')->willReturnCallback(
			function (int|string $id) use ($rows, $throwingId): ?ObjectEntityInterface {
				if ((string)$id === $throwingId) {
					throw new RuntimeException('unreadable');
				}

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

		return new DelegatedAuthorityCheck(new RegisterObjectStore($facade), $this->createMock(IGroupManager::class));
	}//end check()

	/**
	 * A chain of parents, each permitting an ondermandaat, $links deep.
	 *
	 * @param int $links How many parents above the child.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function chain(int $links): array {
		$rows = [];
		for ($i = 0; $i <= $links; $i++) {
			$rows[] = $this->row(
				[
					'id' => 'r-' . $i,
					'acts' => ($i === 0 ? ['sign'] : []),
					'subMandatePermitted' => true,
					'parentAllocation' => ($i < $links ? 'r-' . ($i + 1) : ''),
				]
			);
		}

		return $rows;
	}//end chain()

	/**
	 * The full answer shapes, the trimmed inputs and the allocation id fallback.
	 *
	 * @return void
	 */
	public function testTheAnswerShapes(): void {
		self::assertSame(
			['authorised' => true, 'reason' => 'authorised', 'allocation' => 'a-1'],
			$this->check(rows: [$this->row()])->check(actor: ' alice ', act: ' sign ')
		);
		self::assertSame(
			['authorised' => true, 'reason' => 'authorised', 'allocation' => 'u-1'],
			$this->check(rows: [$this->row(['id' => null, 'uuid' => 'u-1'])])->check(actor: 'alice', act: 'sign')
		);
		self::assertSame(
			['authorised' => true, 'reason' => 'authorised', 'allocation' => ''],
			$this->check(rows: [$this->row(['id' => null])])->check(actor: 'alice', act: 'sign')
		);
		self::assertSame(
			['authorised' => false, 'reason' => 'no-allocation', 'allocation' => null],
			$this->check(rows: [])->check(actor: 'alice', act: 'sign')
		);
		self::assertSame(
			['authorised' => false, 'reason' => DelegatedAuthorityCheck::REASON_NO_ACTOR, 'allocation' => null],
			$this->check(rows: [$this->row()])->check(actor: '', act: '')
		);
	}//end testTheAnswerShapes()

	/**
	 * The chain is walked ten parents up and no further.
	 *
	 * @return void
	 */
	public function testTheChainDepthLimit(): void {
		self::assertTrue($this->check(rows: $this->chain(links: 10))->check(actor: 'alice', act: 'sign')['authorised']);
		self::assertFalse($this->check(rows: $this->chain(links: 11))->check(actor: 'alice', act: 'sign')['authorised']);
	}//end testTheChainDepthLimit()

	/**
	 * A parent that cannot be read covers nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableParentCoversNothing(): void {
		$answer = $this->check(rows: $this->chain(links: 2), throwingId: 'r-2')->check(actor: 'alice', act: 'sign');

		self::assertSame(['authorised' => false, 'reason' => 'no-allocation', 'allocation' => null], $answer);
	}//end testAnUnreadableParentCoversNothing()

	/**
	 * Window edges: the first day is inside; an unreadable end date covers nothing; open windows cover.
	 *
	 * @return void
	 */
	public function testWindowEdges(): void {
		$at = new DateTimeImmutable('2026-06-01T23:30:00');
		$cases = [
			[['validFrom' => '2026-06-01T23:59:00'], true],
			[['validFrom' => '2026-06-02'], false],
			[['validFrom' => '', 'validTo' => ''], true],
			[['validFrom' => null], true],
			[['validTo' => 'never'], false],
			[['validTo' => '2026-06-01T00:00:00'], true],
		];
		foreach ($cases as [$change, $authorised]) {
			$answer = $this->check(rows: [$this->row($change)])->check(actor: 'alice', act: 'sign', moment: $at);
			self::assertSame($authorised, $answer['authorised'], json_encode($change));
		}
	}//end testWindowEdges()

	/**
	 * Ceiling edges: a non-numeric ceiling has no limit; over-ceiling wins over no cover.
	 *
	 * @return void
	 */
	public function testCeilingEdges(): void {
		self::assertTrue($this->check(rows: [$this->row(['financialCeiling' => 'n/a'])])->check(actor: 'alice', act: 'sign', amount: 1e9)['authorised']);
		self::assertTrue($this->check(rows: [$this->row(['financialCeiling' => '100'])])->check(actor: 'alice', act: 'sign', amount: 100.0)['authorised']);

		$answer = $this->check(
			rows: [
				$this->row(['financialCeiling' => 10]),
				$this->row(['id' => 'a-2', 'acts' => ['other']]),
			]
		)->check(actor: 'alice', act: 'sign', amount: 11.0);
		self::assertSame(['authorised' => false, 'reason' => 'over-ceiling', 'allocation' => null], $answer);

		$after = $this->check(
			rows: [
				$this->row(['financialCeiling' => 10]),
				$this->row(['id' => 'a-2', 'status' => 'effective', 'validFrom' => '2099-01-01']),
			]
		)->check(actor: 'alice', act: 'sign', amount: 11.0);
		self::assertSame('over-ceiling', $after['reason']);
	}//end testCeilingEdges()
}//end class
