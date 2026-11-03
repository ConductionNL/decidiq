<?php

/**
 * Golden test for opening a voting round.
 *
 * Opens rounds through VotingService::openVotingRound() over motions and
 * amendments, with and without a quorum, with chair-picked, meeting-type and
 * body rules, invalid rules, preset participants (members, strangers, a member
 * who left, a substituted seat), revotes of a tied round and of a round that
 * may not be revoted, ranked-choice options and a lifecycle transition that
 * refuses or fails. It records every object-service call by parameter name,
 * every lifecycle transition with its actor, every notification with the user
 * it goes to, every activity entry and the saved round (openedAt masked). The
 * expected values live in tests/fixtures/voting-round-open-golden.json; run
 * with RECORD_VOTING_ROUND_OPEN_GOLDEN=1 to write them, then run again
 * without it.
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
 * @spec openspec/specs/voting-system/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use ArrayObject;
use InvalidArgumentException;
use OCA\Decidiq\Service\ActivityPublisherService;
use OCA\Decidiq\Service\MotionService;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\ParticipantUuidLookup;
use OCA\Decidiq\Service\ProcessTemplateService;
use OCA\Decidiq\Service\VoteCastingService;
use OCA\Decidiq\Service\VotingOpenedNotifier;
use OCA\Decidiq\Service\VotingRoundCloser;
use OCA\Decidiq\Service\VotingRoundOpener;
use OCA\Decidiq\Service\VotingRoundPreflight;
use OCA\Decidiq\Service\VotingRoundProjection;
use OCA\Decidiq\Service\VotingRoundResults;
use OCA\Decidiq\Service\VotingRoundRules;
use OCA\Decidiq\Service\VotingService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * Golden test for opening a voting round.
 *
 * @covers \OCA\Decidiq\Service\VotingService
 * @covers \OCA\Decidiq\Service\VotingRoundOpener
 * @covers \OCA\Decidiq\Service\VotingRoundPreflight
 * @covers \OCA\Decidiq\Service\VotingRoundResolution
 * @uses   \OCA\Decidiq\Service\VotingOpenedNotifier
 * @uses   \OCA\Decidiq\Service\VotingRoundRules
 * @uses   \OCA\Decidiq\Service\VotingRoundRequest
 * @uses   \OCA\Decidiq\Service\ParticipantResolver
 * @uses   \OCA\Decidiq\Service\MeetingRuleSource
 * @uses   \OCA\Decidiq\Service\MeetingAttendanceReader
 * @uses   \OCA\Decidiq\Service\BodyQuorum
 * @uses   \OCA\Decidiq\Service\SubstitutionResolver
 * @uses   \OCA\Decidiq\Service\AmendmentOrderService
 * @uses   \OCA\Decidiq\Service\RankedBallotRules
 * @uses   \OCA\Decidiq\Service\SavedObjectNormaliser
 * @uses   \OCA\Decidiq\Service\BordaCount
 */
class VotingRoundOpenGoldenTest extends TestCase {
	use VotingFacadeCalls;


	private const GOLDEN = __DIR__ . '/../../fixtures/voting-round-open-golden.json';

	/**
	 * Every recorded call of the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * Every opening matches the recording.
	 *
	 * @return void
	 */
	public function testEveryOpeningMatchesTheRecording(): void {
		$actual = [];
		foreach ($this->cases() as $name => $args) {
			$this->log = [];
			try {
				$returns = ['returns' => $this->openRoundOn($this->facade(store: new ArrayObject($this->store())), ...$args)];
			} catch (Throwable $e) {
				$returns = ['throws' => get_class($e), 'message' => $e->getMessage()];
			}

			$actual[$name] = $this->masked(value: $returns) + ['calls' => $this->log];
		}

		$golden = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('RECORD_VOTING_ROUND_OPEN_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		self::assertSame(array_keys($golden), array_keys($actual));
		foreach ($actual as $name => $value) {
			self::assertSame(json_encode($golden[$name]), json_encode($value), $name);
		}
	}//end testEveryOpeningMatchesTheRecording()

	/**
	 * The cases: the facade's named arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$base = ['motionId' => 'mot-2', 'meetingId' => 'meet-1', 'votingMethod' => 'for-against-abstain', 'isSecret' => false, 'closedAt' => null];
		$ranked = ['votingMethod' => 'ranked-choice'] + $base;

		return [
			'motion-defaults'            => $base,
			'motion-null-rules'          => $base + ['presetParticipantIds' => [], 'revoteOfRoundId' => null, 'roundRules' => null],
			'secret-with-deadline'       => ['isSecret' => true, 'closedAt' => '2026-06-12T18:00:00+00:00'] + $base,
			'empty-deadline'             => ['closedAt' => ''] + $base,
			'show-of-hands'              => ['votingMethod' => 'show-of-hands'] + $base,
			'presets'                    => $base + ['presetParticipantIds' => ['p-0', 'p-1', 'p-stranger', 'p-left', 'p-out', 'p-1']],
			'chair-rules'                => $base + ['roundRules' => new VotingRoundRules(voteThreshold: 'qualified-majority-two-thirds', abstentionHandling: 'count', tieBreakRule: 'chair-decides')],
			'invalid-threshold'          => $base + ['roundRules' => new VotingRoundRules(voteThreshold: 'bogus')],
			'invalid-abstention'         => $base + ['roundRules' => new VotingRoundRules(abstentionHandling: 'bogus')],
			'invalid-tie-break'          => $base + ['roundRules' => new VotingRoundRules(tieBreakRule: 'bogus')],
			'invalid-subject'            => $base + ['roundRules' => new VotingRoundRules(subjectType: 'banana')],
			'meeting-type-threshold'     => ['meetingId' => 'meet-typed'] + $base,
			'named-body'                 => $base + ['roundRules' => new VotingRoundRules(governanceBodyId: 'body-2')],
			'no-quorum'                  => ['meetingId' => 'meet-small'] + $base,
			'unknown-meeting'            => ['meetingId' => 'meet-none'] + $base,
			'pending-amendment'          => ['motionId' => 'mot-1'] + $base,
			'first-amendment'            => ['motionId' => 'am-1'] + $base + ['roundRules' => new VotingRoundRules(subjectType: 'amendment')],
			'second-amendment'           => ['motionId' => 'am-2'] + $base + ['roundRules' => new VotingRoundRules(subjectType: 'amendment')],
			'revote-of-tied'             => ['motionId' => 'mot-1'] + $base + ['revoteOfRoundId' => 'round-tied'],
			'revote-of-revoted'          => $base + ['revoteOfRoundId' => 'round-revoted'],
			'revote-of-decided'          => $base + ['revoteOfRoundId' => 'round-decided'],
			'revote-of-missing'          => $base + ['revoteOfRoundId' => 'round-none'],
			'ranked'                     => $ranked + ['roundRules' => new VotingRoundRules(options: [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']])],
			'ranked-no-options'          => $ranked,
			'options-on-plain'           => $base + ['roundRules' => new VotingRoundRules(options: [['key' => 'a', 'label' => 'A']])],
			'ranked-revote'              => $ranked + ['revoteOfRoundId' => 'round-tied-ranked'],
			'transition-refused'         => ['motionId' => 'mot-locked'] + $base,
			'transition-fails'           => ['motionId' => 'mot-broken'] + $base,
		];
	}//end cases()

	/**
	 * The seeded objects, keyed by id.
	 *
	 * @return array<string, array{schema: string, object: array<string, mixed>}>
	 */
	private function store(): array {
		$body = ['@self' => ['relations' => ['governanceBody' => 'body-1']]];
		$rows = [
			'meet-1'            => ['meeting', ['id' => 'meet-1', 'quorumRequired' => 3] + $body],
			'meet-typed'        => ['meeting', ['id' => 'meet-typed', 'quorumRequired' => 3, 'type' => ['id' => 'type-1']] + $body],
			'meet-small'        => ['meeting', ['id' => 'meet-small', 'quorumRequired' => 9] + $body],
			'type-1'            => ['meeting-type', ['id' => 'type-1', 'defaultVoteThreshold' => 'qualified-majority-three-quarters']],
			'body-1'            => ['governance-body', ['id' => 'body-1', 'name' => 'Raad']],
			'body-2'            => ['governance-body', ['id' => 'body-2', 'name' => 'Commissie']],
			'mot-1'             => ['decision', ['id' => 'mot-1', 'title' => 'Motie een', 'decisionType' => 'motion', 'lifecycle' => 'deliberating']],
			'mot-2'             => ['decision', ['id' => 'mot-2', 'title' => 'Motie twee', 'decisionType' => 'motion', 'lifecycle' => 'deliberating']],
			'mot-locked'        => ['decision', ['id' => 'mot-locked', 'title' => 'Vast', 'decisionType' => 'motion']],
			'mot-broken'        => ['decision', ['id' => 'mot-broken', 'title' => 'Stuk', 'decisionType' => 'motion']],
			'am-1'              => ['decision', ['id' => 'am-1', 'title' => 'Amendement A', 'decisionType' => 'amendment', 'amends' => 'mot-1', 'votingOrder' => 1, 'lifecycle' => 'deliberating']],
			'am-2'              => ['decision', ['id' => 'am-2', 'title' => 'Amendement B', 'decisionType' => 'amendment', 'amends' => 'mot-1', 'votingOrder' => 2, 'lifecycle' => 'deliberating']],
			'round-tied'        => ['voting-round', ['id' => 'round-tied', 'result' => 'tied', 'tieBreakRule' => 'revote', 'votingMethod' => 'for-against-abstain']],
			'round-revoted'     => ['voting-round', ['id' => 'round-revoted', 'result' => 'tied', 'tieBreakRule' => 'revote']],
			'round-revote-1'    => ['voting-round', ['id' => 'round-revote-1', 'revoteOfRound' => 'round-revoted']],
			'round-decided'     => ['voting-round', ['id' => 'round-decided', 'result' => 'adopted', 'tieBreakRule' => 'revote']],
			'round-tied-ranked' => ['voting-round', ['id' => 'round-tied-ranked', 'result' => 'tied', 'tieBreakRule' => 'revote', 'votingMethod' => 'ranked-choice', 'options' => [['key' => 'x', 'label' => 'X'], ['key' => 'y', 'label' => 'Y'], ['key' => 'z', 'label' => 'Z']], 'rankingResult' => [['key' => 'x', 'rank' => 1], ['key' => 'y', 'rank' => 1], ['key' => 'z', 'rank' => 3], 'junk']]],
			'sub-1'             => ['mandate-substitution', ['id' => 'sub-1', 'meeting' => 'meet-1', 'outgoingParticipant' => 'p-out', 'incomingParticipant' => 'p-in', 'startedAt' => '2026-06-12T09:00:00+00:00']],
		];
		for ($i = 0; $i < 5; $i++) {
			$rows['p-' . $i] = ['participant', ['id' => 'p-' . $i, 'nextcloudUserId' => 'member-' . $i, 'role' => 'member', 'attendanceStatus' => 'present', 'leftAt' => null] + $body];
		}

		$rows['p-left'] = ['participant', ['id' => 'p-left', 'nextcloudUserId' => 'gone', 'attendanceStatus' => 'present', 'leftAt' => '2026-01-01T00:00:00+00:00'] + $body];
		$rows['p-nouid'] = ['participant', ['id' => 'p-nouid', 'attendanceStatus' => 'absent', 'leftAt' => null] + $body];
		$rows['p-out'] = ['participant', ['id' => 'p-out', 'nextcloudUserId' => 'outgoing', 'attendanceStatus' => 'absent', 'leftAt' => null] + $body];
		$rows['p-in'] = ['participant', ['id' => 'p-in', 'nextcloudUserId' => 'incoming', 'attendanceStatus' => 'present', 'leftAt' => null] + $body];
		$rows['p-stranger'] = ['participant', ['id' => 'p-stranger', 'nextcloudUserId' => 'stranger', '@self' => ['relations' => ['governanceBody' => 'body-9']]]];

		$store = [];
		foreach ($rows as $id => [$schema, $object]) {
			$store[$id] = ['schema' => $schema, 'object' => $object];
		}

		return $store;
	}//end store()

	/**
	 * The voting facade around a real opener; the other collaborators are inert.
	 *
	 * @param ArrayObject $store The seeded objects
	 *
	 * @return VotingService
	 */
	private function facade(ArrayObject $store): VotingService {
		$objects = $this->objectService(store: $store);
		$logger  = new NullLogger();

		$motions = $this->createMock(MotionService::class);
		$motions->method('getAmendmentsForMotion')->willReturnCallback(
			function (string $motionId) use ($store): array {
				$this->log[] = ['amendmentsOf' => $motionId];
				$out = [];
				foreach ($store as $row) {
					if (($row['object']['amends'] ?? null) === $motionId) {
						$out[] = $row['object'];
					}
				}

				return $out;
			}
		);
		$motions->method('transitionLifecycle')->willReturnCallback(
			function (string $objectId, string $objectType, string $newState, string $actorId, ?string $outcome = null): void {
				$this->log[] = ['transition' => [$objectId, $objectType, $newState, $actorId, $outcome]];
				if ($objectId === 'mot-locked') {
					throw new InvalidArgumentException('not allowed from this state');
				}

				if ($objectId === 'mot-broken') {
					throw new RuntimeException('database gone');
				}
			}
		);

		$templates = $this->createMock(ProcessTemplateService::class);
		$templates->method('resolveVotingRuleForBody')->willReturnCallback(
			function (?string $governanceBodyId): ?array {
				$this->log[] = ['template' => $governanceBodyId];
				return ($governanceBodyId === 'body-2') ? ['voteThreshold' => 'qualified-majority-two-thirds', 'tieBreakRule' => 'revote'] : null;
			}
		);

		$prefs = $this->createMock(NotificationPreferenceService::class);
		$prefs->method('dispatch')->willReturnCallback(
			function (string $personId, string $eventType, string $title, string $message, string $deepLink = ''): int {
				$this->log[] = ['notify' => [$personId, $eventType, $title, $message, $deepLink]];
				return 1;
			}
		);
		$activity = $this->createMock(ActivityPublisherService::class);
		$activity->method('publishGovernanceEvent')->willReturnCallback(
			function (string $subject, string $title, string $status, string $objectType, string $objectUuid, string $segment, array $affectedUserIds = []): int {
				$this->log[] = ['activity' => [$subject, $title, $status, $objectType, $objectUuid, $segment, $affectedUserIds]];
				return 1;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objects, $prefs, $activity): object {
				return match ($id) {
					'OCA\OpenRegister\Service\ObjectService' => $objects,
					NotificationPreferenceService::class => $prefs,
					ActivityPublisherService::class => $activity,
					default => throw new RuntimeException('not wired: ' . $id),
				};
			}
		);

		$participants = new ParticipantResolver(logger: $logger, objectService: $objects);

		return new VotingService(
			opener: new VotingRoundOpener(
				motionService: $motions,
				participantResolver: $participants,
				preflight: new VotingRoundPreflight(
					logger: $logger,
					motionService: $motions,
					participantResolver: $participants,
					templateService: $templates,
					objectService: $objects,
				),
				notifier: new VotingOpenedNotifier(logger: $logger, participantResolver: $participants, container: $container),
				objectService: $objects,
			),
			caster: $this->createMock(VoteCastingService::class),
			closer: $this->createMock(VotingRoundCloser::class),
			results: $this->createMock(VotingRoundResults::class),
			projection: $this->createMock(VotingRoundProjection::class),
			participants: $this->createMock(ParticipantUuidLookup::class),
		);
	}//end facade()

	/**
	 * An object-service double over the store that records every call.
	 *
	 * @param ArrayObject $store The seeded objects
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(ArrayObject $store): ObjectServiceInterface {
		$schema  = '';
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			function (string|int $slug) use (&$schema, $objects): ObjectServiceInterface {
				$schema = (string)$slug;
				return $objects;
			}
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) use ($store): ?ObjectEntity {
				$this->log[] = ['find' => ['id' => $id, 'register' => $register, 'schema' => $schema]];
				$row = ($store[(string)$id] ?? null);
				if ($row === null || ($schema !== null && $row['schema'] !== $schema)) {
					return null;
				}

				return $this->entity(object: $row['object']);
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []) use ($store, &$schema): array {
				$this->log[] = ['findAll' => ['schema' => $schema, 'config' => $config]];
				$out    = [];
				$wanted = (string)($config['filters']['schema'] ?? $schema);
				foreach ($store as $row) {
					if ($row['schema'] !== $wanted) {
						continue;
					}

					$matches = true;
					foreach (($config['filters'] ?? []) as $key => $value) {
						if (in_array($key, ['register', 'schema'], true) === true || str_starts_with((string)$key, '_relations') === true) {
							continue;
						}

						if (($row['object'][$key] ?? null) !== $value) {
							$matches = false;
						}
					}

					if ($matches === true) {
						$out[] = $this->entity(object: $row['object']);
					}
				}

				return $out;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null) use ($store): ObjectEntity {
				$this->log[] = ['save' => ['register' => $register, 'schema' => $schema, 'object' => $this->masked(value: $object)]];
				$store['saved-' . count($store)] = ['schema' => (string)$schema, 'object' => $object];
				return $this->entity(object: $object + ['id' => 'round-new']);
			}
		);

		return $objects;
	}//end objectService()

	/**
	 * Mask the opening time, the only value that differs per run.
	 *
	 * @param array<string, mixed> $value A recorded value
	 *
	 * @return array<string, mixed> The value with every openedAt masked.
	 */
	private function masked(array $value): array {
		array_walk_recursive(
			$value,
			static function (mixed &$item, int|string $key): void {
				if ($key === 'openedAt' && is_string($item) === true
					&& preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $item) === 1
				) {
					$item = '<atom>';
				}
			}
		);
		return $value;
	}//end masked()

	/**
	 * Wrap a payload in an ObjectEntity double that serialises to it.
	 *
	 * @param array<string, mixed> $object The payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $object): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize', 'getObject'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($object);
		$entity->method('getObject')->willReturn($object);
		return $entity;
	}//end entity()
}//end class
