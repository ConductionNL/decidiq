<?php

/**
 * Unit tests for the public record lists decidiq offers the portal.
 *
 * Portaliq's public records block calls the provider methods a contribution's
 * `publicRecords` names, without a session. These tests call the same methods
 * on the provider, built with the real ORI rules and record services over an
 * in-memory OpenRegister that, like OpenRegister for an anonymous caller,
 * answers nothing unless asked in system context.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-public-council-records/specs/portal-contribution/spec.md
 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-001-decidiq-offers-each-public-members-voting-record-to-the-portal-without-a-session
 * @spec openspec/changes/voting-per-body-chair-and-staff/specs/voting-system/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Portal;

use OCA\Decidiq\Portal\PortalContributionProvider;
use OCA\Decidiq\Service\OriPersonPublicationRule;
use OCA\Decidiq\Service\OriVotePublicationRule;
use OCA\Decidiq\Service\PersonParticipantLookup;
use OCA\Decidiq\Service\ObjectRelationFilter;
use OCA\Decidiq\Service\PublicMeetingRecords;
use OCA\Decidiq\Service\PublicVotingRecords;
use OCA\Decidiq\Service\VoteContextReader;
use OCA\Decidiq\Service\VoterEligibility;
use OCA\Decidiq\Service\VotingRecordService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The meetings, decisions and voting records a visitor reads without signing in.
 *
 * @spec openspec/changes/portal-public-council-records/specs/portal-contribution/spec.md
 */
final class PortalPublicRecordsTest extends TestCase {

	/**
	 * Objects per schema: schema => id => data.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * A council that publishes its votes, a member who voted in an open round
	 * on a published motion, a board that publishes nothing and a guest; and
	 * the publications staff made.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [];
		$past = gmdate('Y-m-d\TH:i:s\Z', (time() - 86400));
		$future = gmdate('Y-m-d\TH:i:s\Z', (time() + 86400));

		$this->put('governance-body', 'raad', ['name' => 'Gemeenteraad Voorbeeld', 'publishVotingRecords' => true]);
		$this->put('governance-body', 'rvc', ['name' => 'Raad van commissarissen', 'publishVotingRecords' => false]);
		$this->put('person', 'marie', ['name' => 'Marie Janssen', 'email' => 'marie@voorbeeld.example']);
		$this->put('person', 'kees', ['name' => 'Kees Bakker']);
		$this->put('person', 'gerda', ['name' => 'Gerda Gast']);
		$this->put('participant', 'p-marie', ['displayName' => 'Marie Janssen', 'role' => 'member', 'party' => 'Groen Voorbeeld', 'email' => 'marie@voorbeeld.example']);
		$this->put('membership', 'm-marie', ['role' => 'member', 'party' => 'Groen Voorbeeld', 'person' => 'marie', 'governanceBody' => 'raad', 'startDate' => '2022-03-16T00:00:00Z']);
		$this->put('membership', 'm-kees', ['role' => 'member', 'person' => 'kees', 'governanceBody' => 'rvc', 'startDate' => '2023-01-01T00:00:00Z']);
		$this->put('membership', 'm-gerda', ['role' => 'guest', 'person' => 'gerda', 'governanceBody' => 'raad', 'startDate' => '2025-01-01T00:00:00Z']);

		$this->put('decision', 'groen-dak', ['title' => 'Groen dak op het stadhuis', 'decisionType' => 'motion', 'isPublished' => 'public']);
		$this->put('decision-stage', 'stage-groen-dak', ['sequence' => 1, 'stageType' => 'decisive', 'status' => 'decided', 'decisionMakerType' => 'body', 'decision' => 'groen-dak', 'votingRound' => 'r-open', 'assignedBody' => 'raad']);
		$this->put('voting-round', 'r-open', ['isSecret' => false, 'openedAt' => '2025-04-10T20:55:00Z', 'closedAt' => '2025-04-10T21:05:00Z', 'result' => 'adopted', 'decisionStage' => 'stage-groen-dak', 'votesFor' => 23, 'votesAgainst' => 8, 'votesAbstain' => 1]);
		$this->put('vote', 'v-marie', ['value' => 'for', 'castAt' => '2025-04-10T21:01:00Z', 'votingRound' => 'r-open', 'participant' => 'p-marie']);

		$this->put('decision', 'benoeming', ['title' => 'Benoeming griffier', 'decisionType' => 'resolution', 'isPublished' => 'public']);
		$this->put('decision-stage', 'stage-benoeming', ['sequence' => 1, 'stageType' => 'decisive', 'status' => 'decided', 'decisionMakerType' => 'body', 'decision' => 'benoeming', 'votingRound' => 'r-secret', 'assignedBody' => 'raad']);
		$this->put('voting-round', 'r-secret', ['isSecret' => true, 'openedAt' => '2025-05-01T20:00:00Z', 'closedAt' => '2025-05-01T20:10:00Z', 'result' => 'adopted', 'decisionStage' => 'stage-benoeming']);
		$this->put('vote', 'v-marie-secret', ['value' => 'against', 'castAt' => '2025-05-01T20:05:00Z', 'votingRound' => 'r-secret', 'participant' => 'p-marie']);

		$this->put('publication-payload', 'pub-raad-1', ['oriType' => 'Vergadering', 'title' => 'Raadsvergadering', 'bodyName' => 'Gemeenteraad Voorbeeld', 'meetingDate' => '2025-04-10T19:30:00Z', 'location' => 'Raadzaal', 'agendaItems' => [['oriType' => 'AgendaPunt', 'order' => 2, 'title' => 'Groen dak op het stadhuis'], ['oriType' => 'AgendaPunt', 'order' => 1, 'title' => 'Opening']], 'publicationDate' => $past]);
		$this->put('publication-payload', 'pub-raad-2', ['oriType' => 'Vergadering', 'title' => 'Raadsvergadering', 'bodyName' => 'Gemeenteraad Voorbeeld', 'meetingDate' => '2025-05-01T19:30:00Z', 'publicationDate' => $past]);
		$this->put('publication-payload', 'pub-scheduled', ['oriType' => 'Vergadering', 'title' => 'Besloten vergadering', 'bodyName' => 'Gemeenteraad Voorbeeld', 'meetingDate' => '2025-06-01T19:30:00Z', 'publicationDate' => $future]);
		$this->put('publication-payload', 'pub-withdrawn', ['oriType' => 'Vergadering', 'title' => 'Ingetrokken vergadering', 'bodyName' => 'Gemeenteraad Voorbeeld', 'meetingDate' => '2025-06-02T19:30:00Z', 'publicationDate' => $past, 'depublicationDate' => $past]);
		$this->put('publication-payload', 'pub-groen-dak', ['oriType' => 'Besluit', 'title' => 'Groen dak op het stadhuis', 'text' => 'De raad verzoekt het college een groen dak aan te leggen.', 'outcome' => 'adopted', 'decisionDate' => '2025-04-10T21:05:00Z', 'bodyName' => 'Gemeenteraad Voorbeeld', 'voteTotals' => ['for' => 23, 'against' => 8, 'abstain' => 1], 'publicationDate' => $past]);
		$this->put('publication-payload', 'pub-college', ['oriType' => 'Besluit', 'title' => 'Opdracht aanleg', 'text' => 'Het college geeft de opdracht.', 'outcome' => 'adopted', 'decisionDate' => '2025-04-15T10:00:00Z', 'bodyName' => 'College', 'voteTotals' => ['for' => 0, 'against' => 0, 'abstain' => 0], 'publicationDate' => $past]);
	}//end setUp()

	/**
	 * Every list names two provider methods portaliq can call: public, not
	 * static, at most one required argument (portaliq's TimelineProviderMethod rule).
	 *
	 * @return void
	 */
	public function testEveryListNamesMethodsPortaliqCanCall(): void {
		$manifest = $this->provider()->getContribution(['audience' => 'citizen']);

		self::assertIsArray(actual: $manifest);
		self::assertSame(expected: ['publicMeetings', 'publicDecisions', 'memberVotingRecords'], actual: array_column($manifest['publicRecords'], 'id'));
		foreach ($manifest['publicRecords'] as $entry) {
			foreach ([$entry['listProvider'], $entry['recordProvider']] as $method) {
				self::assertMatchesRegularExpression(pattern: '/^[a-z][A-Za-z0-9]*$/', string: $method);
				$reflection = new ReflectionMethod(PortalContributionProvider::class, $method);
				self::assertTrue(condition: $reflection->isPublic() && $reflection->isStatic() === false, message: $method.' must be a public instance method');
				self::assertLessThanOrEqual(expected: 1, actual: $reflection->getNumberOfRequiredParameters(), message: $method);
			}
		}
	}//end testEveryListNamesMethodsPortaliqCanCall()

	/**
	 * The list names exactly the public role holders: not a member of a body
	 * that publishes no votes, not a guest.
	 *
	 * @return void
	 */
	public function testTheMemberListNamesPublicRoleHoldersOnly(): void {
		$members = $this->provider()->publicMembers();

		self::assertSame(expected: [['id' => 'marie', 'title' => 'Marie Janssen', 'subtitle' => 'Member, Groen Voorbeeld, Gemeenteraad Voorbeeld']], actual: $members);
	}//end testTheMemberListNamesPublicRoleHoldersOnly()

	/**
	 * A member's record holds her open vote with its subject, choice and
	 * result, and the summary counts it; her secret vote is neither shown nor counted.
	 *
	 * @return void
	 */
	public function testAMembersRecordHoldsHerOpenVotesOnly(): void {
		$record = $this->provider()->publicVotingRecord('marie');

		self::assertIsArray(actual: $record);
		self::assertSame(expected: 'Marie Janssen', actual: $record['title']);
		self::assertSame(expected: [['date' => 'long:2025-04-10', 'subject' => 'Groen dak op het stadhuis', 'choice' => 'For', 'result' => 'Adopted']], actual: $record['rows']);
		self::assertSame(expected: ['date', 'subject', 'choice', 'result'], actual: array_column($record['columns'], 'key'));
		$cards = array_column($record['summary'], 'value', 'label');
		self::assertSame(expected: ['Took part' => '100%', 'For' => '1', 'Against' => '0', 'Abstained' => '0'], actual: $cards, message: 'The secret against-vote is not counted');
		self::assertStringContainsString(needle: 'Secret votes', haystack: $record['note']);
	}//end testAMembersRecordHoldsHerOpenVotesOnly()

	/**
	 * Nobody the list does not name has a record.
	 *
	 * @return void
	 */
	public function testNoRecordForSomeoneTheListDoesNotName(): void {
		$provider = $this->provider();

		self::assertNull(actual: $provider->publicVotingRecord('kees'));
		self::assertNull(actual: $provider->publicVotingRecord('gerda'));
		self::assertNull(actual: $provider->publicVotingRecord('nobody'));
	}//end testNoRecordForSomeoneTheListDoesNotName()

	/**
	 * A council whose chair has no vote lists neither its chair (the mayor)
	 * nor its secretary (the griffier), and neither has a record.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-per-body-chair-and-staff/specs/voting-system/spec.md#requirement-req-vpb-001-a-body-declares-whether-its-chair-votes-and-staff-never-vote
	 */
	public function testACouncilChairWithoutAVoteAndTheGriffierAreNotListed(): void {
		$this->store['governance-body']['raad']['chairHasVote'] = false;
		$this->put('person', 'mayor', ['name' => 'Anna Burgemeester']);
		$this->put('person', 'griffier', ['name' => 'Bram Griffier']);
		$this->put('membership', 'm-mayor', ['role' => 'chair', 'person' => 'mayor', 'governanceBody' => 'raad', 'startDate' => '2022-03-16T00:00:00Z']);
		$this->put('membership', 'm-griffier', ['role' => 'secretary', 'person' => 'griffier', 'governanceBody' => 'raad', 'startDate' => '2022-03-16T00:00:00Z']);
		$provider = $this->provider();

		self::assertSame(expected: ['marie'], actual: array_column($provider->publicMembers(), 'id'));
		self::assertNull(actual: $provider->publicVotingRecord('mayor'));
		self::assertNull(actual: $provider->publicVotingRecord('griffier'));
	}//end testACouncilChairWithoutAVoteAndTheGriffierAreNotListed()

	/**
	 * A committee that says nothing about its chair keeps the chair's vote:
	 * the chair is listed; its secretary still is not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-per-body-chair-and-staff/specs/voting-system/spec.md#requirement-req-vpb-001-a-body-declares-whether-its-chair-votes-and-staff-never-vote
	 */
	public function testACommitteeChairVotesUnlessTheBodySaysOtherwise(): void {
		$this->put('governance-body', 'commissie', ['name' => 'Commissie ruimte', 'publishVotingRecords' => true]);
		$this->put('person', 'voorzitter', ['name' => 'Cor Voorzitter']);
		$this->put('person', 'secretaris', ['name' => 'Sara Secretaris']);
		$this->put('membership', 'm-voorzitter', ['role' => 'chair', 'person' => 'voorzitter', 'governanceBody' => 'commissie', 'startDate' => '2022-03-16T00:00:00Z']);
		$this->put('membership', 'm-secretaris', ['role' => 'secretary', 'person' => 'secretaris', 'governanceBody' => 'commissie', 'startDate' => '2022-03-16T00:00:00Z']);

		$members = $this->provider()->publicMembers();

		self::assertSame(expected: ['voorzitter', 'marie'], actual: array_column($members, 'id'));
		self::assertSame(expected: 'Chair, Commissie ruimte', actual: $members[0]['subtitle']);
	}//end testACommitteeChairVotesUnlessTheBodySaysOtherwise()

	/**
	 * A round whose stage names a decision that does not exist (by slug, or
	 * deleted) is skipped; the member's other votes still show.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-per-body-chair-and-staff/specs/voting-system/spec.md#requirement-req-vpb-002-a-dangling-reference-skips-one-object-not-the-whole-list
	 */
	public function testADanglingDecisionReferenceSkipsOnlyThatRound(): void {
		$this->put('decision-stage', 'stage-dangling', ['sequence' => 1, 'stageType' => 'decisive', 'decision' => 'besluit-by-slug', 'votingRound' => 'r-dangling', 'assignedBody' => 'raad']);
		$this->put('voting-round', 'r-dangling', ['isSecret' => false, 'openedAt' => '2025-06-10T20:00:00Z', 'closedAt' => '2025-06-10T20:10:00Z', 'result' => 'adopted', 'decisionStage' => 'stage-dangling']);
		$this->put('vote', 'v-marie-dangling', ['value' => 'against', 'castAt' => '2025-06-10T20:05:00Z', 'votingRound' => 'r-dangling', 'participant' => 'p-marie']);

		$record = $this->provider()->publicVotingRecord('marie');

		self::assertIsArray(actual: $record);
		self::assertSame(expected: ['Groen dak op het stadhuis'], actual: array_column($record['rows'], 'subject'));
	}//end testADanglingDecisionReferenceSkipsOnlyThatRound()

	/**
	 * The shipped example set, loaded as-is: every voting councillor has a
	 * record with one named vote per public round, the votes add up to each
	 * round's totals, and the mayor and the griffier are not listed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-per-body-chair-and-staff/specs/voting-system/spec.md#requirement-req-vpb-001-a-body-declares-whether-its-chair-votes-and-staff-never-vote
	 */
	public function testTheExampleSetShowsEveryCouncillorsNamedVotes(): void {
		$this->store = [];
		$path = dirname(__DIR__, 3).'/lib/Settings/example-sets/zuiddrecht.json';
		$set = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		foreach ($set['objects'] as $row) {
			if ($row['register'] === 'decidiq') {
				$this->put($row['schema'], $row['key'], self::resolve(value: $row['data']));
			}
		}

		$provider = $this->provider();
		$members = array_column($provider->publicMembers(), 'id');
		$voters = [];
		foreach ($this->store['membership'] as $membership) {
			if ($membership['governanceBody'] === 'body-gemeenteraad' && $membership['role'] === 'member') {
				$voters[] = $membership['person'];
			}
		}

		sort($members);
		sort($voters);
		self::assertCount(expectedCount: 27, haystack: $voters);
		self::assertSame(expected: $voters, actual: $members, message: 'Only the voting councillors are listed: no mayor, no griffier');

		$tally = [];
		foreach ($voters as $personId) {
			$record = $provider->publicVotingRecord($personId);
			self::assertIsArray(actual: $record, message: $personId);
			self::assertCount(expectedCount: 3, haystack: $record['rows'], message: $personId.' votes in each public round');
			foreach ($record['rows'] as $row) {
				$tally[$row['subject']][$row['choice']] = (($tally[$row['subject']][$row['choice']] ?? 0) + 1);
			}
		}

		$tallied = 0;
		foreach ($this->store['decision-stage'] as $stage) {
			// A stage decided without a vote (drafting, initials, signature) has no round to tally.
			if (isset($stage['votingRound']) === false) {
				continue;
			}

			$round = $this->store['voting-round'][$stage['votingRound']];
			$subject = $this->store['decision'][$stage['decision']]['title'];
			$expected = array_filter(['For' => $round['votesFor'], 'Against' => $round['votesAgainst'], 'Abstained' => $round['votesAbstain']]);
			$actual = $tally[$subject];
			ksort($expected);
			ksort($actual);
			self::assertSame(expected: $expected, actual: $actual, message: $subject.': the named votes add up to the totals');
			$tallied++;
		}

		self::assertSame(expected: 3, actual: $tallied, message: 'Each of the three public rounds is checked against its totals');
	}//end testTheExampleSetShowsEveryCouncillorsNamedVotes()

	/**
	 * Resolve the example-set placeholders the way the installer does, close enough
	 * for a read: an object reference becomes the object's key, a relative
	 * datetime becomes that many days from now at the given time.
	 *
	 * @param mixed $value A fragment value
	 *
	 * @return mixed
	 */
	private static function resolve(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(static fn (mixed $item): mixed => self::resolve(value: $item), $value);
		}

		if (is_string($value) === false) {
			return $value;
		}

		if (preg_match('/^\{\{object:([^.}]+)\.id\}\}$/', $value, $match) === 1) {
			return $match[1];
		}

		if (preg_match('/^\{\{datetime:([+-]\d+)[^ }]*(?: (\d\d:\d\d))?\}\}$/', $value, $match) === 1) {
			return gmdate('Y-m-d', (time() + ((int)$match[1] * 86400))).'T'.($match[2] ?? '00:00').':00Z';
		}

		return $value;
	}//end resolve()

	/**
	 * Only meetings staff published and did not withdraw are listed, latest first.
	 *
	 * @return void
	 */
	public function testOnlyLivePublishedMeetingsAreListed(): void {
		$meetings = $this->provider()->publicMeetings();

		self::assertSame(expected: ['pub-raad-2', 'pub-raad-1'], actual: array_column($meetings, 'id'));
		self::assertSame(expected: 'Raadsvergadering long:2025-04-10', actual: $meetings[1]['title']);
		self::assertSame(expected: 'Gemeenteraad Voorbeeld, Raadzaal', actual: $meetings[1]['subtitle']);
	}//end testOnlyLivePublishedMeetingsAreListed()

	/**
	 * A meeting shows its agenda in order; one without an agenda says so; an
	 * unpublished or withdrawn one is not found.
	 *
	 * @return void
	 */
	public function testAMeetingShowsItsPublishedAgenda(): void {
		$provider = $this->provider();
		$meeting = $provider->publicMeeting('pub-raad-1');

		self::assertIsArray(actual: $meeting);
		self::assertSame(expected: [['order' => '1', 'item' => 'Opening'], ['order' => '2', 'item' => 'Groen dak op het stadhuis']], actual: $meeting['rows']);
		self::assertArrayNotHasKey(key: 'note', array: $meeting);
		self::assertSame(expected: 'The agenda of this meeting has not been published yet.', actual: $provider->publicMeeting('pub-raad-2')['note']);
		self::assertNull(actual: $provider->publicMeeting('pub-scheduled'));
		self::assertNull(actual: $provider->publicMeeting('pub-withdrawn'));
	}//end testAMeetingShowsItsPublishedAgenda()

	/**
	 * A decision shows its outcome and text, and the vote totals only when there was a vote.
	 *
	 * @return void
	 */
	public function testADecisionShowsItsOutcomeAndTotals(): void {
		$provider = $this->provider();

		self::assertSame(expected: ['pub-college', 'pub-groen-dak'], actual: array_column($provider->publicDecisions(), 'id'));
		$motion = $provider->publicDecision('pub-groen-dak');
		self::assertIsArray(actual: $motion);
		self::assertSame(expected: ['Date' => 'long:2025-04-10', 'Outcome' => 'Adopted', 'For' => '23', 'Against' => '8', 'Abstained' => '1'], actual: array_column($motion['summary'], 'value', 'label'));
		self::assertSame(expected: 'De raad verzoekt het college een groen dak aan te leggen.', actual: $motion['note']);
		self::assertSame(expected: ['Date', 'Outcome'], actual: array_column($provider->publicDecision('pub-college')['summary'], 'label'));
		self::assertNull(actual: $provider->publicDecision('pub-raad-1'), message: 'A meeting id is not a decision');
	}//end testADecisionShowsItsOutcomeAndTotals()

	/**
	 * Without decidiq's services (plain `new`) every list is empty and no record exists.
	 *
	 * @return void
	 */
	public function testWithoutServicesTheListsAreEmpty(): void {
		$provider = new PortalContributionProvider();

		self::assertSame(expected: [], actual: $provider->publicMembers());
		self::assertSame(expected: [], actual: $provider->publicMeetings());
		self::assertSame(expected: [], actual: $provider->publicDecisions());
		self::assertNull(actual: $provider->publicVotingRecord('marie'));
		self::assertNull(actual: $provider->publicMeeting('pub-raad-1'));
		self::assertNull(actual: $provider->publicDecision('pub-groen-dak'));
	}//end testWithoutServicesTheListsAreEmpty()

	/**
	 * The provider as the container builds it, over the in-memory store.
	 *
	 * @return PortalContributionProvider
	 */
	private function provider(): PortalContributionProvider {
		$objectService = $this->objectService();
		$people = new PersonParticipantLookup(objectService: $objectService);
		$context = new VoteContextReader(objectService: $objectService);
		$records = new VotingRecordService(objectService: $objectService, participants: $people, relationFilter: new ObjectRelationFilter(), context: $context);
		$factory = $this->factory();

		return new PortalContributionProvider(
			l10nFactory: $factory,
			votingRecords: new PublicVotingRecords(
				personRule: new OriPersonPublicationRule(context: $context, objectService: $objectService),
				voteRule: new OriVotePublicationRule(records: $records, context: $context, people: $people, objectService: $objectService),
				context: $context,
				voters: new VoterEligibility(),
				objectService: $objectService,
				l10nFactory: $factory,
			),
			meetingRecords: new PublicMeetingRecords(objectService: $objectService, l10nFactory: $factory),
		);
	}//end provider()

	/**
	 * A factory whose catalogue answers the English source and marks a long date.
	 *
	 * @return IFactory
	 */
	private function factory(): IFactory {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$l10n->method('n')->willReturnCallback(static fn (string $one, string $many, int $count): string => str_replace('%n', (string)$count, ($count === 1 ? $one : $many)));
		$l10n->method('l')->willReturnCallback(static fn (string $type, $data, array $options = []): string => ($options['width'] ?? '').':'.gmdate(($type === 'time' ? 'H:i' : 'Y-m-d'), (int)$data));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		return $factory;
	}//end factory()

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
	 * An ObjectServiceInterface over the store that answers system-context reads only.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null, bool $_rbac = true): ?ObjectEntity {
				$data = ($this->store[(string)$schema][(string)$id] ?? null);
				if ($_rbac === true) {
					return null;
				}

				// Like OpenRegister: a missing object throws, it does not read as null.
				if ($data === null) {
					throw new DoesNotExistException('Object not found in magic table');
				}

				return self::entity(schema: (string)$schema, id: (string)$id, data: $data);
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true): array {
				if ($_rbac === true) {
					return [];
				}

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
		$objectService->method('saveObject')->willThrowException(new \RuntimeException('A public read must never write.'));

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
				if (in_array($value, array_column(($data['relations'] ?? []), 'id'), true) === false) {
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
}//end class
