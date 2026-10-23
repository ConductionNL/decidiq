<?php

/**
 * Decidiq public voting records
 *
 * The data behind the portal's "how did the members vote" page: the people
 * the ORI API may name, and per person their public votes with a summary.
 * Both lists are built on the two ORI publication rules, so the public site
 * and the ORI API can never disagree about who or what is public.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-001-decidiq-offers-each-public-members-voting-record-to-the-portal-without-a-session
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IL10N;
use OCP\L10N\IFactory;

/**
 * Lists the public members and builds one member's public voting record.
 *
 * Only what OriPersonPublicationRule names is listed, and only the votes
 * OriVotePublicationRule returns are counted: closed, not secret rounds on
 * published decisions in bodies that publish their voting records.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- two rules, the context reader, the voter rule, the object service and l10n.
 *
 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-001-decidiq-offers-each-public-members-voting-record-to-the-portal-without-a-session
 */
class PublicVotingRecords {

	/**
	 * The register every object lives in.
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The membership roles that are a public office, as the person rule reads them.
	 *
	 * @var array<string, string>
	 */
	private const ROLE_LABELS = [
		'chair' => 'Chair',
		'vice-chair' => 'Vice-chair',
		'secretary' => 'Secretary',
		'treasurer' => 'Treasurer',
		'member' => 'Member',
	];

	/**
	 * An ORI vote option as the reader sees it.
	 *
	 * @var array<string, string>
	 */
	private const CHOICES = [
		'yes' => 'For',
		'no' => 'Against',
		'abstain' => 'Abstained',
	];

	/**
	 * A round's result as the reader sees it.
	 *
	 * @var array<string, string>
	 */
	private const RESULTS = [
		'adopted' => 'Adopted',
		'rejected' => 'Rejected',
		'tied' => 'Tied',
		'invalid' => 'Invalid',
	];

	/**
	 * Decidiq's catalogue in the reader's language.
	 *
	 * @var IL10N
	 */
	private readonly IL10N $l10n;

	/**
	 * Constructor.
	 *
	 * @param OriPersonPublicationRule $personRule    Who may be named
	 * @param OriVotePublicationRule   $voteRule      Which votes are public
	 * @param VoteContextReader        $context       Reads one object in system context
	 * @param VoterEligibility         $voters        Which roles vote in which body
	 * @param ObjectServiceInterface   $objectService OpenRegister object service
	 * @param IFactory                 $l10nFactory   The reader's language
	 */
	public function __construct(
		private readonly OriPersonPublicationRule $personRule,
		private readonly OriVotePublicationRule $voteRule,
		private readonly VoteContextReader $context,
		private readonly VoterEligibility $voters,
		private readonly ObjectServiceInterface $objectService,
		IFactory $l10nFactory,
	) {
		$this->l10n = $l10nFactory->get('decidiq');
	}//end __construct()

	/**
	 * The public members who vote, as `{id, title, subtitle?}`, sorted by name.
	 *
	 * A person the ORI API names but who holds no voting office (a chair
	 * without a vote, a griffier) is not listed: there is no record to read.
	 *
	 * @return list<array<string, string>>
	 *
	 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-001-decidiq-offers-each-public-members-voting-record-to-the-portal-without-a-session
	 * @spec openspec/changes/voting-per-body-chair-and-staff/specs/voting-system/spec.md#requirement-req-vpb-001-a-body-declares-whether-its-chair-votes-and-staff-never-vote
	 */
	public function members(): array {
		$offices = $this->offices();
		$entries = [];
		foreach ($this->personRule->persons() as $person) {
			$name = ($person['name'] ?? null);
			if (is_string($name) === false || isset($offices[$person['id']]) === false) {
				continue;
			}

			$entry = ['id' => $person['id'], 'title' => $name];
			$subtitle = $this->subtitle(memberships: ($offices[$person['id']] ?? []));
			if ($subtitle !== '') {
				$entry['subtitle'] = $subtitle;
			}

			$entries[] = $entry;
		}

		usort($entries, static fn (array $a, array $b): int => strcmp($a['title'], $b['title']));

		return $entries;
	}//end members()

	/**
	 * One member's public voting record, or null for anyone the list does not name.
	 *
	 * @param string $personId The person id
	 *
	 * @return array<string, mixed>|null `{title, subtitle?, summary, columns, rows, note}`
	 *
	 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-001-decidiq-offers-each-public-members-voting-record-to-the-portal-without-a-session
	 * @spec openspec/changes/voting-per-body-chair-and-staff/specs/voting-system/spec.md#requirement-req-vpb-001-a-body-declares-whether-its-chair-votes-and-staff-never-vote
	 */
	public function record(string $personId): ?array {
		$person = $this->personRule->person(personId: $personId);
		$memberships = ($this->offices()[$personId] ?? []);
		if ($person === null || is_string($person['name'] ?? null) === false || $memberships === []) {
			return null;
		}

		$events = $this->publicEvents();
		$counts = ['yes' => 0, 'no' => 0, 'abstain' => 0];
		$rows = [];
		foreach ($this->voteRule->votes(voter: $personId) as $vote) {
			$event = ($events[$vote['vote_event']] ?? null);
			if ($event === null) {
				continue;
			}

			$counts[$vote['option']] = ($counts[$vote['option']] + 1);
			$rows[] = [
				'sortKey' => (string)$event['end_date'],
				'date' => $this->date(value: (string)$event['end_date']),
				'subject' => $event['subject'],
				'choice' => $this->l10n->t(self::CHOICES[$vote['option']]),
				'result' => $this->l10n->t((self::RESULTS[(string)$event['result']] ?? 'Unknown')),
			];
		}

		usort($rows, static fn (array $a, array $b): int => strcmp($b['sortKey'], $a['sortKey']));
		$rows = array_map(static function (array $row): array {
			unset($row['sortKey']);
			return $row;
		}, $rows);

		$record = [
			'title' => $person['name'],
			'summary' => $this->summary(counts: $counts, eligible: $this->eligibleRounds(events: $events, memberships: $memberships)),
			'columns' => [
				['key' => 'date', 'label' => $this->l10n->t('Date')],
				['key' => 'subject', 'label' => $this->l10n->t('Subject')],
				['key' => 'choice', 'label' => $this->l10n->t('Vote')],
				['key' => 'result', 'label' => $this->l10n->t('Result')],
			],
			'rows' => $rows,
			'note' => $this->l10n->t('Only open votes on published decisions. Secret votes are not shown here.'),
		];
		$subtitle = $this->subtitle(memberships: $memberships);
		if ($subtitle !== '') {
			$record['subtitle'] = $subtitle;
		}

		return $record;
	}//end record()

	/**
	 * The summary cards: participation, then the counts for, against and abstained.
	 *
	 * @param array<string, int> $counts   The member's public votes per option
	 * @param int                $eligible The public rounds the member could vote in
	 *
	 * @return list<array<string, string>>
	 */
	private function summary(array $counts, int $eligible): array {
		$cast = array_sum($counts);
		$share = 0;
		if ($eligible > 0) {
			$share = (int)round(min(100, ($cast * 100 / $eligible)));
		}

		return [
			[
				'label' => $this->l10n->t('Took part'),
				'value' => $share.'%',
				'detail' => $this->l10n->n('in %n open vote', 'in %n open votes', $eligible),
			],
			['label' => $this->l10n->t('For'), 'value' => (string)$counts['yes']],
			['label' => $this->l10n->t('Against'), 'value' => (string)$counts['no']],
			['label' => $this->l10n->t('Abstained'), 'value' => (string)$counts['abstain']],
		];
	}//end summary()

	/**
	 * How many public rounds were held in the member's bodies while they held office there.
	 *
	 * @param array<string, array<string, mixed>> $events      The public rounds by id
	 * @param list<array<string, mixed>>          $memberships The member's public offices
	 *
	 * @return int
	 */
	private function eligibleRounds(array $events, array $memberships): int {
		$eligible = 0;
		foreach ($events as $event) {
			foreach ($memberships as $membership) {
				$sameBody = (($membership['governanceBody'] ?? null) === $event['body']);
				if ($sameBody === true && self::activeOn(membership: $membership, date: (string)$event['end_date']) === true) {
					$eligible++;
					break;
				}
			}
		}

		return $eligible;
	}//end eligibleRounds()

	/**
	 * The open (not secret) public rounds by id, each with its body, its decision's title, date and result.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function publicEvents(): array {
		$events = [];
		foreach ($this->voteRule->voteEvents() as $event) {
			$round = $this->context->object(schema: 'voting-round', id: (string)$event['id']);
			$decision = $this->context->object(schema: 'decision', id: (string)$event['motion']);
			// A secret round's totals are public, its ballots never are: it is
			// no round a member's record can show, so it is not counted either.
			if ($round === null || $decision === null || ($round['isSecret'] ?? true) !== false) {
				continue;
			}

			$events[(string)$event['id']] = [
				'end_date' => $event['end_date'],
				'result' => ($event['result'] ?? ''),
				'subject' => (string)($decision['title'] ?? ''),
				'body' => $this->context->bodyOfRound(roundId: (string)$event['id'], round: $round, decision: $decision),
			];
		}

		return $events;
	}//end publicEvents()

	/**
	 * The voting offices per person: memberships in a body that publishes its
	 * voting records, in a role that votes in that body (VoterEligibility).
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function offices(): array {
		$entities = $this->objectService->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => 'membership'], 'limit' => 500],
			_rbac: false,
			_multitenancy: false
		);

		$offices = [];
		foreach ($entities as $entity) {
			$membership = $entity->jsonSerialize();
			$personId = ($membership['person'] ?? null);
			$bodyId = ($membership['governanceBody'] ?? null);
			if (is_string($personId) === false || is_string($bodyId) === false || isset(self::ROLE_LABELS[($membership['role'] ?? '')]) === false) {
				continue;
			}

			$body = $this->context->object(schema: 'governance-body', id: $bodyId);
			if (($body['publishVotingRecords'] ?? false) !== true || $this->voters->mayVote(role: $membership['role'], body: $body) === false) {
				continue;
			}

			$membership['bodyName'] = (string)($body['name'] ?? '');
			$offices[$personId][] = $membership;
		}

		return $offices;
	}//end offices()

	/**
	 * "Member, Green party" for the person's current public office, or ''.
	 *
	 * @param list<array<string, mixed>> $memberships The person's public offices
	 *
	 * @return string
	 */
	private function subtitle(array $memberships): string {
		$today = date('Y-m-d');
		$current = null;
		foreach ($memberships as $membership) {
			if (self::activeOn(membership: $membership, date: $today) === true) {
				$current = $membership;
				break;
			}
		}

		if ($current === null) {
			return '';
		}

		$role = trim((string)($current['label'] ?? ''));
		if ($role === '') {
			$role = $this->l10n->t(self::ROLE_LABELS[$current['role']]);
		}

		$parts = [$role, trim((string)($current['party'] ?? '')), $current['bodyName']];

		return implode(', ', array_filter($parts, static fn (string $part): bool => $part !== ''));
	}//end subtitle()

	/**
	 * A date as the reader reads it ("9 oktober 2026" in Dutch), or the raw value when it cannot be read.
	 *
	 * @param string $value An ISO date or date-time
	 *
	 * @return string
	 */
	private function date(string $value): string {
		$time = strtotime($value);
		if ($time === false) {
			return $value;
		}

		return (string)$this->l10n->l('date', $time, ['width' => 'long']);
	}//end date()

	/**
	 * Whether a membership ran on a date (ISO strings compare by their date part).
	 *
	 * @param array<string, mixed> $membership The membership
	 * @param string               $date       An ISO date or date-time
	 *
	 * @return bool
	 */
	private static function activeOn(array $membership, string $date): bool {
		$day = substr($date, 0, 10);
		$start = substr((string)($membership['startDate'] ?? ''), 0, 10);
		$end = substr((string)($membership['endDate'] ?? ''), 0, 10);

		return ($start === '' || $start <= $day) && ($end === '' || $end >= $day);
	}//end activeOn()
}//end class
