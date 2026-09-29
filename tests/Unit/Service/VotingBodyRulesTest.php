<?php

/**
 * Unit tests: a voting round follows its body's voting rule and quorum rule.
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
 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-002-votes-follow-the-body-rules
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\MotionService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\ProcessTemplateService;
use OCA\Decidiq\Service\VotingOpenedNotifier;
use OCA\Decidiq\Service\VotingRoundOpener;
use OCA\Decidiq\Service\VotingRoundPreflight;
use OCA\Decidiq\Service\VotingRoundRules;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Opening a round reads the meeting's body: its voting rule through the body's
 * process template, and its quorum (a member count or a quorum rule).
 *
 * The real VotingRoundOpener, VotingRoundPreflight and ParticipantResolver run
 * over an ObjectService double that answers like OpenRegister: the meeting
 * carries its body as the flat relation `@self.relations.governanceBody`.
 *
 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-002-votes-follow-the-body-rules
 */
class VotingBodyRulesTest extends TestCase {

	private const MEETING = 'a1b2c3d4-0000-4000-8000-000000000001';

	private const BODY = '0b6c1d2e-3f40-4a51-8b62-7c83d94ea5f6';

	private const MOTION = 'a1b2c3d4-0000-4000-8000-000000000009';

	/**
	 * Rounds written to OpenRegister.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Build the opener.
	 *
	 * @param array<string, mixed> $meeting The meeting
	 * @param array<string, mixed> $body    Its governance body
	 * @param int                  $present Members marked present
	 * @param int                  $absent  Members marked absent
	 *
	 * @return VotingRoundOpener
	 */
	private function opener(array $meeting, array $body, int $present, int $absent): VotingRoundOpener {
		$this->saved = [];
		$meeting = array_merge(
			['id' => self::MEETING, '@self' => ['relations' => ['governanceBody' => self::BODY]]],
			$meeting
		);

		$participants = [];
		for ($i = 0; $i < ($present + $absent); $i++) {
			$participants[] = $this->entity([
				'id' => 'p-' . $i,
				'nextcloudUserId' => 'member-' . $i,
				'role' => 'member',
				'attendanceStatus' => ($i < $present ? 'present' : 'absent'),
				'leftAt' => null,
				'@self' => ['relations' => ['governanceBody' => self::BODY]],
			]);
		}

		$participants[] = $this->entity([
			'id' => 'other-body-member',
			'attendanceStatus' => 'present',
			'@self' => ['relations' => ['governanceBody' => 'another-body']],
		]);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('find')->willReturnCallback(
			fn (int|string $id): ObjectEntity => match ((string)$id) {
				self::MEETING => $this->entity($meeting),
				self::BODY => $this->entity(array_merge(['id' => self::BODY, 'name' => 'Raad van commissarissen'], $body)),
				default => $this->entity(['id' => (string)$id, 'decisionType' => 'motion', 'lifecycle' => 'deliberating']),
			}
		);
		$objectService->method('findAll')->willReturn($participants);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				return $this->entity($object);
			}
		);

		$templateService = $this->createMock(ProcessTemplateService::class);
		$templateService->method('resolveVotingRuleForBody')->willReturnCallback(
			static fn (?string $bodyId): ?array => ($bodyId === self::BODY ? ['voteThreshold' => 'qualified-majority-two-thirds'] : null)
		);

		$logger = new NullLogger();
		$motionService = $this->createMock(MotionService::class);
		$resolver = new ParticipantResolver(logger: $logger, objectService: $objectService);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not wired'));

		return new VotingRoundOpener(
			motionService: $motionService,
			participantResolver: $resolver,
			preflight: new VotingRoundPreflight(
				logger: $logger,
				motionService: $motionService,
				participantResolver: $resolver,
				templateService: $templateService,
				objectService: $objectService,
			),
			notifier: new VotingOpenedNotifier(logger: $logger, participantResolver: $resolver, container: $container),
			objectService: $objectService,
		);

	}//end opener()

	/**
	 * An entity double serialising to the row.
	 *
	 * @param array<string, mixed> $row The row
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
	 * Open a round the way the panel now does: no rule, no body.
	 *
	 * @param VotingRoundOpener $opener The opener
	 *
	 * @return array<string, mixed>
	 */
	private function open(VotingRoundOpener $opener): array {
		return $opener->openVotingRound(
			motionId: self::MOTION,
			meetingId: self::MEETING,
			votingMethod: 'for-against-abstain',
			isSecret: false,
			closedAt: null,
			roundRules: new VotingRoundRules()
		);

	}//end open()

	/**
	 * Scenario: the supervisory board requires a two-thirds majority; the
	 * round opened on its meeting carries that rule.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-002-votes-follow-the-body-rules
	 */
	public function testTheRoundCarriesTheBodysTwoThirdsRule(): void {
		$this->open($this->opener(meeting: [], body: [], present: 9, absent: 0));

		self::assertCount(1, $this->saved);
		self::assertSame('qualified-majority-two-thirds', $this->saved[0]['voteThreshold']);

	}//end testTheRoundCarriesTheBodysTwoThirdsRule()

	/**
	 * Too few members present for the body's quorum rule: the round is refused
	 * and nothing is written.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-002-votes-follow-the-body-rules
	 */
	public function testTooFewPresentForTheBodyQuorumRuleIsRefused(): void {
		// Two thirds of 9 members is 6; 5 are present.
		$opener = $this->opener(meeting: [], body: ['quorumRule' => 'qualified-majority-two-thirds'], present: 5, absent: 4);

		$refusal = '';
		try {
			$this->open($opener);
		} catch (RuntimeException $e) {
			$refusal = $e->getMessage();
		}

		self::assertStringContainsString('Quorum', $refusal);
		self::assertSame([], $this->saved);

	}//end testTooFewPresentForTheBodyQuorumRuleIsRefused()

	/**
	 * Enough members present for the body's quorum rule: the round opens.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-002-votes-follow-the-body-rules
	 */
	public function testEnoughPresentForTheBodyQuorumRuleOpens(): void {
		$this->open($this->opener(meeting: [], body: ['quorumRule' => 'qualified-majority-two-thirds'], present: 6, absent: 3));

		self::assertCount(1, $this->saved);
		self::assertTrue($this->saved[0]['quorumWith']);

	}//end testEnoughPresentForTheBodyQuorumRuleOpens()

	/**
	 * The body's member count is a quorum too.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-002-votes-follow-the-body-rules
	 */
	public function testTheBodysMemberQuorumCounts(): void {
		$opener = $this->opener(meeting: [], body: ['quorum' => 5], present: 4, absent: 5);

		$this->expectException(RuntimeException::class);
		$this->open($opener);

	}//end testTheBodysMemberQuorumCounts()

	/**
	 * The meeting's own quorum wins over the body's.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-002-votes-follow-the-body-rules
	 */
	public function testTheMeetingsOwnQuorumWins(): void {
		$this->open($this->opener(meeting: ['quorumRequired' => 3], body: ['quorum' => 7], present: 4, absent: 5));

		self::assertCount(1, $this->saved);

	}//end testTheMeetingsOwnQuorumWins()
}//end class
