<?php

/**
 * Unit tests for DecisionTransitionGuard.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/decision-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Lifecycle;

use OCA\Decidesk\Lifecycle\DecisionTransitionGuard;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive tests for the decision lifecycle transition map and the
 * per-domain policy gates (chair-only, quorum, decide-without-vote,
 * unknown-domain default-deny).
 *
 * @spec openspec/specs/decision-management/spec.md
 */
class DecisionTransitionGuardTest extends TestCase {

	/**
	 * Guard under test.
	 *
	 * @var DecisionTransitionGuard
	 */
	private DecisionTransitionGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->guard = new DecisionTransitionGuard();

	}//end setUp()

	/**
	 * The full action availability matrix: every state × every action, in a
	 * permissive domain (operations: decide-without-vote allowed).
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testAvailableActionsMatrixOperationsDomain(): void {
		$expected = [
			'draft' => ['propose'],
			'proposed' => ['deliberate'],
			'deliberating' => ['openVoting', 'decide'],
			'voting' => ['decide'],
			'decided' => ['enact', 'archive'],
			'enacted' => ['archive'],
			'archived' => [],
		];

		foreach ($expected as $state => $actions) {
			self::assertSame(
				expected: $actions,
				actual: $this->guard->getAvailableActions(currentLifecycle: $state, domain: 'operations'),
				message: "Available actions from '$state' (operations)"
			);
		}

	}//end testAvailableActionsMatrixOperationsDomain()

	/**
	 * In strict domains the deliberating → decided shortcut is filtered out.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testDecideWithoutVoteFilteredInStrictDomains(): void {
		foreach (['legislative', 'association', 'corporate'] as $domain) {
			self::assertSame(
				expected: ['openVoting'],
				actual: $this->guard->getAvailableActions(currentLifecycle: 'deliberating', domain: $domain),
				message: "deliberating actions in '$domain'"
			);
		}

	}//end testDecideWithoutVoteFilteredInStrictDomains()

	/**
	 * Unknown actions resolve to null; known actions resolve to their entry.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testResolveTransition(): void {
		self::assertNull(actual: $this->guard->resolveTransition(action: 'teleport'));
		self::assertNull(actual: $this->guard->resolveTransition(action: ''));

		$enact = $this->guard->resolveTransition(action: 'enact');
		self::assertSame(expected: 'enacted', actual: $enact['to']);
		self::assertSame(expected: ['decided'], actual: $enact['from']);

		self::assertSame(
			expected: ['propose', 'deliberate', 'openVoting', 'decide', 'enact', 'archive'],
			actual: $this->guard->getKnownActions()
		);

	}//end testResolveTransition()

	/**
	 * Chair-only pairs per domain, including the unknown-domain fallback.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testChairOnlyTransitions(): void {
		// Legislative: both sensitive edges chair-only.
		self::assertTrue(condition: $this->guard->requiresChairAuthorization(domain: 'legislative', from: 'deliberating', to: 'voting'));
		self::assertTrue(condition: $this->guard->requiresChairAuthorization(domain: 'legislative', from: 'voting', to: 'decided'));

		// Association / corporate: only opening the vote is chair-only.
		self::assertTrue(condition: $this->guard->requiresChairAuthorization(domain: 'association', from: 'deliberating', to: 'voting'));
		self::assertFalse(condition: $this->guard->requiresChairAuthorization(domain: 'association', from: 'voting', to: 'decided'));
		self::assertTrue(condition: $this->guard->requiresChairAuthorization(domain: 'corporate', from: 'deliberating', to: 'voting'));

		// Operations / citizen: nothing chair-only.
		self::assertFalse(condition: $this->guard->requiresChairAuthorization(domain: 'operations', from: 'deliberating', to: 'voting'));
		self::assertFalse(condition: $this->guard->requiresChairAuthorization(domain: 'citizen', from: 'voting', to: 'decided'));

		// Unknown domain falls back to the restricted (most chair-gated) policy.
		self::assertTrue(condition: $this->guard->requiresChairAuthorization(domain: 'galactic', from: 'deliberating', to: 'voting'));
		self::assertTrue(condition: $this->guard->requiresChairAuthorization(domain: 'galactic', from: 'voting', to: 'decided'));

	}//end testChairOnlyTransitions()

	/**
	 * Quorum enforcement flag per domain, default-deny for unknown domains.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testQuorumRequiredPerDomain(): void {
		self::assertTrue(condition: $this->guard->isQuorumRequired(domain: 'legislative'));
		self::assertTrue(condition: $this->guard->isQuorumRequired(domain: 'association'));
		self::assertTrue(condition: $this->guard->isQuorumRequired(domain: 'corporate'));
		self::assertFalse(condition: $this->guard->isQuorumRequired(domain: 'operations'));
		self::assertFalse(condition: $this->guard->isQuorumRequired(domain: 'citizen'));
		self::assertTrue(condition: $this->guard->isQuorumRequired(domain: 'does-not-exist'));

	}//end testQuorumRequiredPerDomain()

	/**
	 * Unknown domains never get a more permissive decide-without-vote edge.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testUnknownDomainDefaultDeny(): void {
		self::assertFalse(
			condition: $this->guard->isTransitionAllowed(domain: 'injected-domain', fromState: 'deliberating', toState: 'decided')
		);
		self::assertSame(
			expected: ['openVoting'],
			actual: $this->guard->getAvailableActions(currentLifecycle: 'deliberating', domain: 'injected-domain')
		);

	}//end testUnknownDomainDefaultDeny()

	/**
	 * The voting-open gate reads the declarative quorumMet meeting field.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testIsVotingOpenAllowedReadsQuorumMet(): void {
		self::assertTrue(condition: $this->guard->isVotingOpenAllowed(meeting: ['quorumWith' => true]));
		self::assertFalse(condition: $this->guard->isVotingOpenAllowed(meeting: ['quorumWith' => false]));
		self::assertFalse(condition: $this->guard->isVotingOpenAllowed(meeting: []));
		self::assertFalse(condition: $this->guard->isVotingOpenAllowed(meeting: ['quorumWith' => 'yes']));

	}//end testIsVotingOpenAllowedReadsQuorumMet()

	/**
	 * Only adopted decisions may be enacted.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testIsEnactAllowedRequiresAdoptedOutcome(): void {
		self::assertTrue(condition: $this->guard->isEnactAllowed(decision: ['outcome' => 'adopted']));
		self::assertFalse(condition: $this->guard->isEnactAllowed(decision: ['outcome' => 'rejected']));
		self::assertFalse(condition: $this->guard->isEnactAllowed(decision: []));

	}//end testIsEnactAllowedRequiresAdoptedOutcome()

	/**
	 * The terminal outcome states are exactly the states past the vote —
	 * `decided` and everything only reachable through it. `withdrawn` is
	 * terminal in the lifecycle graph but was never decided, so it is not one
	 * of them, and none of the in-flight states are.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testTerminalOutcomeStates(): void {
		self::assertSame(
			expected: ['decided', 'enacted', 'archived'],
			actual: DecisionTransitionGuard::TERMINAL_OUTCOME_STATES
		);

		foreach (['decided', 'enacted', 'archived'] as $terminal) {
			self::assertTrue(
				condition: $this->guard->isTerminalOutcomeState(lifecycle: $terminal),
				message: "'$terminal' must be treated as a terminal outcome state"
			);
		}

		foreach (['draft', 'proposed', 'deliberating', 'voting', 'withdrawn'] as $inFlight) {
			self::assertFalse(
				condition: $this->guard->isTerminalOutcomeState(lifecycle: $inFlight),
				message: "'$inFlight' must NOT demand an outcome"
			);
		}

	}//end testTerminalOutcomeStates()

	/**
	 * FAIL-CLOSED PIN.
	 *
	 * The terminal gate keys off the transition's target state, so a transition
	 * added later whose target nobody classified would slip through the gate
	 * silently — the same fail-open shape that `transitionLifecycle()` had when
	 * it chose MOTION_TRANSITIONS for every objectType that was not literally
	 * 'amendment'.
	 *
	 * Every target in the transition map must be explicitly either terminal or
	 * in-flight. Adding a transition to a new state without deciding which it
	 * is fails here rather than quietly skipping the completeness check.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testEveryTransitionTargetIsClassified(): void {
		// The in-flight states — the deliberate complement of
		// TERMINAL_OUTCOME_STATES over the whole lifecycle vocabulary.
		$inFlight = ['draft', 'proposed', 'deliberating', 'voting', 'withdrawn'];

		$targets = [];
		foreach ($this->guard->getKnownActions() as $action) {
			$transition = $this->guard->resolveTransition(action: $action);
			self::assertNotNull(actual: $transition, message: "action '$action' must resolve");
			$targets[] = $transition['to'];
		}

		self::assertNotEmpty(actual: $targets);

		foreach (array_unique($targets) as $target) {
			$isTerminal = in_array($target, DecisionTransitionGuard::TERMINAL_OUTCOME_STATES, true);
			$isInFlight = in_array($target, $inFlight, true);

			self::assertTrue(
				condition: ($isTerminal xor $isInFlight),
				message: "Transition target '$target' is not classified exactly once. Add it to "
					. 'DecisionTransitionGuard::TERMINAL_OUTCOME_STATES if reaching it means the decision has been '
					. 'decided (and so must carry outcome + decisionDate), or to this test\'s in-flight list if it '
					. 'must not demand them. Leaving it unclassified silently skips the terminal-completeness gate.'
			);
		}

	}//end testEveryTransitionTargetIsClassified()

	/**
	 * A complete decision reports nothing missing.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testCompleteDecisionHasNoMissingTerminalFields(): void {
		foreach (['adopted', 'rejected'] as $outcome) {
			self::assertSame(
				expected: [],
				actual: $this->guard->getMissingTerminalFields(
					decision: ['outcome' => $outcome, 'decisionDate' => '2026-04-10T21:00:00Z']
				)
			);
		}

	}//end testCompleteDecisionHasNoMissingTerminalFields()

	/**
	 * Every way a decision can be terminally incomplete is named precisely:
	 * absent, empty, whitespace-only, or an out-of-vocabulary outcome.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testMissingTerminalFieldsAreNamed(): void {
		$cases = [
			'both absent' => [[], ['outcome', 'decisionDate']],
			'outcome only' => [['decisionDate' => '2026-04-10T21:00:00Z'], ['outcome']],
			'decisionDate only' => [['outcome' => 'adopted'], ['decisionDate']],
			'empty strings' => [['outcome' => '', 'decisionDate' => ''], ['outcome', 'decisionDate']],
			'whitespace decisionDate' => [['outcome' => 'adopted', 'decisionDate' => '   '], ['decisionDate']],
			'nulls' => [['outcome' => null, 'decisionDate' => null], ['outcome', 'decisionDate']],
			// The shipped `motie-woonlasten-2025` seed carried this value; it is
			// an in-flight placeholder, not a recorded result.
			'outcome "pending"' => [['outcome' => 'pending', 'decisionDate' => '2026-04-10T21:00:00Z'], ['outcome']],
		];

		foreach ($cases as $label => [$decision, $expected]) {
			self::assertSame(
				expected: $expected,
				actual: $this->guard->getMissingTerminalFields(decision: $decision),
				message: "case: $label"
			);
		}

	}//end testMissingTerminalFieldsAreNamed()

	/**
	 * The terminal field list is exactly the pair that left the schema's
	 * unconditional `required[]`. If a future change adds one back to the
	 * schema without removing it here — or drops one here without restoring it
	 * to the schema — this pins the contract.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testTerminalRequiredFieldsMirrorTheRelaxedSchemaEntries(): void {
		self::assertSame(
			expected: ['outcome', 'decisionDate'],
			actual: DecisionTransitionGuard::TERMINAL_REQUIRED_FIELDS
		);

		$register = json_decode(
			json: (string)file_get_contents(__DIR__ . '/../../../lib/Settings/decidesk_register.json'),
			associative: true
		);
		$schema = $register['components']['schemas']['Decision'];

		foreach (DecisionTransitionGuard::TERMINAL_REQUIRED_FIELDS as $field) {
			self::assertContains(
				needle: $field,
				haystack: array_keys($schema['properties']),
				message: "Decision schema must still declare the '$field' property"
			);
			self::assertNotContains(
				needle: $field,
				haystack: $schema['required'],
				message: "'$field' is enforced at the transition boundary, so it must NOT be "
					. 'unconditionally required on the schema — an in-flight motion has neither.'
			);
		}

		self::assertSame(
			expected: DecisionTransitionGuard::OUTCOME_VALUES,
			actual: $schema['properties']['outcome']['enum'],
			message: 'The guard vocabulary must mirror the schema enum exactly.'
		);

	}//end testTerminalRequiredFieldsMirrorTheRelaxedSchemaEntries()

	/**
	 * STATES lists the 7 lifecycle states in machine order.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testStatesOrder(): void {
		self::assertSame(
			expected: ['draft', 'proposed', 'deliberating', 'voting', 'decided', 'enacted', 'archived'],
			actual: DecisionTransitionGuard::STATES
		);

	}//end testStatesOrder()

	/**
	 * process-configuration: a non-null policyOverride REPLACES the domain lookup.
	 * The 'operations' domain normally allows decide-without-vote; an override
	 * that forbids it must make the deliberating -> decided edge disallowed even
	 * under 'operations'.
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return void
	 */
	public function testPolicyOverrideReplacesDomainLookup(): void {
		$override = [
			'quorumEnforced' => true,
			'chairOnlyTransitions' => ['deliberating:voting'],
			'allowDecideWithoutVote' => false,
		];

		// Without the override, operations allows the shortcut.
		self::assertTrue(
			condition: $this->guard->isTransitionAllowed(domain: 'operations', fromState: 'deliberating', toState: 'decided')
		);

		// With the override, the shortcut is forbidden.
		self::assertFalse(
			condition: $this->guard->isTransitionAllowed(domain: 'operations', fromState: 'deliberating', toState: 'decided', policyOverride: $override)
		);

		// The override's chair-only set is consulted, not the domain's empty one.
		self::assertTrue(
			condition: $this->guard->requiresChairAuthorization(domain: 'operations', from: 'deliberating', to: 'voting', policyOverride: $override)
		);
		self::assertFalse(
			condition: $this->guard->requiresChairAuthorization(domain: 'operations', from: 'deliberating', to: 'voting')
		);

		// Quorum follows the override.
		self::assertTrue(condition: $this->guard->isQuorumRequired(domain: 'operations', policyOverride: $override));
		self::assertFalse(condition: $this->guard->isQuorumRequired(domain: 'operations'));

	}//end testPolicyOverrideReplacesDomainLookup()

	/**
	 * process-configuration: a null policyOverride is byte-identical to the
	 * pre-process-config behaviour (the hardcoded domain constants apply).
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return void
	 */
	public function testNullPolicyOverrideFallsBackToHardcodedDomain(): void {
		// legislative is the strictest hardcoded domain: chair-only on both
		// sensitive edges, quorum enforced.
		self::assertTrue(condition: $this->guard->isQuorumRequired(domain: 'legislative', policyOverride: null));
		self::assertTrue(
			condition: $this->guard->requiresChairAuthorization(domain: 'legislative', from: 'voting', to: 'decided', policyOverride: null)
		);
		// Unknown domain still default-denies the decide-without-vote shortcut.
		self::assertFalse(
			condition: $this->guard->isTransitionAllowed(domain: 'totally-unknown', fromState: 'deliberating', toState: 'decided', policyOverride: null)
		);

	}//end testNullPolicyOverrideFallsBackToHardcodedDomain()
}//end class
