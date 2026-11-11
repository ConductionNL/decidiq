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
 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-012, REQ-AR-013)
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
 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-013)
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
	 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-012)
	 */
	public function assertStepIsResolvable(array $step): void {
		$actor = trim((string)($step['actor'] ?? ''));
		$rule = self::ruleOf(step: $step);

		if ($actor !== '' && $rule !== '') {
			// Two answers to the same question, and whichever the engine reads
			// first wins. That is a bug nobody can reproduce.
			throw new RuntimeException(
				sprintf('This step names both an actor (%s) and a rule (%s); it can carry one or the other.', $actor, $rule)
			);
		}

		self::assertKnownRule(rule: $rule);
		self::assertRuleNamesItsSubject(step: $step, rule: $rule);
	}//end assertStepIsResolvable()

	/**
	 * Resolve a step's rule to one person, at the moment the stage becomes
	 * active.
	 *
	 * @param ActorResolution $resolution The step, the organisation records and what the rule may need besides.
	 *
	 * @return array{actor: string, actorResolvedBy: string, actorResolvedAt: string} The resolved actor and its provenance.
	 *
	 * @throws RuntimeException When the rule resolves to nobody, to more than one person, or to somebody with no account.
	 *
	 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-013)
	 */
	public function resolve(ActorResolution $resolution): array {
		$this->assertStepIsResolvable(step: $resolution->step);
		$rule = self::ruleToResolve(resolution: $resolution);
		$reading = $this->readingFor(resolution: $resolution, rule: $rule);
		$resolved = $this->signableCandidate(resolution: $resolution, reading: $reading);

		return [
			'actor' => $resolved,
			'actorResolvedBy' => $rule,
			'actorResolvedAt' => self::instantOr(resolvedAt: $resolution->resolvedAt),
		];
	}//end resolve()

	/**
	 * The one person a rule resolves to, once they are known to have an account.
	 *
	 * @param ActorResolution  $resolution The resolution, carrying the people and the account check.
	 * @param ActorRuleReading $reading    The reading.
	 *
	 * @return string The resolved person.
	 *
	 * @throws RuntimeException When the rule resolves to nobody, to more than one, or to somebody with no account.
	 */
	private function signableCandidate(ActorResolution $resolution, ActorRuleReading $reading): string {
		$resolved = $this->soleCandidate(people: $resolution->people, reading: $reading);

		if ($resolution->hasAccount !== null && ($resolution->hasAccount)($resolved) === false) {
			throw new RuntimeException(
				sprintf('The rule "%s" resolved to %s, who has no account on this instance and cannot sign anything.', $reading->rule, $resolved)
			);
		}

		return $resolved;
	}//end signableCandidate()

	/**
	 * The rule a step names, trimmed; empty when it names none.
	 *
	 * @param array<string, mixed> $step The step.
	 *
	 * @return string The rule.
	 */
	private static function ruleOf(array $step): string {
		return trim((string)($step['actorRule'] ?? ''));
	}//end ruleOf()

	/**
	 * Refuse a rule that is not one of the known rules.
	 *
	 * @param string $rule The rule, empty when the step names none.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the rule is unknown.
	 */
	private static function assertKnownRule(string $rule): void {
		if ($rule !== '' && in_array($rule, self::RULES, true) === false) {
			throw new RuntimeException(
				sprintf('Unknown actor rule "%s"; expected one of %s.', $rule, implode(', ', self::RULES))
			);
		}
	}//end assertKnownRule()

	/**
	 * Refuse a rule that reads a named person when the step names nobody.
	 *
	 * @param array<string, mixed> $step The step.
	 * @param string               $rule The rule it names.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the subject is missing.
	 */
	private static function assertRuleNamesItsSubject(array $step, string $rule): void {
		if (in_array($rule, self::RULES_NEEDING_A_SUBJECT, true) === true
			&& trim((string)($step['actorRuleSubject'] ?? '')) === ''
		) {
			throw new RuntimeException(
				sprintf('The rule "%s" has to say whose manager or substitute it means.', $rule)
			);
		}
	}//end assertRuleNamesItsSubject()

	/**
	 * The rule to resolve, once there is a rule and there are people to read it against.
	 *
	 * @param ActorResolution $resolution The resolution.
	 *
	 * @return string The rule.
	 *
	 * @throws RuntimeException When the step names a person, or no person record is readable.
	 */
	private static function ruleToResolve(ActorResolution $resolution): string {
		$rule = self::ruleOf(step: $resolution->step);
		if ($rule === '') {
			throw new RuntimeException('This step names a person, not a rule; there is nothing to resolve.');
		}

		if ($resolution->people === []) {
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

		return $rule;
	}//end ruleToResolve()

	/**
	 * The instant a resolution is stamped with: the given one, or now.
	 *
	 * @param string $resolvedAt The given instant, empty for now.
	 *
	 * @return string The instant, as ISO-8601.
	 */
	private static function instantOr(string $resolvedAt): string {
		if ($resolvedAt === '') {
			return (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);
		}

		return $resolvedAt;
	}//end instantOr()

	/**
	 * The person the rule is read against, and the reference it reads.
	 *
	 * @param ActorResolution $resolution The resolution, carrying the step and the subject's owner.
	 * @param string          $rule       The rule being resolved.
	 *
	 * @return ActorRuleReading The reading.
	 *
	 * @throws RuntimeException When there is nobody to read against.
	 */
	private function readingFor(ActorResolution $resolution, string $rule): ActorRuleReading {
		$readAgainst = trim((string)($resolution->step['actorRuleSubject'] ?? ''));
		if ($rule === self::RULE_MANAGER_OF_SUBJECT_OWNER) {
			$readAgainst = trim($resolution->subjectOwner);
		}

		if ($readAgainst === '') {
			throw new RuntimeException(
				sprintf('The rule "%s" has nobody to read against; the subject has no owner on record.', $rule)
			);
		}

		$reading = new ActorRuleReading(rule: $rule, person: $readAgainst);
		$reading->property = $this->propertyFor(rule: $rule);

		return $reading;
	}//end readingFor()

	/**
	 * The organisation-record property a rule reads.
	 *
	 * @param string $rule The rule being resolved.
	 *
	 * @return string The property name.
	 */
	private function propertyFor(string $rule): string {
		if ($rule === self::RULE_SUBSTITUTE_OF_ACTOR) {
			return 'substitute';
		}

		return 'manager';
	}//end propertyFor()

	/**
	 * The one person a rule resolves to, or a refusal.
	 *
	 * @param array<int, mixed> $people  The organisation records.
	 * @param ActorRuleReading  $reading The rule, the person it is read against and the property it reads.
	 *
	 * @return string The resolved person.
	 *
	 * @throws RuntimeException When the rule resolves to nobody, or to more than one.
	 */
	private function soleCandidate(array $people, ActorRuleReading $reading): string {
		$candidates = $this->candidatesFor(people: $people, reading: $reading);

		if ($candidates === []) {
			throw new RuntimeException(
				sprintf('The rule "%s" resolved to nobody for %s; the organisation record names no %s.', $reading->rule, $reading->person, $reading->property)
			);
		}

		if (count($candidates) > 1) {
			// Picking the first of two is picking at random, and the random
			// choice is then recorded as a deliberate one.
			throw self::ambiguity(reading: $reading, candidates: $candidates);
		}

		return $candidates[0];
	}//end soleCandidate()

	/**
	 * The refusal for a rule that resolves to more than one person.
	 *
	 * @param ActorRuleReading   $reading    The reading.
	 * @param array<int, string> $candidates Everybody it resolved to.
	 *
	 * @return RuntimeException The refusal, naming them all.
	 */
	private static function ambiguity(ActorRuleReading $reading, array $candidates): RuntimeException {
		return new RuntimeException(
			sprintf(
				'The rule "%s" resolved to more than one person for %s: %s. The organisation record has to say which.',
				$reading->rule,
				$reading->person,
				implode(' and ', $candidates)
			)
		);
	}//end ambiguity()

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
	 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-016)
	 */
	public function substituteOf(array $people, string $person): ?string {
		$reading = new ActorRuleReading(rule: self::RULE_SUBSTITUTE_OF_ACTOR, person: $person);
		$reading->property = 'substitute';
		$candidates = $this->candidatesFor(people: $people, reading: $reading);

		// More than one is as unusable as none: there is no basis to pick.
		if (count($candidates) === 1) {
			return $candidates[0];
		}

		return null;
	}//end substituteOf()

	/**
	 * Who the organisation record names as this person's manager or substitute.
	 *
	 * @param array<int, array<string, mixed>> $people  The person records.
	 * @param ActorRuleReading                 $reading The person to read and which reference to read.
	 *
	 * @return array<int, string> The candidates, without duplicates.
	 */
	private function candidatesFor(array $people, ActorRuleReading $reading): array {
		$candidates = [];

		foreach ($people as $record) {
			if (is_array($record) === false || $this->identifies(record: $record, person: $reading->person) === false) {
				continue;
			}

			$candidates = self::withoutDuplicates(
				candidates: $candidates,
				references: $this->referencesOf(record: $record, property: $reading->property)
			);
		}

		return $candidates;
	}//end candidatesFor()

	/**
	 * The candidates so far, followed by the references not yet among them.
	 *
	 * @param array<int, string> $candidates The candidates so far.
	 * @param array<int, string> $references One record's references, in order.
	 *
	 * @return array<int, string> The candidates, first occurrence kept.
	 */
	private static function withoutDuplicates(array $candidates, array $references): array {
		foreach ($references as $reference) {
			if (in_array($reference, $candidates, true) === false) {
				$candidates[] = $reference;
			}
		}

		return $candidates;
	}//end withoutDuplicates()

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
	 * @return array<int, string> The non-empty string references, in order.
	 */
	private function referencesOf(array $record, string $property): array {
		$value = ($record[$property] ?? null);

		if (is_string($value) === true) {
			$value = [$value];
		}

		if (is_array($value) === false) {
			return [];
		}

		return array_values(
			array_filter(
				$value,
				static fn (mixed $entry): bool => is_string($entry) === true && $entry !== ''
			)
		);
	}//end referencesOf()
}//end class
