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

use OCA\Decidiq\Service\DecisionAuthority;
use OCA\Decidiq\Service\DelegatedAuthorityCheck;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Whether the person who drafted a decision may take it, read off the
 * delegatie- en mandaatregister: the answer the decisions leaf shows as its
 * mandate notice.
 *
 * @spec openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-says-whether-the-drafter-may-take-the-decision
 */
class DecisionAuthorityTest extends TestCase {

	private ObjectServiceInterface&MockObject $objects;

	private DelegatedAuthorityCheck&MockObject $check;

	private RegisterObjectStore&MockObject $store;

	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->check   = $this->createMock(DelegatedAuthorityCheck::class);
		$this->store   = $this->createMock(RegisterObjectStore::class);
	}//end setUp()

	/**
	 * @return DecisionAuthority The service under test.
	 */
	private function service(): DecisionAuthority {
		return new DecisionAuthority(
			objectService: $this->objects,
			check: $this->check,
			store: $this->store,
			logger: new NullLogger()
		);
	}//end service()

	/**
	 * A decision the caller may read, drafted by pieter.
	 *
	 * @param array<string, mixed> $data The decision's fields.
	 *
	 * @return void
	 */
	private function readable(array $data): void {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($data);
		$this->objects->method('find')->willReturn($entity);
	}//end readable()

	/**
	 * The drafter's mandate is named, with its restrictions.
	 *
	 * @return void
	 */
	public function testTheDraftersMandateIsNamed(): void {
		$this->readable(['decisionType' => 'woo-decision', '@self' => ['owner' => 'pieter']]);
		$this->check->expects($this->once())->method('check')
			->with('pieter', 'woo-decision')
			->willReturn(['authorised' => true, 'reason' => 'authorised', 'allocation' => 'a-1']);
		$this->store->method('find')->willReturn(['id' => 'a-1', 'subject' => 'Woo decisions', 'beperkingen' => 'Not public: the team lead signs.']);

		$answer = $this->service()->forDecision(decisionId: 'd-1');

		$this->assertSame(true, $answer['authorised']);
		$this->assertSame('pieter', $answer['actor']);
		$this->assertSame('Woo decisions', $answer['subject']);
		$this->assertSame('Not public: the team lead signs.', $answer['restrictions']);
	}//end testTheDraftersMandateIsNamed()

	/**
	 * Without a covering allocation the answer is no, and nothing about any
	 * allocation is read or returned.
	 *
	 * @return void
	 */
	public function testNoAllocationIsANoWithoutDetails(): void {
		$this->readable(['decisionType' => 'woo-decision', '@self' => ['owner' => 'pieter']]);
		$this->check->method('check')->willReturn(['authorised' => false, 'reason' => 'no-allocation', 'allocation' => null]);
		$this->store->expects($this->never())->method('find');

		$answer = $this->service()->forDecision(decisionId: 'd-1');

		$this->assertSame(false, $answer['authorised']);
		$this->assertSame('no-allocation', $answer['reason']);
		$this->assertNull($answer['subject']);
	}//end testNoAllocationIsANoWithoutDetails()

	/**
	 * A decision the caller may not read answers nothing, so its existence
	 * does not leak; the register is not asked.
	 *
	 * @return void
	 */
	public function testAnUnreadableDecisionAnswersNothing(): void {
		$this->objects->method('find')->willThrowException(new DoesNotExistException('nope'));
		$this->check->expects($this->never())->method('check');

		$this->assertNull($this->service()->forDecision(decisionId: 'd-x'));
	}//end testAnUnreadableDecisionAnswersNothing()

	/**
	 * A decision without a type or an owner is asked with an empty actor or
	 * act, which the check answers no.
	 *
	 * @return void
	 */
	public function testAMissingTypeOrOwnerIsAskedAsEmpty(): void {
		$this->readable(['@self' => []]);
		$this->check->expects($this->once())->method('check')->with('', '')
			->willReturn(['authorised' => false, 'reason' => 'no-actor', 'allocation' => null]);

		$this->assertSame(false, $this->service()->forDecision(decisionId: 'd-1')['authorised']);
	}//end testAMissingTypeOrOwnerIsAskedAsEmpty()
}//end class
