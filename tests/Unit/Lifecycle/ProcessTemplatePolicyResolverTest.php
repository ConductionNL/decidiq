<?php

/**
 * Unit tests for ProcessTemplatePolicyResolver.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/process-configuration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Lifecycle;

use OCA\Decidiq\Lifecycle\ProcessTemplatePolicyResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure template -> guard-policy translation, including the fail-safe
 * null path that reverts a malformed template to the hardcoded default-deny.
 *
 * @spec openspec/specs/process-configuration/spec.md
 */
class ProcessTemplatePolicyResolverTest extends TestCase {

	/**
	 * Resolver under test.
	 *
	 * @var ProcessTemplatePolicyResolver
	 */
	private ProcessTemplatePolicyResolver $resolver;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new ProcessTemplatePolicyResolver();

	}//end setUp()

	/**
	 * A well-formed template maps chairOnly transitions, quorum and
	 * decide-without-vote into the guard policy shape.
	 *
	 * @return void
	 */
	public function testResolveTranslatesTemplateToPolicy(): void {
		$template = [
			'quorumRequired' => true,
			'allowDecideWithoutVote' => false,
			'stateMachine' => [
				'transitions' => [
					['from' => 'draft', 'to' => 'proposed'],
					['from' => 'deliberating', 'to' => 'voting', 'chairOnly' => true],
					['from' => 'voting', 'to' => 'decided', 'guards' => ['chair_only']],
				],
			],
		];

		$policy = $this->resolver->resolve($template);

		$this->assertNotNull($policy);
		$this->assertTrue($policy['quorumEnforced']);
		$this->assertFalse($policy['allowDecideWithoutVote']);
		$this->assertContains('deliberating:voting', $policy['chairOnlyTransitions']);
		$this->assertContains('voting:decided', $policy['chairOnlyTransitions'], 'A chair_only guard token must also mark the edge chair-only.');
		$this->assertNotContains('draft:proposed', $policy['chairOnlyTransitions']);

	}//end testResolveTranslatesTemplateToPolicy()

	/**
	 * A null template yields a null override (caller falls back).
	 *
	 * @return void
	 */
	public function testResolveNullTemplateReturnsNull(): void {
		$this->assertNull($this->resolver->resolve(null));

	}//end testResolveNullTemplateReturnsNull()

	/**
	 * A template missing its state machine yields a null override — a malformed
	 * template never loosens a guard, it reverts to default-deny (fail-safe).
	 *
	 * @return void
	 */
	public function testResolveMalformedTemplateReturnsNullFailSafe(): void {
		$this->assertNull($this->resolver->resolve(['name' => 'broken']));
		$this->assertNull($this->resolver->resolve(['stateMachine' => 'not-an-array']));
		$this->assertNull($this->resolver->resolve(['stateMachine' => ['transitions' => 'nope']]));

	}//end testResolveMalformedTemplateReturnsNullFailSafe()

	/**
	 * quorumRequired defaults to true when absent (default-deny posture).
	 *
	 * @return void
	 */
	public function testResolveQuorumDefaultsToEnforced(): void {
		$policy = $this->resolver->resolve(['stateMachine' => ['transitions' => []]]);
		$this->assertNotNull($policy);
		$this->assertTrue($policy['quorumEnforced']);
		$this->assertFalse($policy['allowDecideWithoutVote']);

	}//end testResolveQuorumDefaultsToEnforced()

	/**
	 * The default voting rule is extracted, and a missing one yields null.
	 *
	 * @return void
	 */
	public function testResolveVotingRule(): void {
		$rule = $this->resolver->resolveVotingRule(
			[
				'votingRule' => [
					'voteThreshold' => 'qualified-majority-two-thirds',
					'abstentionHandling' => 'count',
					'tieBreakRule' => 'chair-decides',
				],
			]
		);

		$this->assertSame('qualified-majority-two-thirds', $rule['voteThreshold']);
		$this->assertSame('count', $rule['abstentionHandling']);
		$this->assertSame('chair-decides', $rule['tieBreakRule']);

		$this->assertNull($this->resolver->resolveVotingRule(null));
		$this->assertNull($this->resolver->resolveVotingRule(['name' => 'no-rule']));

	}//end testResolveVotingRule()
}//end class
