<?php

/**
 * Decision Withdrawal Service
 *
 * A decision that has been taken can be withdrawn, and a withdrawal is an
 * APPEND. The original outcome is never cleared.
 *
 * WHY IT IS NOT AN EDIT OF THE OUTCOME
 * -------------------------------------
 * A decision that was granted and then withdrawn is a different fact from a
 * decision that was refused. Somebody acted on the grant in between. Setting
 * the outcome to something else loses that, and the record then cannot answer
 * the only question anybody asks afterwards: what were people entitled to rely
 * on, and until when.
 *
 * WHY THE ACTOR KIND IS REQUIRED
 * -------------------------------
 * A withdrawal by the deciding body and a withdrawal by the party who asked are
 * different acts with different consequences: one is the government changing
 * its mind, the other is somebody dropping their own request. A record that
 * does not say which is not a record.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Withdraws a decision without losing what it decided.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-005)
 */
final class DecisionWithdrawalService {
	/**
	 * The deciding body withdrew its own decision.
	 *
	 * @var string
	 */
	public const BY_BESTUURSORGAAN = 'bestuursorgaan';

	/**
	 * The party who asked withdrew their request.
	 *
	 * @var string
	 */
	public const BY_BELANGHEBBENDE = 'belanghebbende';

	/**
	 * Who a withdrawal may come from.
	 *
	 * @var array<int, string>
	 */
	public const ACTOR_KINDS = [self::BY_BESTUURSORGAAN, self::BY_BELANGHEBBENDE];

	/**
	 * Withdraw a decision.
	 *
	 * @param array<string, mixed> $decision The decision as stored.
	 * @param string $withdrawnBy Which kind of actor withdrew it.
	 * @param string $reason Why, written for the person who receives it.
	 * @param string $withdrawnAt When, as an ISO-8601 instant; now when empty.
	 *
	 * @return array<string, mixed> The decision, withdrawn, with its outcome intact.
	 *
	 * @throws InvalidArgumentException When the actor kind is missing or unknown, or the decision was never taken.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-005)
	 */
	public function withdraw(
		array $decision,
		string $withdrawnBy,
		string $reason = '',
		string $withdrawnAt = '',
	): array {
		if (in_array($withdrawnBy, self::ACTOR_KINDS, true) === false) {
			throw new InvalidArgumentException(
				sprintf(
					'A withdrawal has to say who withdrew it: %s. The two are different acts with different consequences.',
					implode(' or ', self::ACTOR_KINDS)
				)
			);
		}

		if (($decision['withdrawn'] ?? false) === true) {
			throw new InvalidArgumentException('This decision has already been withdrawn.');
		}

		$outcome = (string)($decision['outcome'] ?? '');
		if ($outcome === '') {
			throw new InvalidArgumentException('A decision that was never taken cannot be withdrawn.');
		}

		$when = ($withdrawnAt === '' ? (new DateTimeImmutable())->format(DateTimeImmutable::ATOM) : $withdrawnAt);

		// Deliberately NOT touching `outcome`. What was decided stays readable
		// beside the withdrawal, because somebody relied on it in between.
		$decision['withdrawn'] = true;
		$decision['withdrawnAt'] = $when;
		$decision['withdrawnBy'] = $withdrawnBy;
		$decision['withdrawnReason'] = trim($reason);

		$history = ($decision['history'] ?? []);
		if (is_array($history) === false) {
			$history = [];
		}

		$history[] = [
			'event' => 'withdrawn',
			'at' => $when,
			'by' => $withdrawnBy,
			'reason' => trim($reason),
			'outcomeAtWithdrawal' => $outcome,
		];
		$decision['history'] = $history;

		return $decision;
	}//end withdraw()

	/**
	 * How a withdrawn decision reads: withdrawn, by whom, and still showing what
	 * it originally decided.
	 *
	 * @param array<string, mixed> $decision The decision.
	 *
	 * @return array<string, mixed> The projection.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-005)
	 */
	public function readFrom(array $decision): array {
		return [
			'outcome' => (string)($decision['outcome'] ?? ''),
			'withdrawn' => (($decision['withdrawn'] ?? false) === true),
			'withdrawnBy' => (string)($decision['withdrawnBy'] ?? ''),
			'withdrawnAt' => (string)($decision['withdrawnAt'] ?? ''),
			'withdrawnReason' => (string)($decision['withdrawnReason'] ?? ''),
		];
	}//end readFrom()
}//end class
