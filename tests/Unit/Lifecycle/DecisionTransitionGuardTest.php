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
class DecisionTransitionGuardTest extends TestCase
{

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
    protected function setUp(): void
    {
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
    public function testAvailableActionsMatrixOperationsDomain(): void
    {
        $expected = [
            'draft'        => ['propose'],
            'proposed'     => ['deliberate'],
            'deliberating' => ['openVoting', 'decide'],
            'voting'       => ['decide'],
            'decided'      => ['enact', 'archive'],
            'enacted'      => ['archive'],
            'archived'     => [],
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
    public function testDecideWithoutVoteFilteredInStrictDomains(): void
    {
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
    public function testResolveTransition(): void
    {
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
    public function testChairOnlyTransitions(): void
    {
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
    public function testQuorumRequiredPerDomain(): void
    {
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
    public function testUnknownDomainDefaultDeny(): void
    {
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
    public function testIsVotingOpenAllowedReadsQuorumMet(): void
    {
        self::assertTrue(condition: $this->guard->isVotingOpenAllowed(meeting: ['quorumMet' => true]));
        self::assertFalse(condition: $this->guard->isVotingOpenAllowed(meeting: ['quorumMet' => false]));
        self::assertFalse(condition: $this->guard->isVotingOpenAllowed(meeting: []));
        self::assertFalse(condition: $this->guard->isVotingOpenAllowed(meeting: ['quorumMet' => 'yes']));

    }//end testIsVotingOpenAllowedReadsQuorumMet()

    /**
     * Only adopted decisions may be enacted.
     *
     * @spec openspec/specs/decision-management/spec.md
     *
     * @return void
     */
    public function testIsEnactAllowedRequiresAdoptedOutcome(): void
    {
        self::assertTrue(condition: $this->guard->isEnactAllowed(decision: ['outcome' => 'adopted']));
        self::assertFalse(condition: $this->guard->isEnactAllowed(decision: ['outcome' => 'rejected']));
        self::assertFalse(condition: $this->guard->isEnactAllowed(decision: []));

    }//end testIsEnactAllowedRequiresAdoptedOutcome()

    /**
     * STATES lists the 7 lifecycle states in machine order.
     *
     * @spec openspec/specs/decision-management/spec.md
     *
     * @return void
     */
    public function testStatesOrder(): void
    {
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
    public function testPolicyOverrideReplacesDomainLookup(): void
    {
        $override = [
            'quorumEnforced'         => true,
            'chairOnlyTransitions'   => ['deliberating:voting'],
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
    public function testNullPolicyOverrideFallsBackToHardcodedDomain(): void
    {
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
