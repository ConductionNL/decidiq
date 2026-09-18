<?php

/**
 * Approval Actor Resolver
 *
 * A step can name a rule instead of a person: the manager of the actor, the
 * manager of whoever owns the subject, the actor's substitute. This turns the
 * rule into a person.
 *
 * IT FAILS CLOSED, AND THAT IS THE ENTIRE DESIGN
 * -----------------------------------------------
 * Nobody, more than one person, or a person with no account on this instance
 * all refuse the activation and say which rule against which subject could not
 * be resolved. There is deliberately no fall-back to the route's owner, to the
 * previous step's actor, or to an administrator. Every one of those produces a
 * sign-off from somebody the route never asked for, and it looks exactly like a
 * sign-off from somebody it did. A refused activation is visible in a minute; a
 * silently reassigned approval is found in an audit, if at all.
 *
 * TWO CANDIDATES IS A REFUSAL, NOT A CHOICE
 * ------------------------------------------
 * Picking the first of two managers is picking at random, and the random choice
 * is recorded as a deliberate one. The organisation record is wrong and a human
 * has to fix it.
 *
 * IT READS THE ORGANISATION RECORD AND NOTHING ELSE
 * --------------------------------------------------
 * Through OpenRegister, by schema marker. No service class resolved out of
 * another app's container, no HTTP call to a sibling app (REQ-AR-013, gate-27).
 * A resolver that reached into humaniq would make decidiq unable to boot
 * without it, for a lookup that is one query.
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
 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-012, REQ-AR-013)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use RuntimeException;

/**
 * Resolves a step's actor rule to one person, or refuses.
 *
 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-013)
 */
final class ApprovalActorResolver {
	/**
	 * The manager of the person the step names.
	 *
	 * @var string
	 */
	public const RULE_MANAGER_OF_ACTOR = 'manager-of-actor';

	/**
	 * The manager of whoever owns the subject.
	 *
	 * @var string
	 */
	public const RULE_MANAGER_OF_SUBJECT_OWNER = 'manager-of-subject-owner';

	/**
	 * The substitute of the person the step names.
	 *
	 * @var string
	 */
	public const RULE_SUBSTITUTE_OF_ACTOR = 'substitute-of-actor';

	/**
	 * The rules a step may name.
	 *
	 * @var array<int, string>
	 */
	public const RULES = [
		self::RULE_MANAGER_OF_ACTOR,
		self::RULE_MANAGER_OF_SUBJECT_OWNER,
		self::RULE_SUBSTITUTE_OF_ACTOR,
	];

	/**
	 * The rules that read a person named on the step.
	 *
	 * @var array<int, string>
	 */
	private const RULES_NEEDING_A_SUBJECT = [self::RULE_MANAGER_OF_ACTOR, self::RULE_SUBSTITUTE_OF_ACTOR];

	/**
	 * Refuse a step that cannot say who it is for.
	 *
	 * @param array<string, mixed> $step The step.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the step names both an actor and a rule, or an unknown rule.
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-012)
	 */
	public function assertStepIsResolvable(array $step): void {
		$actor = trim((string)($step['actor'] ?? ''));
		$rule = trim((string)($step['actorRule'] ?? ''));

		if ($actor !== '' && $rule !== '') {
			// Two answers to the same question, and whichever the engine reads
			// first wins. That is a bug nobody can reproduce.
			throw new RuntimeException(
				sprintf('This step names both an actor (%s) and a rule (%s); it can carry one or the other.', $actor, $rule)
			);
		}

		if ($rule !== '' && in_array($rule, self::RULES, true) === false) {
			throw new RuntimeException(
				sprintf('Unknown actor rule "%s"; expected one of %s.', $rule, implode(', ', self::RULES))
			);
		}

		if (in_array($rule, self::RULES_NEEDING_A_SUBJECT, true) === true
			&& trim((string)($step['actorRuleSubject'] ?? '')) === ''
		) {
			throw new RuntimeException(
				sprintf('The rule "%s" has to say whose manager or substitute it means.', $rule)
			);
		}
	}//end assertStepIsResolvable()

	/**
	 * Resolve a step's rule to one person, at the moment the stage becomes
	 * active.
	 *
	 * @param array<string, mixed> $step The step.
	 * @param array<int, array<string, mixed>> $people The person records readable from the organisation register.
	 * @param string $subjectOwner Who owns the subject, for the rule that reads it.
	 * @param callable(string): bool $hasAccount Whether a person id has an account on this instance.
	 * @param string $resolvedAt When, as an ISO-8601 instant; now when empty.
	 *
	 * @return array{actor: string, actorResolvedBy: string, actorResolvedAt: string} The resolved actor and its provenance.
	 *
	 * @throws RuntimeException When the rule resolves to nobody, to more than one person, or to somebody with no account.
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-013)
	 */
	public function resolve(
		array $step,
		array $people,
		string $subjectOwner = '',
		?callable $hasAccount = null,
		string $resolvedAt = '',
	): array {
		$this->assertStepIsResolvable($step);

		$rule = trim((string)($step['actorRule'] ?? ''));
		if ($rule === '') {
			throw new RuntimeException('This step names a person, not a rule; there is nothing to resolve.');
		}

		if ($people === []) {
			// No installed schema implements the person kind. Refusing here is
			// the difference between "we could not find the manager" and "we
			// asked somebody else instead".
			throw new RuntimeException(
				sprintf(
					'The rule "%s" cannot be resolved: no readable organisation record implements the person kind on this instance.',
					$rule
				)
			);
		}

		$readAgainst = ($rule === self::RULE_MANAGER_OF_SUBJECT_OWNER
			? trim($subjectOwner)
			: trim((string)($step['actorRuleSubject'] ?? '')));

		if ($readAgainst === '') {
			throw new RuntimeException(
				sprintf('The rule "%s" has nobody to read against; the subject has no owner on record.', $rule)
			);
		}

		$property = ($rule === self::RULE_SUBSTITUTE_OF_ACTOR ? 'substitute' : 'manager');
		$candidates = $this->candidatesFor(people: $people, person: $readAgainst, property: $property);

		if ($candidates === []) {
			throw new RuntimeException(
				sprintf('The rule "%s" resolved to nobody for %s; the organisation record names no %s.', $rule, $readAgainst, $property)
			);
		}

		if (count($candidates) > 1) {
			// Picking the first of two is picking at random, and the random
			// choice is then recorded as a deliberate one.
			throw new RuntimeException(
				sprintf(
					'The rule "%s" resolved to more than one person for %s: %s. The organisation record has to say which.',
					$rule,
					$readAgainst,
					implode(' and ', $candidates)
				)
			);
		}

		$resolved = $candidates[0];

		if ($hasAccount !== null && $hasAccount($resolved) === false) {
			throw new RuntimeException(
				sprintf('The rule "%s" resolved to %s, who has no account on this instance and cannot sign anything.', $rule, $resolved)
			);
		}

		return [
			'actor' => $resolved,
			'actorResolvedBy' => $rule,
			'actorResolvedAt' => ($resolvedAt === '' ? (new DateTimeImmutable())->format(DateTimeImmutable::ATOM) : $resolvedAt),
		];
	}//end resolve()

	/**
	 * Resolve the substitute of a person, returning null rather than throwing.
	 *
	 * No substitute is a normal state of affairs: it must be a note on the stage
	 * and must not stop the original actor from signing (REQ-AR-016). That is
	 * why this does not reuse `resolve()`'s refusals.
	 *
	 * @param array<int, array<string, mixed>> $people The person records.
	 * @param string $person Whose substitute to find.
	 *
	 * @return string|null The substitute, or null when there is not exactly one.
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-016)
	 */
	public function substituteOf(array $people, string $person): ?string {
		$candidates = $this->candidatesFor(people: $people, person: $person, property: 'substitute');

		// More than one is as unusable as none: there is no basis to pick.
		return (count($candidates) === 1 ? $candidates[0] : null);
	}//end substituteOf()

	/**
	 * Who the organisation record names as this person's manager or substitute.
	 *
	 * @param array<int, array<string, mixed>> $people The person records.
	 * @param string $person The person to read.
	 * @param string $property Which reference to read.
	 *
	 * @return array<int, string> The candidates, without duplicates.
	 */
	private function candidatesFor(array $people, string $person, string $property): array {
		$candidates = [];

		foreach ($people as $record) {
			if (is_array($record) === false) {
				continue;
			}

			if ($this->identifies($record, $person) === false) {
				continue;
			}

			foreach ($this->referencesOf($record, $property) as $reference) {
				if ($reference !== '' && in_array($reference, $candidates, true) === false) {
					$candidates[] = $reference;
				}
			}
		}

		return $candidates;
	}//end candidatesFor()

	/**
	 * Whether a person record is the person being read.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param string $person The person.
	 *
	 * @return bool True when it is them.
	 */
	private function identifies(array $record, string $person): bool {
		foreach (['id', 'uuid', 'userId', 'accountName'] as $key) {
			if ((string)($record[$key] ?? '') === $person) {
				return true;
			}
		}

		return false;
	}//end identifies()

	/**
	 * The references a record carries under a property, whether it holds one or
	 * a list.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param string $property The property.
	 *
	 * @return array<int, string> The references.
	 */
	private function referencesOf(array $record, string $property): array {
		$value = ($record[$property] ?? null);

		if (is_string($value) === true) {
			return ($value === '' ? [] : [$value]);
		}

		if (is_array($value) === false) {
			return [];
		}

		$references = [];
		foreach ($value as $entry) {
			if (is_string($entry) === true && $entry !== '') {
				$references[] = $entry;
			}
		}

		return $references;
	}//end referencesOf()
}//end class
