<?php

/**
 * Unit tests for VotingRecordService and PersonParticipantLookup: a person's
 * votes in closed rounds that were not secret.
 *
 * Ballots cast through the app are built by the real VoteBallotFactory, so the
 * voter sits in the ballot's `relations`; the example sets write the voter in
 * the `participant` property instead. Both shapes are read.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ObjectRelationFilter;
use OCA\Decidiq\Service\PersonParticipantLookup;
use OCA\Decidiq\Service\VoteBallotFactory;
use OCA\Decidiq\Service\VoteContextReader;
use OCA\Decidiq\Service\VoterTokenSecret;
use OCA\Decidiq\Service\VotingRecordService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for VotingRecordService.
 *
 * @covers \OCA\Decidiq\Service\VotingRecordService
 * @covers \OCA\Decidiq\Service\PersonParticipantLookup
 * @covers \OCA\Decidiq\Service\VoteContextReader
 * @uses   \OCA\Decidiq\Service\ObjectRelationFilter
 * @uses   \OCA\Decidiq\Service\VoteBallotFactory
 * @uses   \OCA\Decidiq\Service\VoterTokenSecret
 *
 * @spec openspec/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
 */
class VotingRecordServiceTest extends TestCase {

	/**
	 * Objects per schema: schema => id => data.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Number of save calls the service made.
	 *
	 * @var int
	 */
	private int $saves = 0;

	/**
	 * Put one object in the store.
	 *
	 * @param string               $schema The schema slug
	 * @param string               $id     The object id
	 * @param array<string, mixed> $data   The object data
	 *
	 * @return void
	 */
	private function put(string $schema, string $id, array $data): void {
		$this->store[$schema][$id] = $data;
	}//end put()

	/**
	 * A ballot built the way castVote() builds it.
	 *
	 * @param string      $roundId       The round
	 * @param string      $participantId The caster
	 * @param string      $value         for, against or abstain
	 * @param string|null $delegatorId   The member a proxy votes for
	 *
	 * @return array<string, mixed>
	 */
	private function castBallot(string $roundId, string $participantId, string $value, ?string $delegatorId = null): array {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not in this test'));
		$factory = new VoteBallotFactory($container, $this->createMock(LoggerInterface::class), new VoterTokenSecret($container));

		$vote = $factory->buildVote(
			votingRoundId: $roundId,
			participantId: $participantId,
			value: $value,
			isProxy: $delegatorId !== null,
			delegatorId: $delegatorId,
			isSecret: false,
			existingVote: null
		);
		unset($vote['@self']);
		$vote['castAt'] = '2025-04-10T21:10:00+00:00';
		return $vote;
	}//end castBallot()

	/**
	 * Wrap stored data in the entity OpenRegister returns.
	 *
	 * @param string               $schema The schema slug
	 * @param string               $id     The object id
	 * @param array<string, mixed> $data   The data
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $schema, string $id, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($id);
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * An ObjectServiceInterface over the in-memory store.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null): ?ObjectEntity {
				$data = ($this->store[(string)$schema][(string)$id] ?? null);
				if ($data === null) {
					return null;
				}

				return self::entity(schema: (string)$schema, id: (string)$id, data: $data);
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$filters = ($config['filters'] ?? []);
				$schema = (string)($filters['schema'] ?? '');
				unset($filters['schema'], $filters['register']);
				$found = [];
				foreach (($this->store[$schema] ?? []) as $id => $data) {
					if (self::meetsFilters(data: $data, filters: $filters) === true) {
						$found[] = self::entity(schema: $schema, id: (string)$id, data: $data);
					}
				}

				return $found;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function () {
				$this->saves++;
				throw new \RuntimeException('A read must never write.');
			}
		);
		return $objectService;
	}//end objectService()

	/**
	 * Whether stored data meets equality filters (the relation filter matches any relation id).
	 *
	 * @param array<string, mixed> $data    The data
	 * @param array<string, mixed> $filters The filters
	 *
	 * @return bool
	 */
	private static function meetsFilters(array $data, array $filters): bool {
		foreach ($filters as $field => $value) {
			if ($field === ObjectRelationFilter::RELATION_FILTER_FIELD) {
				$ids = array_column(($data['relations'] ?? []), 'id');
				if (in_array($value, $ids, true) === false) {
					return false;
				}

				continue;
			}

			if (($data[$field] ?? null) !== $value) {
				return false;
			}
		}

		return true;
	}//end meetsFilters()

	/**
	 * The service under test, over the store.
	 *
	 * @return VotingRecordService
	 */
	private function service(): VotingRecordService {
		$objectService = $this->objectService();
		return new VotingRecordService(
			objectService: $objectService,
			participants: new PersonParticipantLookup(objectService: $objectService),
			relationFilter: new ObjectRelationFilter(),
			context: new VoteContextReader(objectService: $objectService)
		);
	}//end service()

	/**
	 * The council, Marie, a published motion and its rounds.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [];
		$this->saves = 0;
		$this->put('governance-body', 'raad', ['name' => 'Gemeenteraad Amsterdam', 'bodyType' => 'legislative']);
		$this->put('person', 'marie', ['name' => 'Marie Janssen', 'email' => 'm.janssen@amsterdam.nl', 'nextcloudUserId' => 'mjanssen']);
		$this->put('participant', 'p-marie', ['displayName' => 'Marie Janssen', 'role' => 'member', 'party' => 'D66', 'nextcloudUserId' => 'mjanssen', 'email' => 'm.janssen@amsterdam.nl']);
		$this->put('membership', 'm-marie', ['role' => 'member', 'party' => 'D66', 'person' => 'marie', 'governanceBody' => 'raad', 'startDate' => '2022-03-16T00:00:00Z']);
		$this->put('meeting', 'raad-april', ['title' => 'Raadsvergadering april', 'governanceBody' => 'raad']);
		$this->put('decision', 'groen-dak', ['title' => 'Groen dak op het stadhuis', 'decisionType' => 'motion', 'isPublished' => 'public', 'meeting' => 'raad-april']);
		$this->put('decision-stage', 'stage-groen-dak', ['decision' => 'groen-dak', 'votingRound' => 'r-open']);
		$this->put('voting-round', 'r-open', ['isSecret' => false, 'closedAt' => '2025-04-10T21:05:00Z', 'result' => 'adopted', 'decisionStage' => 'stage-groen-dak']);
		$this->put('voting-round', 'r-secret', ['isSecret' => true, 'closedAt' => '2025-04-10T22:00:00Z', 'result' => 'adopted']);
		$this->put('vote', 'v-open', ['value' => 'for', 'castAt' => '2025-04-10T21:01:00Z', 'votingRound' => 'r-open', 'participant' => 'p-marie']);
		$this->put('vote', 'v-secret', ['value' => 'against', 'castAt' => '2025-04-10T21:55:00Z', 'votingRound' => 'r-secret', 'participant' => 'p-marie']);
	}//end setUp()

	/**
	 * Scenario "A member reads a colleague's record": the open vote is listed
	 * with date, decision, choice, result and party; the secret one is not.
	 *
	 * @return void
	 */
	public function testListsTheOpenVoteAndLeavesOutTheSecretRound(): void {
		$record = $this->service()->forPerson(personId: 'marie');

		self::assertCount(1, $record);
		self::assertSame('v-open', $record[0]['vote']);
		self::assertSame('2025-04-10T21:01:00Z', $record[0]['date']);
		self::assertSame(['id' => 'groen-dak', 'title' => 'Groen dak op het stadhuis'], $record[0]['decision']);
		self::assertSame('for', $record[0]['choice']);
		self::assertSame('adopted', $record[0]['result']);
		self::assertSame('D66', $record[0]['party']);
		self::assertSame('raad', $record[0]['body']);
	}//end testListsTheOpenVoteAndLeavesOutTheSecretRound()

	/**
	 * An anonymised vote (value nulled on close) and a round still open are absent.
	 *
	 * @return void
	 */
	public function testLeavesOutAnonymisedVotesAndOpenRounds(): void {
		$this->put('voting-round', 'r-anon', ['isSecret' => false, 'closedAt' => '2025-05-01T20:00:00Z', 'result' => 'rejected']);
		$this->put('vote', 'v-anon', ['value' => null, 'castAt' => '2025-05-01T19:59:00Z', 'votingRound' => 'r-anon', 'participant' => 'p-marie']);
		$this->put('voting-round', 'r-running', ['isSecret' => false, 'result' => null]);
		$this->put('vote', 'v-running', ['value' => 'for', 'castAt' => '2025-06-01T19:59:00Z', 'votingRound' => 'r-running', 'participant' => 'p-marie']);

		$record = $this->service()->forPerson(personId: 'marie');

		self::assertSame(['v-open'], array_column($record, 'vote'));
	}//end testLeavesOutAnonymisedVotesAndOpenRounds()

	/**
	 * A ballot cast in the app carries its voter in `relations` only; a proxy
	 * ballot counts for the member it was cast for, not for the proxy.
	 *
	 * @return void
	 */
	public function testReadsBallotsCastInTheAppAndCountsAProxyVoteForTheMember(): void {
		$this->put('participant', 'p-bas', ['displayName' => 'Bas Smit', 'role' => 'member', 'party' => 'VVD', 'email' => 'b.smit@amsterdam.nl']);
		$this->put('voting-round', 'r-app', ['isSecret' => false, 'closedAt' => '2025-09-01T20:00:00Z', 'result' => 'rejected']);
		$this->put('vote', 'v-app', $this->castBallot(roundId: 'r-app', participantId: 'p-marie', value: 'against'));
		$this->put('vote', 'v-proxy-for-bas', $this->castBallot(roundId: 'r-app', participantId: 'p-marie', value: 'for', delegatorId: 'p-bas'));

		$record = $this->service()->forPerson(personId: 'marie');

		self::assertSame(['v-app', 'v-open'], array_column($record, 'vote'), 'Newest first; the proxy ballot is Bas\'s vote.');
		self::assertSame('against', $record[0]['choice']);
		self::assertNull($record[0]['decision']);
	}//end testReadsBallotsCastInTheAppAndCountsAProxyVoteForTheMember()

	/**
	 * The party is the one of the membership active on the vote's date.
	 *
	 * @return void
	 */
	public function testThePartyIsTheOneAtTheTimeOfTheVote(): void {
		$this->put('membership', 'm-marie', ['role' => 'member', 'party' => 'D66', 'person' => 'marie', 'governanceBody' => 'raad', 'startDate' => '2022-03-16T00:00:00Z', 'endDate' => '2025-12-31T00:00:00Z']);
		$this->put('membership', 'm-marie-new', ['role' => 'member', 'party' => 'Volt', 'person' => 'marie', 'governanceBody' => 'raad', 'startDate' => '2026-01-01T00:00:00Z']);

		$record = $this->service()->forPerson(personId: 'marie');

		self::assertSame('D66', $record[0]['party']);
	}//end testThePartyIsTheOneAtTheTimeOfTheVote()

	/**
	 * Scenario "A person without participants": empty, and nothing created.
	 *
	 * @return void
	 */
	public function testAPersonMatchingNoParticipantHasAnEmptyRecordAndNothingIsCreated(): void {
		$this->put('person', 'nobody', ['name' => 'Niemand', 'email' => 'niemand@example.org', 'nextcloudUserId' => 'niemand']);
		$before = [count($this->store['person']), count($this->store['participant'])];

		$record = $this->service()->forPerson(personId: 'nobody');

		self::assertSame([], $record);
		self::assertSame($before, [count($this->store['person']), count($this->store['participant'])]);
		self::assertSame(0, $this->saves);
	}//end testAPersonMatchingNoParticipantHasAnEmptyRecordAndNothingIsCreated()

	/**
	 * The lookup matches on the Nextcloud user id first and only then on email.
	 *
	 * @return void
	 */
	public function testTheLookupPrefersTheNextcloudUserIdOverTheEmail(): void {
		$this->put('participant', 'p-other', ['displayName' => 'Andere Marie', 'role' => 'member', 'email' => 'm.janssen@amsterdam.nl']);
		$lookup = new PersonParticipantLookup(objectService: $this->objectService());

		self::assertSame(['p-marie'], $lookup->participantIdsOf(person: $this->store['person']['marie']));
		self::assertSame(
			['p-marie', 'p-other'],
			$lookup->participantIdsOf(person: ['name' => 'Marie Janssen', 'email' => 'm.janssen@amsterdam.nl'])
		);
		self::assertSame([], $lookup->participantIdsOf(person: ['name' => 'Zonder gegevens']));
	}//end testTheLookupPrefersTheNextcloudUserIdOverTheEmail()

	/**
	 * An unknown person has no record.
	 *
	 * @return void
	 */
	public function testAnUnknownPersonIsNull(): void {
		self::assertNull($this->service()->findPerson(personId: 'ghost'));
		self::assertSame('Marie Janssen', $this->service()->findPerson(personId: 'marie')['name']);
	}//end testAnUnknownPersonIsNull()

	/**
	 * A person the caller cannot read is refused as not found; a readable one passes.
	 *
	 * @return void
	 */
	public function testAPersonTheCallerCannotReadIsRefused(): void {
		$this->service()->requireReadablePerson(personId: 'marie');

		$this->expectException(DoesNotExistException::class);
		$this->service()->requireReadablePerson(personId: 'ghost');
	}//end testAPersonTheCallerCannotReadIsRefused()
}//end class
