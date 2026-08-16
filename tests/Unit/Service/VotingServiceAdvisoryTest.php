<?php

/**
 * Unit tests for AdvisoryVoteService — the advisory (citizen-participation)
 * tally mode extracted from VotingService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/citizen-participation/specs/voting-system/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\AdvisoryVoteService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests advisory tally reuse: value enum, duplicate rejection (shared dedup
 * path), and atomic recount onto the BudgetProposal.
 *
 * @spec openspec/changes/citizen-participation/specs/voting-system/spec.md
 */
class VotingServiceAdvisoryTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var AdvisoryVoteService
	 */
	private AdvisoryVoteService $service;

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();

		$this->service = new AdvisoryVoteService(
			objectService: $this->objectService,
		);

	}//end setUp()

	/**
	 * Build an ObjectEntity mock serialising to the given array.
	 *
	 * @param array<string, mixed> $data Payload.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * An invalid advisory value is rejected (voor/tegen only).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/voting-system/spec.md
	 */
	public function testInvalidAdvisoryValueRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->applyAdvisoryTally(proposalId: 'p1', voterId: 'alice', value: 'abstain');

	}//end testInvalidAdvisoryValueRejected()

	/**
	 * First advisory vote writes a CitizenVote and re-tallies onto the proposal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/voting-system/spec.md
	 */
	public function testFirstAdvisoryVoteTallies(): void {
		$voteRecord = $this->entity(
			['voteValue' => 'voor', 'voterId' => 'alice', 'relations' => [['schema' => 'budget-proposal', 'id' => 'p1']]]
		);

		// findAll: first call (dedup) returns no prior vote; second call (re-tally) returns one 'voor'.
		$this->objectService->method('findAll')->willReturnOnConsecutiveCalls([], [$voteRecord]);
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'p1', 'votesFor' => 0, 'votesAgainst' => 0]));
		$this->objectService->method('saveObject')->willReturnCallback(fn (...$a) => $this->entity($a[0] ?? []));

		$result = $this->service->applyAdvisoryTally(proposalId: 'p1', voterId: 'alice', value: 'voor');
		self::assertSame(1, $result['votesFor']);
		self::assertSame(0, $result['votesAgainst']);

	}//end testFirstAdvisoryVoteTallies()

	/**
	 * A duplicate advisory vote by the same voter is rejected via the shared
	 * dedup path; the tally is unchanged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/voting-system/spec.md
	 */
	public function testDuplicateAdvisoryVoteRejected(): void {
		$existing = $this->entity(
			['voteValue' => 'voor', 'voterId' => 'alice', 'relations' => [['schema' => 'budget-proposal', 'id' => 'p1']]]
		);
		// Dedup findAll returns the prior vote referencing this proposal + voter.
		$this->objectService->method('findAll')->willReturn([$existing]);

		$this->objectService->expects($this->never())->method('saveObject');
		$this->expectException(\RuntimeException::class);
		$this->service->applyAdvisoryTally(proposalId: 'p1', voterId: 'alice', value: 'voor');

	}//end testDuplicateAdvisoryVoteRejected()

	/**
	 * Advisory recount counts voor/tegen CitizenVotes and persists onto the proposal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-participation/specs/voting-system/spec.md
	 */
	public function testAdvisoryRecount(): void {
		$votes = [
			$this->entity(['voteValue' => 'voor', 'relations' => [['schema' => 'budget-proposal', 'id' => 'p1']]]),
			$this->entity(['voteValue' => 'voor', 'relations' => [['schema' => 'budget-proposal', 'id' => 'p1']]]),
			$this->entity(['voteValue' => 'tegen', 'relations' => [['schema' => 'budget-proposal', 'id' => 'p1']]]),
		];
		$this->objectService->method('findAll')->willReturn($votes);
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'p1']));
		$this->objectService->method('saveObject')->willReturnCallback(fn (...$a) => $this->entity($a[0] ?? []));

		$tally = $this->service->tallyAdvisoryProposal(proposalId: 'p1');
		self::assertSame(2, $tally['votesFor']);
		self::assertSame(1, $tally['votesAgainst']);

	}//end testAdvisoryRecount()

}//end class
