<?php

/**
 * Turns an incoming roster into suggested member onboardings and
 * offboardings for one governance body, and creates the ones the
 * secretariat confirms.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use OCA\Decidiq\Service\Pathway\PathwaySteps;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The incoming roster is the list the member import reads (a CSV or a
 * Nextcloud group): one row per person with a name, an email and/or a
 * Nextcloud account, and a role. People are matched by account, else by
 * email, case-insensitively.
 *
 * `suggest()` only reads: an onboarding suggestion for each roster person
 * without an active membership of the body, an offboarding suggestion for
 * each active member who is not on the roster. `confirm()` computes the same
 * diff again from the current state and creates only the confirmed
 * suggestions that are still valid, all with one batch label; a suggestion
 * that no longer holds (a membership was added or ended in between, or an
 * open onboarding or offboarding already exists) is returned as rejected,
 * never applied twice. Neither ever creates or ends a membership: that is a
 * step on each created offboarding. The only other write is a Person record
 * for a joining person who has none yet.
 *
 * Every read is one bulk query per record type, so a large body does not
 * cost a query per member.
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
 */
class RosterTurnoverService {

	/**
	 * The trigger written on every record the run creates.
	 *
	 * @var string
	 */
	public const TRIGGER = 'council-turnover-batch';

	/**
	 * The register.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * Upper bound of one bulk read.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

	/**
	 * The steps a new onboarding starts with.
	 *
	 * @var list<array{stepType: string, title: string, mandatory: bool}>
	 */
	private const ONBOARDING_STEPS = [
		['stepType' => 'installation', 'title' => 'Installation', 'mandatory' => true],
		['stepType' => 'account-linking', 'title' => 'Link the account', 'mandatory' => true],
		['stepType' => 'assign-groups', 'title' => 'Assign groups', 'mandatory' => true],
		['stepType' => 'induction-pack', 'title' => 'Induction pack', 'mandatory' => false],
		['stepType' => 'ancillary-positions-intake', 'title' => 'Ancillary positions', 'mandatory' => false],
	];

	/**
	 * The steps a new offboarding starts with.
	 *
	 * @var list<array{stepType: string, title: string, mandatory: bool}>
	 */
	private const OFFBOARDING_STEPS = [
		['stepType' => 'end-membership', 'title' => 'End the membership', 'mandatory' => true],
		['stepType' => 'revoke-groups', 'title' => 'Revoke groups', 'mandatory' => true],
		['stepType' => 'personal-data-note', 'title' => 'Personal data note', 'mandatory' => true],
		['stepType' => 'exit-confirmation', 'title' => 'Confirm the exit', 'mandatory' => true],
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param ITimeFactory           $time          The clock.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Whether the governance body exists (and the caller may read it).
	 *
	 * @param string $bodyId The governance body uuid.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	public function bodyExists(string $bodyId): bool {
		if (trim($bodyId) === '') {
			return false;
		}

		try {
			return $this->objectService->find(id: $bodyId, register: self::REGISTER, schema: 'governance-body') !== null;
		} catch (\Throwable) {
			return false;
		}
	}//end bodyExists()

	/**
	 * The suggestion list for a body and an incoming roster. Writes nothing.
	 *
	 * @param string            $bodyId The governance body uuid.
	 * @param array<int, mixed> $rows   The incoming roster.
	 *
	 * @return array{joining: list<array<string,mixed>>, leaving: list<array<string,mixed>>, ignored: list<array<string,mixed>>}
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	public function suggest(string $bodyId, array $rows): array {
		$roster  = $this->roster(rows: $rows);
		$members = $this->activeMembers(bodyId: $bodyId);
		$open    = $this->openPathways(bodyId: $bodyId);
		$persons = $this->personsFor(roster: $roster['people']);

		$memberKeys = [];
		foreach ($members as $member) {
			foreach ($member['keys'] as $key) {
				$memberKeys[$key] = true;
			}
		}

		$joining = [];
		foreach ($roster['people'] as $key => $person) {
			if ($this->anyKey(keys: $person['keys'], set: $memberKeys) === true) {
				continue;
			}

			$personId = $this->personIdFor(keys: $person['keys'], persons: $persons);
			if ($personId !== '' && isset($open['onboarding'][$personId]) === true) {
				continue;
			}

			$joining[] = [
				'key'             => $key,
				'name'            => $person['name'],
				'email'           => $person['email'],
				'nextcloudUserId' => $person['nextcloudUserId'],
				'role'            => $person['role'],
				'personId'        => $personId,
			];
		}

		$rosterKeys = [];
		foreach ($roster['people'] as $person) {
			foreach ($person['keys'] as $key) {
				$rosterKeys[$key] = true;
			}
		}

		$leaving = [];
		foreach ($members as $member) {
			if ($this->anyKey(keys: $member['keys'], set: $rosterKeys) === true
				|| isset($open['offboarding'][$member['membershipId']]) === true
			) {
				continue;
			}

			$leaving[] = [
				'membershipId' => $member['membershipId'],
				'personId'     => $member['personId'],
				'name'         => $member['name'],
				'role'         => $member['role'],
			];
		}

		return ['joining' => $joining, 'leaving' => $leaving, 'ignored' => $roster['ignored']];
	}//end suggest()

	/**
	 * Create the confirmed suggestions that still hold, under one batch label.
	 *
	 * @param string            $bodyId   The governance body uuid.
	 * @param array<int, mixed> $rows     The same incoming roster.
	 * @param list<string>      $joining  The confirmed joining keys.
	 * @param list<string>      $leaving  The confirmed leaving membership uuids.
	 * @param string            $batch    The batch label.
	 * @param string            $endDate  The end date for the leaving members (Y-m-d).
	 * @param string            $endReason Why their membership ends.
	 *
	 * @return array{onboardings: list<string>, offboardings: list<string>, rejected: list<array{suggestion: string, reason: string}>}
	 *
	 * @throws \InvalidArgumentException When the batch label is empty, or leaving members are confirmed without a valid end date.
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	public function confirm(
		string $bodyId,
		array $rows,
		array $joining,
		array $leaving,
		string $batch,
		string $endDate,
		string $endReason
	): array {
		$batch = trim($batch);
		if ($batch === '') {
			throw new \InvalidArgumentException('A batch label is required.');
		}

		if ($leaving !== [] && $this->isDate(value: $endDate) === false) {
			throw new \InvalidArgumentException('An end date (YYYY-MM-DD) is required for leaving members.');
		}

		$endReason = trim($endReason);
		if ($endReason === '') {
			$endReason = 'end-of-term';
		}

		$fresh = $this->suggest(bodyId: $bodyId, rows: $rows);
		$result = ['onboardings' => [], 'offboardings' => [], 'rejected' => []];

		$joiningNow = [];
		foreach ($fresh['joining'] as $suggestion) {
			$joiningNow[$suggestion['key']] = $suggestion;
		}

		foreach (array_values(array_unique($joining)) as $key) {
			if (isset($joiningNow[$key]) === false) {
				$result['rejected'][] = [
					'suggestion' => $key,
					'reason'     => 'no longer a joining member (a membership or an open onboarding exists now)',
				];
				continue;
			}

			$result['onboardings'][] = $this->createOnboarding(bodyId: $bodyId, suggestion: $joiningNow[$key], batch: $batch);
		}

		$leavingNow = [];
		foreach ($fresh['leaving'] as $suggestion) {
			$leavingNow[$suggestion['membershipId']] = $suggestion;
		}

		foreach (array_values(array_unique($leaving)) as $membershipId) {
			if (isset($leavingNow[$membershipId]) === false) {
				$result['rejected'][] = [
					'suggestion' => $membershipId,
					'reason'     => 'no longer a leaving member (the membership ended, or an open offboarding exists now)',
				];
				continue;
			}

			$result['offboardings'][] = $this->createOffboarding(
				bodyId: $bodyId,
				suggestion: $leavingNow[$membershipId],
				batch: $batch,
				endDate: $endDate,
				endReason: $endReason
			);
		}

		return $result;
	}//end confirm()

	/**
	 * The roster rows as people keyed by account or email; rows without either are ignored.
	 *
	 * @param array<int, mixed> $rows The incoming roster.
	 *
	 * @return array{people: array<string, array<string, mixed>>, ignored: list<array<string,mixed>>}
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function roster(array $rows): array {
		$people  = [];
		$ignored = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$uid   = trim((string) ($row['nextcloudUserId'] ?? ''));
			$email = strtolower(trim((string) ($row['email'] ?? '')));
			$keys  = $this->keys(uid: $uid, email: $email);
			if ($keys === []) {
				$ignored[] = ['name' => trim((string) ($row['name'] ?? '')), 'reason' => 'no account or email to match on'];
				continue;
			}

			$role = trim((string) ($row['role'] ?? ''));
			if ($role === '') {
				$role = 'member';
			}

			$people[$keys[0]] = [
				'name'            => trim((string) ($row['name'] ?? '')),
				'email'           => $email,
				'nextcloudUserId' => $uid,
				'role'            => $role,
				'keys'            => $keys,
			];
		}

		return ['people' => $people, 'ignored' => $ignored];
	}//end roster()

	/**
	 * The body's active memberships with their person's match keys.
	 *
	 * @param string $bodyId The governance body uuid.
	 *
	 * @return list<array{membershipId: string, personId: string, name: string, role: string, keys: list<string>}>
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function activeMembers(string $bodyId): array {
		$today       = $this->time->getDateTime()->format('Y-m-d');
		$memberships = [];
		foreach ($this->rows(schema: 'membership', filters: ['governanceBody' => $bodyId]) as $row) {
			if (PathwaySteps::relationId(value: ($row['governanceBody'] ?? null)) !== $bodyId) {
				continue;
			}

			$end = substr(trim((string) ($row['endDate'] ?? '')), 0, 10);
			if ($end !== '' && $end <= $today) {
				continue;
			}

			$memberships[] = $row;
		}

		$personIds = [];
		foreach ($memberships as $row) {
			$personIds[] = PathwaySteps::relationId(value: ($row['person'] ?? null));
		}

		$persons = $this->byId(rows: $this->rows(schema: 'person', filters: [], ids: array_values(array_filter(array_unique($personIds)))));

		$members = [];
		foreach ($memberships as $row) {
			$personId = PathwaySteps::relationId(value: ($row['person'] ?? null));
			$person   = ($persons[$personId] ?? []);
			$members[] = [
				'membershipId' => (string) ($row['id'] ?? ''),
				'personId'     => $personId,
				'name'         => (string) ($person['name'] ?? ''),
				'role'         => (string) ($row['role'] ?? ''),
				'keys'         => $this->keys(
					uid: trim((string) ($person['nextcloudUserId'] ?? '')),
					email: strtolower(trim((string) ($person['email'] ?? '')))
				),
			];
		}

		return $members;
	}//end activeMembers()

	/**
	 * The people with an unfinished onboarding (by person) or offboarding (by membership) of the body.
	 *
	 * @param string $bodyId The governance body uuid.
	 *
	 * @return array{onboarding: array<string, true>, offboarding: array<string, true>}
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function openPathways(string $bodyId): array {
		$open = ['onboarding' => [], 'offboarding' => []];
		foreach ($this->rows(schema: OnboardingProvisioningService::ONBOARDING, filters: ['targetBody' => $bodyId]) as $row) {
			if ($this->isOpen(row: $row) === true && PathwaySteps::relationId(value: ($row['targetBody'] ?? null)) === $bodyId) {
				$open['onboarding'][PathwaySteps::relationId(value: ($row['person'] ?? null))] = true;
			}
		}

		foreach ($this->rows(schema: OnboardingProvisioningService::OFFBOARDING, filters: ['body' => $bodyId]) as $row) {
			if ($this->isOpen(row: $row) === true && PathwaySteps::relationId(value: ($row['body'] ?? null)) === $bodyId) {
				$open['offboarding'][PathwaySteps::relationId(value: ($row['membership'] ?? null))] = true;
			}
		}

		return $open;
	}//end openPathways()

	/**
	 * The existing Person records the roster people match, keyed by match key.
	 *
	 * @param array<string, array{keys: list<string>, email: string, nextcloudUserId: string}> $roster The roster people.
	 *
	 * @return array<string, string> Match key => person uuid.
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function personsFor(array $roster): array {
		$emails = [];
		$uids   = [];
		foreach ($roster as $person) {
			if ($person['email'] !== '') {
				$emails[] = $person['email'];
			}

			if ($person['nextcloudUserId'] !== '') {
				$uids[] = $person['nextcloudUserId'];
			}
		}

		$found = [];
		if ($uids !== []) {
			$found = array_merge($found, $this->rows(schema: 'person', filters: ['nextcloudUserId' => $uids]));
		}

		if ($emails !== []) {
			$found = array_merge($found, $this->rows(schema: 'person', filters: ['email' => $emails]));
		}

		$byKey = [];
		foreach ($found as $row) {
			$id = (string) ($row['id'] ?? '');
			$keys = $this->keys(
				uid: trim((string) ($row['nextcloudUserId'] ?? '')),
				email: strtolower(trim((string) ($row['email'] ?? '')))
			);
			foreach ($keys as $key) {
				if ($id !== '' && isset($byKey[$key]) === false) {
					$byKey[$key] = $id;
				}
			}
		}

		return $byKey;
	}//end personsFor()

	/**
	 * Create one onboarding, and the Person when there is none yet.
	 *
	 * @param string              $bodyId     The governance body uuid.
	 * @param array<string,mixed> $suggestion The joining suggestion.
	 * @param string              $batch      The batch label.
	 *
	 * @return string The new onboarding uuid.
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function createOnboarding(string $bodyId, array $suggestion, string $batch): string {
		$personId = (string) $suggestion['personId'];
		if ($personId === '') {
			$person = array_filter(
				[
					'name'            => $suggestion['name'],
					'email'           => $suggestion['email'],
					'nextcloudUserId' => $suggestion['nextcloudUserId'],
				],
				static fn (string $value): bool => $value !== ''
			);
			$personId = $this->idOf(saved: $this->objectService->saveObject(object: $person, register: self::REGISTER, schema: 'person'));
		}

		$record = [
			'person'     => $personId,
			'targetBody' => $bodyId,
			'targetRole' => $suggestion['role'],
			'trigger'    => self::TRIGGER,
			'batch'      => $batch,
			'lifecycle'  => 'started',
			'steps'      => $this->openSteps(steps: self::ONBOARDING_STEPS),
		];
		if ($suggestion['nextcloudUserId'] !== '') {
			$record['ncAccount'] = $suggestion['nextcloudUserId'];
		}

		return $this->idOf(
			saved: $this->objectService->saveObject(object: $record, register: self::REGISTER, schema: OnboardingProvisioningService::ONBOARDING)
		);
	}//end createOnboarding()

	/**
	 * Create one offboarding. The membership itself is left as it is.
	 *
	 * @param string              $bodyId     The governance body uuid.
	 * @param array<string,mixed> $suggestion The leaving suggestion.
	 * @param string              $batch      The batch label.
	 * @param string              $endDate    The end date.
	 * @param string              $endReason  The end reason.
	 *
	 * @return string The new offboarding uuid.
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function createOffboarding(string $bodyId, array $suggestion, string $batch, string $endDate, string $endReason): string {
		$record = [
			'person'     => $suggestion['personId'],
			'body'       => $bodyId,
			'membership' => $suggestion['membershipId'],
			'trigger'    => self::TRIGGER,
			'batch'      => $batch,
			'endDate'    => $endDate,
			'endReason'  => $endReason,
			'lifecycle'  => 'started',
			'steps'      => $this->openSteps(steps: self::OFFBOARDING_STEPS),
		];

		return $this->idOf(
			saved: $this->objectService->saveObject(object: $record, register: self::REGISTER, schema: OnboardingProvisioningService::OFFBOARDING)
		);
	}//end createOffboarding()

	/**
	 * The default steps, all open.
	 *
	 * @param list<array{stepType: string, title: string, mandatory: bool}> $steps The step templates.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-004-structured-checklist-steps-with-per-step-status
	 */
	private function openSteps(array $steps): array {
		return array_map(static fn (array $step): array => $step + ['status' => 'open'], $steps);
	}//end openSteps()

	/**
	 * One bulk read, as plain arrays.
	 *
	 * @param string            $schema  The schema slug.
	 * @param array<string,mixed> $filters The filters.
	 * @param list<string>|null $ids     Narrow to these uuids; an empty list reads nothing.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function rows(string $schema, array $filters, ?array $ids=null): array {
		if ($ids === []) {
			return [];
		}

		$config = ['filters' => (['register' => self::REGISTER, 'schema' => $schema, '_limit' => self::LIMIT] + $filters)];
		if ($ids !== null) {
			$config['ids'] = $ids;
		}

		$rows = [];
		foreach ($this->objectService->findAll($config) as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rows()

	/**
	 * Rows keyed by id.
	 *
	 * @param list<array<string,mixed>> $rows The rows.
	 *
	 * @return array<string, array<string,mixed>>
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function byId(array $rows): array {
		$byId = [];
		foreach ($rows as $row) {
			$byId[(string) ($row['id'] ?? '')] = $row;
		}

		return $byId;
	}//end byId()

	/**
	 * The match keys of a person: account first, then email.
	 *
	 * @param string $uid   The Nextcloud uid.
	 * @param string $email The lower-cased email.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function keys(string $uid, string $email): array {
		$keys = [];
		if ($uid !== '') {
			$keys[] = 'uid:' . $uid;
		}

		if ($email !== '') {
			$keys[] = 'email:' . $email;
		}

		return $keys;
	}//end keys()

	/**
	 * Whether any of the keys is in the set.
	 *
	 * @param list<string>        $keys The keys.
	 * @param array<string, true> $set  The set.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function anyKey(array $keys, array $set): bool {
		foreach ($keys as $key) {
			if (isset($set[$key]) === true) {
				return true;
			}
		}

		return false;
	}//end anyKey()

	/**
	 * The person uuid matching one of the keys, or empty.
	 *
	 * @param list<string>          $keys    The keys.
	 * @param array<string, string> $persons Match key => person uuid.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function personIdFor(array $keys, array $persons): string {
		foreach ($keys as $key) {
			if (isset($persons[$key]) === true) {
				return $persons[$key];
			}
		}

		return '';
	}//end personIdFor()

	/**
	 * Whether an onboarding or offboarding is still running.
	 *
	 * @param array<string,mixed> $row The record.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function isOpen(array $row): bool {
		return in_array(($row['lifecycle'] ?? 'started'), ['completed', 'lapsed'], true) === false;
	}//end isOpen()

	/**
	 * Whether a value is a calendar date (Y-m-d).
	 *
	 * @param string $value The value.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function isDate(string $value): bool {
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		return ($date !== false && $date->format('Y-m-d') === $value);
	}//end isDate()

	/**
	 * The uuid of a saved object.
	 *
	 * @param mixed $saved What saveObject returned.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function idOf(mixed $saved): string {
		if ($saved instanceof ObjectEntityInterface === true && (string) $saved->getUuid() !== '') {
			return (string) $saved->getUuid();
		}

		if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
			$saved = $saved->jsonSerialize();
		}

		if (is_array($saved) === true) {
			return (string) ($saved['id'] ?? ($saved['uuid'] ?? ''));
		}

		return '';
	}//end idOf()

}//end class
