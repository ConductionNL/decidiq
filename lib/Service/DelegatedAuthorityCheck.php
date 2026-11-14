<?php

/**
 * Decidiq delegated authority check.
 *
 * Answers one question for any app that gates an act on authority: may this
 * account perform this act, for this amount, on this date, under an authority
 * the register records? The register is the delegatie- en mandaatregister
 * (`bevoegdheidstoedeling`). An app that used to keep its own mandate matrix
 * asks here instead, so the matrix lives in one place.
 *
 * 🔑 DECIDIQ ANSWERS, THE ASKING APP ENFORCES. REQ-DMR-006 keeps the register
 * from gating decidiq's own Decision lifecycle, and this class does not either:
 * it returns an answer and refuses nothing itself.
 *
 * 🔴 EVERY UNCERTAIN ANSWER IS "NO". An empty `acts` list covers no act, a row
 * with an unreadable date covers nothing, an ondermandaat whose parent is not
 * in force covers nothing, and a register that cannot be read answers
 * `register-unreadable`, never "authorised".
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/delegated-authority-check/specs/delegatie-mandaatregister/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use OCP\IGroupManager;
use Throwable;

/**
 * Judges whether an account holds an authority for an act.
 *
 * @spec openspec/changes/delegated-authority-check/specs/delegatie-mandaatregister/spec.md
 */
class DelegatedAuthorityCheck {

	public const REASON_AUTHORISED = 'authorised';

	public const REASON_NO_ACTOR = 'no-actor';

	public const REASON_NO_ACT = 'no-act';

	public const REASON_NO_ALLOCATION = 'no-allocation';

	public const REASON_OVER_CEILING = 'over-ceiling';

	public const REASON_UNREADABLE = 'register-unreadable';

	/**
	 * Schema slug of the register this check reads.
	 */
	private const SCHEMA = 'bevoegdheidstoedeling';

	/**
	 * The one status under which a toedeling grants anything.
	 */
	private const STATUS_EFFECTIVE = 'effective';

	/**
	 * How far up an ondermandaat chain the check walks before it gives up.
	 */
	private const MAX_CHAIN_DEPTH = 10;

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store  Reads the toedeling rows.
	 * @param IGroupManager       $groups Answers group membership for delegateGroup.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
		private readonly IGroupManager $groups,
	) {
	}//end __construct()

	/**
	 * Whether the account may perform the act.
	 *
	 * @param string                 $actor  The Nextcloud account that wants to act.
	 * @param string                 $act    The act key, as the asking app names it.
	 * @param float|null             $amount The amount in euro the act concerns, or null when it has none.
	 * @param DateTimeImmutable|null $moment     The moment of the act; now when null.
	 *
	 * @return array{authorised: bool, reason: string, allocation: string|null}
	 *
	 * @spec openspec/changes/delegated-authority-check/specs/delegatie-mandaatregister/spec.md
	 */
	public function check(string $actor, string $act, ?float $amount=null, ?DateTimeImmutable $moment=null): array {
		return $this->answerRequest(
			request: new AuthorityRequest(actor: trim($actor), act: trim($act), amount: $amount, moment: ($moment ?? new DateTimeImmutable()))
		);
	}//end check()

	/**
	 * Answer one authority question against the in-force toedelingen.
	 *
	 * @param AuthorityRequest $request The question.
	 *
	 * @return array{authorised: bool, reason: string, allocation: string|null}
	 *
	 * @spec openspec/changes/delegated-authority-check/specs/delegatie-mandaatregister/spec.md
	 */
	private function answerRequest(AuthorityRequest $request): array {
		$refusal = $this->incompleteRequestReason(request: $request);
		if ($refusal !== null) {
			return $this->answer(reason: $refusal);
		}

		try {
			$rows = $this->store->findAll(schema: self::SCHEMA, filters: ['status' => self::STATUS_EFFECTIVE]);
		} catch (Throwable) {
			return $this->answer(reason: self::REASON_UNREADABLE);
		}

		return $this->answerFromRows(rows: $rows, request: $request);
	}//end answerRequest()

	/**
	 * The refusal reason for a question without an actor or an act.
	 *
	 * @param AuthorityRequest $request The question.
	 *
	 * @return string|null The reason, or null when both are present.
	 */
	private function incompleteRequestReason(AuthorityRequest $request): ?string {
		if ($request->actor === '') {
			return self::REASON_NO_ACTOR;
		}

		if ($request->act === '') {
			return self::REASON_NO_ACT;
		}

		return null;
	}//end incompleteRequestReason()

	/**
	 * The answer of the first row that authorises; else over-ceiling when one was exceeded.
	 *
	 * @param array<int, array<string, mixed>> $rows    The in-force toedelingen.
	 * @param AuthorityRequest                 $request The question.
	 *
	 * @return array{authorised: bool, reason: string, allocation: string|null}
	 */
	private function answerFromRows(array $rows, AuthorityRequest $request): array {
		$refusal = self::REASON_NO_ALLOCATION;
		foreach ($rows as $row) {
			$verdict = $this->verdictFor(row: $row, request: $request);
			if ($verdict === self::REASON_AUTHORISED) {
				return $this->grant(row: $row);
			}

			if ($verdict === self::REASON_OVER_CEILING) {
				$refusal = self::REASON_OVER_CEILING;
			}
		}

		return $this->answer(reason: $refusal);
	}//end answerFromRows()

	/**
	 * What one row says: authorised, over its ceiling, or nothing (it does not cover).
	 *
	 * @param array<string, mixed> $row     The toedeling.
	 * @param AuthorityRequest     $request The question.
	 *
	 * @return string|null REASON_AUTHORISED, REASON_OVER_CEILING, or null.
	 */
	private function verdictFor(array $row, AuthorityRequest $request): ?string {
		if ($this->covers(row: $row, request: $request) === false) {
			return null;
		}

		if ($this->withinCeiling(row: $row, amount: $request->amount) === false) {
			return self::REASON_OVER_CEILING;
		}

		return self::REASON_AUTHORISED;
	}//end verdictFor()

	/**
	 * The authorising answer for a row.
	 *
	 * @param array<string, mixed> $row The toedeling.
	 *
	 * @return array{authorised: bool, reason: string, allocation: string|null}
	 */
	private function grant(array $row): array {
		return [
			'authorised' => true,
			'reason'     => self::REASON_AUTHORISED,
			'allocation' => (string)($row['id'] ?? $row['uuid'] ?? ''),
		];
	}//end grant()

	/**
	 * Whether one row grants this account this act at this moment.
	 *
	 * @param array<string, mixed> $row     The toedeling.
	 * @param AuthorityRequest     $request The question.
	 *
	 * @return bool
	 */
	private function covers(array $row, AuthorityRequest $request): bool {
		$acts = $row['acts'] ?? [];
		if (is_array($acts) === false || in_array($request->act, $acts, true) === false) {
			return false;
		}

		if ($this->namesActor(row: $row, actor: $request->actor) === false) {
			return false;
		}

		return $this->inForce(row: $row, moment: $request->moment);
	}//end covers()

	/**
	 * Whether the row names the account, directly or through a group.
	 *
	 * @param array<string, mixed> $row   The toedeling.
	 * @param string               $actor The account.
	 *
	 * @return bool
	 */
	private function namesActor(array $row, string $actor): bool {
		$user = trim((string)($row['delegateUser'] ?? ''));
		if ($user !== '' && $user === $actor) {
			return true;
		}

		$group = trim((string)($row['delegateGroup'] ?? ''));
		if ($group === '') {
			return false;
		}

		return $this->groups->isInGroup($actor, $group);
	}//end namesActor()

	/**
	 * Whether the row, and every parent it is an ondermandaat of, is in force.
	 *
	 * Walks at most MAX_CHAIN_DEPTH parents up; a longer chain (or a cycle)
	 * covers nothing.
	 *
	 * @param array<string, mixed> $row    The toedeling.
	 * @param DateTimeImmutable    $moment The moment.
	 *
	 * @return bool
	 */
	private function inForce(array $row, DateTimeImmutable $moment): bool {
		$current = $row;
		for ($depth = 0; $depth <= self::MAX_CHAIN_DEPTH; $depth++) {
			if ($this->rowInForce(row: $current, moment: $moment) === false) {
				return false;
			}

			$parentId = trim((string)($current['parentAllocation'] ?? ''));
			if ($parentId === '') {
				return true;
			}

			$current = $this->permittingParent(parentId: $parentId);
		}

		return false;
	}//end inForce()

	/**
	 * Whether the row itself is effective and inside its validity window.
	 *
	 * @param array<string, mixed> $row    The toedeling.
	 * @param DateTimeImmutable    $moment The moment.
	 *
	 * @return bool
	 */
	private function rowInForce(array $row, DateTimeImmutable $moment): bool {
		return (string)($row['status'] ?? '') === self::STATUS_EFFECTIVE
			&& $this->withinWindow(row: $row, moment: $moment) === true;
	}//end rowInForce()

	/**
	 * The parent toedeling when it can be read and permits an ondermandaat.
	 *
	 * Anything else yields an empty row, which carries no status and so is
	 * never in force.
	 *
	 * @param string $parentId The parent's uuid.
	 *
	 * @return array<string, mixed> The parent, or [] when it covers nothing.
	 */
	private function permittingParent(string $parentId): array {
		try {
			$parent = $this->store->find(schema: self::SCHEMA, uuid: $parentId);
		} catch (Throwable) {
			return [];
		}

		if ($parent === null || ($parent['subMandatePermitted'] ?? false) !== true) {
			return [];
		}

		return $parent;
	}//end permittingParent()

	/**
	 * Whether the moment lies inside the row's validity window.
	 *
	 * A date that cannot be read covers nothing.
	 *
	 * @param array<string, mixed> $row The toedeling.
	 * @param DateTimeImmutable    $moment  The moment.
	 *
	 * @return bool
	 */
	private function withinWindow(array $row, DateTimeImmutable $moment): bool {
		$day  = $moment->format('Y-m-d');
		$from = trim((string)($row['validFrom'] ?? ''));
		$to   = trim((string)($row['validTo'] ?? ''));

		try {
			return $this->startedBy(from: $from, day: $day) === true && $this->runsUntil(to: $to, day: $day) === true;
		} catch (Throwable) {
			return false;
		}
	}//end withinWindow()

	/**
	 * Whether an open or readable start date lies on or before the day.
	 *
	 * @param string $from The row's validFrom, trimmed.
	 * @param string $day  The day as Y-m-d.
	 *
	 * @return bool
	 *
	 * @throws \Exception When the date cannot be read.
	 */
	private function startedBy(string $from, string $day): bool {
		return $from === '' || (new DateTimeImmutable($from))->format('Y-m-d') <= $day;
	}//end startedBy()

	/**
	 * Whether an open or readable end date lies on or after the day.
	 *
	 * @param string $to  The row's validTo, trimmed.
	 * @param string $day The day as Y-m-d.
	 *
	 * @return bool
	 *
	 * @throws \Exception When the date cannot be read.
	 */
	private function runsUntil(string $to, string $day): bool {
		return $to === '' || (new DateTimeImmutable($to))->format('Y-m-d') >= $day;
	}//end runsUntil()

	/**
	 * Whether the amount stays within the row's financial ceiling.
	 *
	 * An act without an amount has nothing to exceed; a row without a ceiling
	 * has no limit.
	 *
	 * @param array<string, mixed> $row    The toedeling.
	 * @param float|null           $amount The amount in euro.
	 *
	 * @return bool
	 */
	private function withinCeiling(array $row, ?float $amount): bool {
		$ceiling = ($row['financialCeiling'] ?? null);
		if ($amount === null || is_numeric($ceiling) === false) {
			return true;
		}

		return $amount <= (float)$ceiling;
	}//end withinCeiling()

	/**
	 * A refusal in the answer shape.
	 *
	 * @param string $reason The reason key.
	 *
	 * @return array{authorised: bool, reason: string, allocation: string|null}
	 */
	private function answer(string $reason): array {
		return ['authorised' => false, 'reason' => $reason, 'allocation' => null];
	}//end answer()

}//end class
