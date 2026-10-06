<?php

/**
 * Decidiq Decision Withdraw Flow
 *
 * Orchestrates withdrawing a decision (REQ-DWP-005, #1380): validates the
 * actor kind, checks the `lifecycle → withdrawn` edge, enforces the chair-only
 * gate (fail closed) through DecisionLifecycleService, lets
 * DecisionWithdrawalService stamp who withdrew it, why and when, persists the
 * decision via OpenRegister and hands the shared post-transition effects
 * (hash-chained audit append, DecisionConcludedEvent) back to
 * DecisionLifecycleService.
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
 * @spec openspec/specs/decision-management/spec.md
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use InvalidArgumentException;
use OCA\Decidiq\Lifecycle\DecisionTransitionGuard;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Withdraws a decision through the guarded lifecycle.
 *
 * Withdrawal is not an action in the transition map because it carries a
 * payload the map cannot express: a withdrawal that does not say who withdrew
 * it is refused. Access control is the same as a lifecycle transition:
 * OpenRegister ObjectService RBAC (find/saveObject) plus the chair-only gate
 * when the body's policy restricts the edge, which FAILS CLOSED when no chair
 * can be resolved.
 *
 * @spec openspec/specs/decision-management/spec.md
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-005)
 */
class DecisionWithdrawFlow {
	/**
	 * Loads the decision under OpenRegister's per-object read ACL.
	 *
	 * @var DecisionContextResolver
	 */
	private readonly DecisionContextResolver $contextResolver;

	/**
	 * Builds the withdrawal (who, why, when; outcome kept) — REQ-DWP-005.
	 *
	 * @var DecisionWithdrawalService
	 */
	private readonly DecisionWithdrawalService $withdrawalService;

	/**
	 * Constructor for DecisionWithdrawFlow.
	 *
	 * @param LoggerInterface $logger The logger
	 * @param DecisionTransitionGuard $transitionGuard Declares which lifecycle states may be withdrawn
	 * @param DecisionLifecycleService $lifecycleService Shared chair gate and post-transition effects (audit, event)
	 * @param ObjectServiceInterface $objectService OpenRegister ObjectService contract (ADR-022/ADR-083)
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly DecisionTransitionGuard $transitionGuard,
		private readonly DecisionLifecycleService $lifecycleService,
		private readonly ObjectServiceInterface $objectService,
	) {
		$this->contextResolver = new DecisionContextResolver(logger: $logger);
		$this->withdrawalService = new DecisionWithdrawalService();

	}//end __construct()

	/**
	 * Withdraw a decision.
	 *
	 * Pipeline: OR find (per-object read ACL / 404) → the `lifecycle →
	 * withdrawn` edge must be declared (any state before `enacted`) →
	 * chair-only gate when the body's policy restricts the edge (fail closed)
	 * → DecisionWithdrawalService stamps who withdrew it, why and when, and
	 * keeps the outcome (REQ-DWP-005) → saveObject (per-object write ACL) →
	 * hash-chained audit append and, for a delegated decision, the
	 * DecisionConcludedEvent.
	 *
	 * @param string $decisionId UUID of the decision to withdraw
	 * @param string $withdrawnBy Actor kind: bestuursorgaan|belanghebbende
	 * @param string $reason Why, written for the person who receives it
	 * @param string|null $currentUserId Nextcloud UID of the requesting user (chair gate + audit actor)
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-005)
	 *
	 * @return array{success: bool, decision: array|null, message: string}
	 */
	public function withdraw(string $decisionId, string $withdrawnBy, string $reason = '', ?string $currentUserId = null): array {
		if (in_array(needle: $withdrawnBy, haystack: DecisionWithdrawalService::ACTOR_KINDS, strict: true) === false) {
			return $this->failure(
				message: 'A withdrawal has to say who withdrew it: '
					. implode(' or ', DecisionWithdrawalService::ACTOR_KINDS) . '.'
			);
		}

		try {
			$decision = $this->contextResolver->loadDecision(objectService: $this->objectService, decisionId: $decisionId);
			if ($decision === null) {
				return $this->failure(message: "Decision '$decisionId' not found.");
			}

			$currentLifecycle = (string)($decision['lifecycle'] ?? 'draft');
			$rejection = $this->resolveRejection(
				decision: $decision,
				currentLifecycle: $currentLifecycle,
				currentUserId: $currentUserId
			);
			if ($rejection !== null) {
				return $this->failure(message: $rejection);
			}

			try {
				$withdrawn = $this->withdrawalService->withdraw(
					decision: $decision,
					withdrawnBy: $withdrawnBy,
					reason: $reason
				);
			} catch (InvalidArgumentException $e) {
				return $this->failure(message: $e->getMessage());
			}

			return $this->persistWithdrawal(
				decision: $decision,
				decisionId: $decisionId,
				withdrawn: $withdrawn,
				currentLifecycle: $currentLifecycle,
				currentUserId: $currentUserId
			);
		} catch (DoesNotExistException) {
			return $this->failure(message: "Decision '$decisionId' not found.");
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidiq: decision withdrawal failed',
				['id' => $decisionId, 'exception' => $e->getMessage()]
			);
			return $this->failure(message: 'Withdrawal failed. See server log for details.');
		}//end try

	}//end withdraw()

	/**
	 * Resolve why a withdrawal must be refused, or null when it may proceed.
	 *
	 * Evaluated in order: the `lifecycle → withdrawn` edge must be declared,
	 * then the chair-only gate (fail closed) shared with transition().
	 *
	 * @param array<string, mixed> $decision Decision object array
	 * @param string $currentLifecycle The decision's current lifecycle state
	 * @param string|null $currentUserId Nextcloud UID of the requesting user
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return string|null Rejection message, or null when the withdrawal is permitted
	 */
	private function resolveRejection(array $decision, string $currentLifecycle, ?string $currentUserId): ?string {
		if ($this->transitionGuard->isWithdrawable(lifecycle: $currentLifecycle) === false) {
			return "A decision in '$currentLifecycle' state cannot be withdrawn. "
				. 'Withdrawal is possible from: ' . implode(', ', DecisionTransitionGuard::WITHDRAWABLE_STATES) . '.';
		}

		return $this->lifecycleService->resolveChairRejectionForDecision(
			decision: $decision,
			currentLifecycle: $currentLifecycle,
			newState: 'withdrawn',
			currentUserId: $currentUserId
		);

	}//end resolveRejection()

	/**
	 * Persist the withdrawal and run the shared post-transition effects.
	 *
	 * Persists only the declared withdrawal properties beside the new
	 * lifecycle; the append-only history lives in the hash-chained audit
	 * entry, not in an undeclared `history` property. `outcome` is
	 * deliberately not in the patch: it stays exactly as it was.
	 *
	 * @param array<string, mixed> $decision Decision object array (pre-withdrawal)
	 * @param string $decisionId UUID of the decision being withdrawn
	 * @param array<string, mixed> $withdrawn Decision as stamped by DecisionWithdrawalService
	 * @param string $currentLifecycle The pre-withdrawal lifecycle state
	 * @param string|null $currentUserId Nextcloud UID of the requesting user (audit actor)
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-005)
	 *
	 * @return array{success: bool, decision: array|null, message: string}
	 */
	private function persistWithdrawal(
		array $decision,
		string $decisionId,
		array $withdrawn,
		string $currentLifecycle,
		?string $currentUserId,
	): array {
		$patch = [
			'lifecycle' => 'withdrawn',
			'withdrawn' => true,
			'withdrawnAt' => $withdrawn['withdrawnAt'],
			'withdrawnBy' => $withdrawn['withdrawnBy'],
			'withdrawnReason' => $withdrawn['withdrawnReason'],
		];

		// Object-level write ACL: saveObject() throws when the session user lacks
		// write access on this specific object (caught by withdraw(), generic error).
		$updated = $this->objectService->saveObject(
			object: array_merge($decision, $patch),
			register: 'decidiq',
			schema: 'decision',
			uuid: $decisionId,
		);

		$this->lifecycleService->applyPostTransitionEffects(
			objectService: $this->objectService,
			decision: array_merge($decision, $patch),
			decisionId: $decisionId,
			action: 'withdraw',
			currentLifecycle: $currentLifecycle,
			newState: 'withdrawn',
			currentUserId: $currentUserId,
			comment: $patch['withdrawnBy'] . ': ' . $patch['withdrawnReason']
		);

		return [
			'success' => true,
			'decision' => $updated->jsonSerialize(),
			'message' => "Decision transitioned to 'withdrawn'.",
		];

	}//end persistWithdrawal()

	/**
	 * Build the uniform failure result.
	 *
	 * @param string $message Why the withdrawal did not happen
	 *
	 * @return array{success: bool, decision: null, message: string}
	 */
	private function failure(string $message): array {
		return [
			'success' => false,
			'decision' => null,
			'message' => $message,
		];

	}//end failure()
}//end class
