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
	 * @param DateTimeImmutable|null $at     The moment of the act; now when null.
	 *
	 * @return array{authorised: bool, reason: string, allocation: string|null}
	 *
	 * @spec openspec/changes/delegated-authority-check/specs/delegatie-mandaatregister/spec.md
	 */
	public function check(string $actor, string $act, ?float $amount=null, ?DateTimeImmutable $at=null): array {
		$actor = trim($actor);
		$act   = trim($act);
		if ($actor === '') {
			return $this->answer(reason: self::REASON_NO_ACTOR);
		}

		if ($act === '') {
			return $this->answer(reason: self::REASON_NO_ACT);
		}

		$at = ($at ?? new DateTimeImmutable());

		try {
			$rows = $this->store->findAll(schema: self::SCHEMA, filters: ['status' => self::STATUS_EFFECTIVE]);
		} catch (Throwable) {
			return $this->answer(reason: self::REASON_UNREADABLE);
		}

		$overCeiling = false;
		foreach ($rows as $row) {
			if ($this->covers(row: $row, actor: $actor, act: $act, at: $at) === false) {
				continue;
			}

			if ($this->withinCeiling(row: $row, amount: $amount) === false) {
				$overCeiling = true;
				continue;
			}

			return [
				'authorised' => true,
				'reason'     => self::REASON_AUTHORISED,
				'allocation' => (string)($row['id'] ?? $row['uuid'] ?? ''),
			];
		}

		if ($overCeiling === true) {
			return $this->answer(reason: self::REASON_OVER_CEILING);
		}

		return $this->answer(reason: self::REASON_NO_ALLOCATION);
	}//end check()

	/**
	 * Whether one row grants this account this act at this moment.
	 *
	 * @param array<string, mixed> $row   The toedeling.
	 * @param string               $actor The account.
	 * @param string               $act   The act key.
	 * @param DateTimeImmutable    $at    The moment.
	 *
	 * @return bool
	 */
	private function covers(array $row, string $actor, string $act, DateTimeImmutable $at): bool {
		$acts = $row['acts'] ?? [];
		if (is_array($acts) === false || in_array($act, $acts, true) === false) {
			return false;
		}

		if ($this->namesActor(row: $row, actor: $actor) === false) {
			return false;
		}

		return $this->inForce(row: $row, at: $at, depth: 0);
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
	 * @param array<string, mixed> $row   The toedeling.
	 * @param DateTimeImmutable    $at    The moment.
	 * @param int                  $depth How many parents were walked already.
	 *
	 * @return bool
	 */
	private function inForce(array $row, DateTimeImmutable $at, int $depth): bool {
		if ($depth > self::MAX_CHAIN_DEPTH) {
			return false;
		}

		if ((string)($row['status'] ?? '') !== self::STATUS_EFFECTIVE) {
			return false;
		}

		if ($this->withinWindow(row: $row, at: $at) === false) {
			return false;
		}

		$parentId = trim((string)($row['parentAllocation'] ?? ''));
		if ($parentId === '') {
			return true;
		}

		try {
			$parent = $this->store->find(schema: self::SCHEMA, uuid: $parentId);
		} catch (Throwable) {
			return false;
		}

		if ($parent === null || ($parent['subMandatePermitted'] ?? false) !== true) {
			return false;
		}

		return $this->inForce(row: $parent, at: $at, depth: ($depth + 1));
	}//end inForce()

	/**
	 * Whether the moment lies inside the row's validity window.
	 *
	 * A date that cannot be read covers nothing.
	 *
	 * @param array<string, mixed> $row The toedeling.
	 * @param DateTimeImmutable    $at  The moment.
	 *
	 * @return bool
	 */
	private function withinWindow(array $row, DateTimeImmutable $at): bool {
		$day  = $at->format('Y-m-d');
		$from = trim((string)($row['validFrom'] ?? ''));
		$to   = trim((string)($row['validTo'] ?? ''));

		try {
			if ($from !== '' && (new DateTimeImmutable($from))->format('Y-m-d') > $day) {
				return false;
			}

			if ($to !== '' && (new DateTimeImmutable($to))->format('Y-m-d') < $day) {
				return false;
			}
		} catch (Throwable) {
			return false;
		}

		return true;
	}//end withinWindow()

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
