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
 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use InvalidArgumentException;
use OCA\Decidiq\Service\Pathway\PathwaySteps;
use OCA\Decidiq\Service\Pathway\RosterMatcher;
use OCA\Decidiq\Service\Pathway\TurnoverPathwayWriter;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
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
 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
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
	 * Why a confirmed joining suggestion was not created.
	 */
	private const NO_LONGER_JOINING = 'no longer a joining member (a membership or an open onboarding exists now)';

	/**
	 * Why a confirmed leaving suggestion was not created.
	 */
	private const NO_LONGER_LEAVING = 'no longer a leaving member (the membership ended, or an open offboarding exists now)';

	/**
	 * Reads and completes the checklist steps on a pathway record.
	 *
	 * @var PathwaySteps
	 */
	private readonly PathwaySteps $pathwaySteps;

	/**
	 * Matches the roster against the current members.
	 *
	 * @var RosterMatcher
	 */
	private readonly RosterMatcher $matcher;

	/**
	 * Creates the confirmed onboardings and offboardings.
	 *
	 * @var TurnoverPathwayWriter
	 */
	private readonly TurnoverPathwayWriter $writer;

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
		$this->pathwaySteps = new PathwaySteps();
		$this->matcher      = new RosterMatcher();
		$this->writer       = new TurnoverPathwayWriter(objectService: $objectService);
	}//end __construct()

	/**
	 * Whether the governance body exists (and the caller may read it).
	 *
	 * @param string $bodyId The governance body uuid.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	public function bodyExists(string $bodyId): bool {
		if (trim($bodyId) === '') {
			return false;
		}

		try {
			return $this->objectService->find(id: $bodyId, options: new ObjectFindOptions(register: self::REGISTER, schema: 'governance-body')) !== null;
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
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	public function suggest(string $bodyId, array $rows): array {
		$roster  = $this->matcher->roster(rows: $rows);
		$members = $this->activeMembers(bodyId: $bodyId);
		$open    = $this->openPathways(bodyId: $bodyId);

		$joining = $this->matcher->joiningOf(
			people: $roster['people'],
			context: [
				'memberKeys' => $this->matcher->keySet(items: $members),
				'open'       => $open['onboarding'],
				'persons'    => $this->personsFor(roster: $roster['people']),
			]
		);
		$leaving = $this->matcher->leavingOf(
			members: $members,
			context: ['rosterKeys' => $this->matcher->keySet(items: $roster['people']), 'open' => $open['offboarding']]
		);

		return ['joining' => $joining, 'leaving' => $leaving, 'ignored' => $roster['ignored']];
	}//end suggest()

	/**
	 * Create the confirmed suggestions that still hold, under one batch label.
	 *
	 * The request holds `rows` (the same incoming roster), `joining` (the
	 * confirmed joining keys), `leaving` (the confirmed leaving membership
	 * uuids), `batch` (the batch label), `endDate` (Y-m-d, for the leaving
	 * members) and `endReason` (why their membership ends).
	 *
	 * @param string              $bodyId  The governance body uuid.
	 * @param array<string,mixed> $request The confirmation.
	 *
	 * @return array{onboardings: list<string>, offboardings: list<string>, rejected: list<array{suggestion: string, reason: string}>}
	 *
	 * @throws InvalidArgumentException When the batch label is empty, or leaving members are confirmed without a valid end date.
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	public function confirm(string $bodyId, array $request): array {
		$batch = $this->batchOf(request: $request);
		$fresh = $this->suggest(bodyId: $bodyId, rows: (array) ($request['rows'] ?? []));
		$result = ['onboardings' => [], 'offboardings' => [], 'rejected' => []];

		$joiningNow = array_column($fresh['joining'], null, 'key');
		foreach (array_values(array_unique((array) ($request['joining'] ?? []))) as $key) {
			if (isset($joiningNow[$key]) === false) {
				$result['rejected'][] = ['suggestion' => $key, 'reason' => self::NO_LONGER_JOINING];
				continue;
			}

			$result['onboardings'][] = $this->writer->createOnboarding(bodyId: $bodyId, suggestion: $joiningNow[$key] + $batch);
		}

		$leavingNow = array_column($fresh['leaving'], null, 'membershipId');
		foreach (array_values(array_unique((array) ($request['leaving'] ?? []))) as $membershipId) {
			if (isset($leavingNow[$membershipId]) === false) {
				$result['rejected'][] = ['suggestion' => $membershipId, 'reason' => self::NO_LONGER_LEAVING];
				continue;
			}

			$result['offboardings'][] = $this->writer->createOffboarding(
				bodyId: $bodyId,
				suggestion: $leavingNow[$membershipId] + $batch
			);
		}

		return $result;
	}//end confirm()

	/**
	 * The checked batch label, end date and end reason of a confirmation.
	 *
	 * @param array<string,mixed> $request The confirmation.
	 *
	 * @return array{batch: string, endDate: string, endReason: string}
	 *
	 * @throws InvalidArgumentException When the batch label is empty, or leaving members are confirmed without a valid end date.
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function batchOf(array $request): array {
		$batch = trim((string) ($request['batch'] ?? ''));
		if ($batch === '') {
			throw new InvalidArgumentException('A batch label is required.');
		}

		$endDate = (string) ($request['endDate'] ?? '');
		if (($request['leaving'] ?? []) !== [] && $this->isDate(value: $endDate) === false) {
			throw new InvalidArgumentException('An end date (YYYY-MM-DD) is required for leaving members.');
		}

		$endReason = trim((string) ($request['endReason'] ?? ''));
		if ($endReason === '') {
			$endReason = 'end-of-term';
		}

		return ['batch' => $batch, 'endDate' => $endDate, 'endReason' => $endReason];
	}//end batchOf()

	/**
	 * The body's active memberships with their person's match keys.
	 *
	 * @param string $bodyId The governance body uuid.
	 *
	 * @return list<array{membershipId: string, personId: string, name: string, role: string, keys: list<string>}>
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function activeMembers(string $bodyId): array {
		$memberships = $this->currentMemberships(bodyId: $bodyId);
		$personIds   = array_map(fn (array $row): string => $this->pathwaySteps->relationId(value: ($row['person'] ?? null)), $memberships);
		$persons     = $this->byId(rows: $this->rows(schema: 'person', filters: [], ids: array_values(array_filter(array_unique($personIds)))));

		$members = [];
		foreach ($memberships as $row) {
			$personId  = $this->pathwaySteps->relationId(value: ($row['person'] ?? null));
			$person    = ($persons[$personId] ?? []);
			$members[] = [
				'membershipId' => (string) ($row['id'] ?? ''),
				'personId'     => $personId,
				'name'         => (string) ($person['name'] ?? ''),
				'role'         => (string) ($row['role'] ?? ''),
				'keys'         => $this->matcher->personKeys(person: $person),
			];
		}

		return $members;
	}//end activeMembers()

	/**
	 * The body's memberships that have not ended by today.
	 *
	 * @param string $bodyId The governance body uuid.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function currentMemberships(string $bodyId): array {
		$today       = $this->time->getDateTime()->format('Y-m-d');
		$memberships = [];
		foreach ($this->rows(schema: 'membership', filters: ['governanceBody' => $bodyId]) as $row) {
			if ($this->pathwaySteps->relationId(value: ($row['governanceBody'] ?? null)) !== $bodyId) {
				continue;
			}

			$end = substr(trim((string) ($row['endDate'] ?? '')), 0, 10);
			if ($end === '' || $end > $today) {
				$memberships[] = $row;
			}
		}

		return $memberships;
	}//end currentMemberships()

	/**
	 * The people with an unfinished onboarding (by person) or offboarding (by membership) of the body.
	 *
	 * @param string $bodyId The governance body uuid.
	 *
	 * @return array{onboarding: array<string, true>, offboarding: array<string, true>}
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function openPathways(string $bodyId): array {
		$open = ['onboarding' => [], 'offboarding' => []];
		foreach ($this->rows(schema: OnboardingProvisioningService::ONBOARDING, filters: ['targetBody' => $bodyId]) as $row) {
			if ($this->isOpen(row: $row) === true && $this->pathwaySteps->relationId(value: ($row['targetBody'] ?? null)) === $bodyId) {
				$open['onboarding'][$this->pathwaySteps->relationId(value: ($row['person'] ?? null))] = true;
			}
		}

		foreach ($this->rows(schema: OnboardingProvisioningService::OFFBOARDING, filters: ['body' => $bodyId]) as $row) {
			if ($this->isOpen(row: $row) === true && $this->pathwaySteps->relationId(value: ($row['body'] ?? null)) === $bodyId) {
				$open['offboarding'][$this->pathwaySteps->relationId(value: ($row['membership'] ?? null))] = true;
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
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function personsFor(array $roster): array {
		$found = [];
		foreach (['nextcloudUserId', 'email'] as $field) {
			$values = array_values(array_filter(array_column($roster, $field), static fn (string $value): bool => $value !== ''));
			if ($values !== []) {
				$found = array_merge($found, $this->rows(schema: 'person', filters: [$field => $values]));
			}
		}

		$byKey = [];
		foreach ($found as $row) {
			$id = (string) ($row['id'] ?? '');
			if ($id === '') {
				continue;
			}

			foreach ($this->matcher->personKeys(person: $row) as $key) {
				$byKey[$key] = ($byKey[$key] ?? $id);
			}
		}

		return $byKey;
	}//end personsFor()

	/**
	 * One bulk read, as plain arrays.
	 *
	 * @param string            $schema  The schema slug.
	 * @param array<string,mixed> $filters The filters.
	 * @param list<string>|null $ids     Narrow to these uuids; an empty list reads nothing.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
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
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function byId(array $rows): array {
		$byId = [];
		foreach ($rows as $row) {
			$byId[(string) ($row['id'] ?? '')] = $row;
		}

		return $byId;
	}//end byId()

	/**
	 * Whether an onboarding or offboarding is still running.
	 *
	 * @param array<string,mixed> $row The record.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
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
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function isDate(string $value): bool {
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
			return false;
		}

		return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
	}//end isDate()

}//end class
