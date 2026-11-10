<?php

/**
 * Golden tests for ApprovalActorResolver.
 *
 * Pins the refusals and answers ApprovalActorResolverTest leaves out: an
 * unknown rule, a step naming a person, a subject without an owner, a list of
 * references, the account check that lets a person through, the default
 * instant and the substitute lookup over more than one candidate.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-012, REQ-AR-013)
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ActorResolution;
use OCA\Decidiq\Service\ApprovalActorResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins every remaining branch of the actor rule resolution.
 */
final class ApprovalActorResolverGoldenTest extends TestCase {
	/**
	 * The resolver under test.
	 *
	 * @var ApprovalActorResolver
	 */
	private ApprovalActorResolver $resolver;

	/**
	 * Build the resolver.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new ApprovalActorResolver();
	}//end setUp()

	/**
	 * An organisation record with every shape a reference can take.
	 *
	 * @return array<int, mixed> The people.
	 */
	private function people(): array {
		return [
			'not a record',
			['uuid' => 'u-1', 'manager' => ['', 'a.aarts', 7, 'a.aarts']],
			['userId' => 'u-1', 'manager' => 'a.aarts'],
			['accountName' => 'acc', 'manager' => '', 'substitute' => ['s.one', 's.two']],
			['id' => 'n.num', 'manager' => 42],
			['id' => 'solo', 'substitute' => ['s.only']],
		];
	}//end people()

	/**
	 * The message a resolution throws.
	 *
	 * @param callable(): mixed $call The resolution.
	 *
	 * @return string The message, or 'none' when nothing was thrown.
	 */
	private function refusal(callable $call): string {
		try {
			$call();
		} catch (RuntimeException $e) {
			return $e->getMessage();
		}

		return 'none';
	}//end refusal()

	/**
	 * An unknown rule is refused with the rules it could have been.
	 *
	 * @return void
	 */
	public function testAnUnknownRuleIsRefused(): void {
		$this->assertSame(
			'Unknown actor rule "boss"; expected one of manager-of-actor, manager-of-subject-owner, substitute-of-actor.',
			$this->refusal(fn () => $this->resolver->assertStepIsResolvable(step: ['actorRule' => ' boss ']))
		);
	}//end testAnUnknownRuleIsRefused()

	/**
	 * Steps that pass the grammar: a person, nothing at all, and the owner rule without a subject.
	 *
	 * @return void
	 */
	public function testResolvableStepsPass(): void {
		foreach ([['actor' => 'x'], [], ['actorRule' => 'manager-of-subject-owner'], ['actorRule' => 'substitute-of-actor', 'actorRuleSubject' => 's']] as $step) {
			$this->assertSame('none', $this->refusal(fn () => $this->resolver->assertStepIsResolvable(step: $step)));
		}
	}//end testResolvableStepsPass()

	/**
	 * Both, then the subject check, in that order.
	 *
	 * @return void
	 */
	public function testBothIsCheckedBeforeTheRuleName(): void {
		$this->assertSame(
			'This step names both an actor (x) and a rule (boss); it can carry one or the other.',
			$this->refusal(fn () => $this->resolver->assertStepIsResolvable(step: ['actor' => ' x ', 'actorRule' => 'boss']))
		);
		$this->assertSame(
			'The rule "substitute-of-actor" has to say whose manager or substitute it means.',
			$this->refusal(fn () => $this->resolver->assertStepIsResolvable(step: ['actorRule' => 'substitute-of-actor', 'actorRuleSubject' => '  ']))
		);
	}//end testBothIsCheckedBeforeTheRuleName()

	/**
	 * A step naming a person has nothing to resolve, even with no people.
	 *
	 * @return void
	 */
	public function testAStepNamingAPersonIsRefused(): void {
		$this->assertSame(
			'This step names a person, not a rule; there is nothing to resolve.',
			$this->refusal(fn () => $this->resolver->resolve(resolution: new ActorResolution(step: ['actor' => 'x'], people: [])))
		);
	}//end testAStepNamingAPersonIsRefused()

	/**
	 * The grammar is checked before the person check.
	 *
	 * @return void
	 */
	public function testTheGrammarIsCheckedFirst(): void {
		$this->assertSame(
			'Unknown actor rule "boss"; expected one of manager-of-actor, manager-of-subject-owner, substitute-of-actor.',
			$this->refusal(fn () => $this->resolver->resolve(resolution: new ActorResolution(step: ['actorRule' => 'boss'], people: [])))
		);
	}//end testTheGrammarIsCheckedFirst()

	/**
	 * The owner rule without an owner has nobody to read against.
	 *
	 * @return void
	 */
	public function testTheOwnerRuleWithoutAnOwnerIsRefused(): void {
		$this->assertSame(
			'The rule "manager-of-subject-owner" has nobody to read against; the subject has no owner on record.',
			$this->refusal(
				fn () => $this->resolver->resolve(resolution: new ActorResolution(
					step: ['actorRule' => 'manager-of-subject-owner', 'actorRuleSubject' => 'ignored'],
					people: $this->people(),
					subjectOwner: '  ',
				))
			)
		);
	}//end testTheOwnerRuleWithoutAnOwnerIsRefused()

	/**
	 * Records matched by uuid and userId merge their references without duplicates or junk.
	 *
	 * @return void
	 */
	public function testReferencesAreMergedAcrossRecords(): void {
		$seen = [];
		$resolved = $this->resolver->resolve(resolution: new ActorResolution(
			step: ['actorRule' => 'manager-of-subject-owner'],
			people: $this->people(),
			subjectOwner: ' u-1 ',
			hasAccount: function (string $person) use (&$seen): bool {
				$seen[] = $person;
				return true;
			},
			resolvedAt: '2026-01-02T03:04:05+00:00',
		));

		$this->assertSame(
			['actor' => 'a.aarts', 'actorResolvedBy' => 'manager-of-subject-owner', 'actorResolvedAt' => '2026-01-02T03:04:05+00:00'],
			$resolved
		);
		$this->assertSame(['a.aarts'], $seen);
	}//end testReferencesAreMergedAcrossRecords()

	/**
	 * An empty or non-string reference resolves to nobody.
	 *
	 * @return void
	 */
	public function testEmptyAndNonStringReferencesResolveToNobody(): void {
		$this->assertSame(
			'The rule "manager-of-actor" resolved to nobody for acc; the organisation record names no manager.',
			$this->refusal(fn () => $this->resolver->resolve(resolution: new ActorResolution(step: ['actorRule' => 'manager-of-actor', 'actorRuleSubject' => 'acc'], people: $this->people())))
		);
		$this->assertSame(
			'The rule "manager-of-actor" resolved to nobody for n.num; the organisation record names no manager.',
			$this->refusal(fn () => $this->resolver->resolve(resolution: new ActorResolution(step: ['actorRule' => 'manager-of-actor', 'actorRuleSubject' => 'n.num'], people: $this->people())))
		);
	}//end testEmptyAndNonStringReferencesResolveToNobody()

	/**
	 * The substitute rule reads the substitute property and names both candidates.
	 *
	 * @return void
	 */
	public function testTheSubstituteRuleReadsTheSubstitute(): void {
		$this->assertSame(
			'The rule "substitute-of-actor" resolved to more than one person for acc: s.one and s.two. The organisation record has to say which.',
			$this->refusal(fn () => $this->resolver->resolve(resolution: new ActorResolution(step: ['actorRule' => 'substitute-of-actor', 'actorRuleSubject' => 'acc'], people: $this->people())))
		);
		$resolved = $this->resolver->resolve(resolution: new ActorResolution(step: ['actorRule' => 'substitute-of-actor', 'actorRuleSubject' => 'solo'], people: $this->people()));
		$this->assertSame('s.only', $resolved['actor']);
	}//end testTheSubstituteRuleReadsTheSubstitute()

	/**
	 * Without an instant the resolution is stamped now, as ISO-8601.
	 *
	 * @return void
	 */
	public function testTheDefaultInstantIsNow(): void {
		$before = time();
		$resolved = $this->resolver->resolve(resolution: new ActorResolution(step: ['actorRule' => 'substitute-of-actor', 'actorRuleSubject' => 'solo'], people: $this->people()));
		$stamp = \DateTimeImmutable::createFromFormat(\DateTimeImmutable::ATOM, $resolved['actorResolvedAt']);

		$this->assertNotFalse($stamp);
		$this->assertGreaterThanOrEqual($before, $stamp->getTimestamp());
		$this->assertLessThanOrEqual(time(), $stamp->getTimestamp());
	}//end testTheDefaultInstantIsNow()

	/**
	 * The substitute lookup answers null for two candidates and for none.
	 *
	 * @return void
	 */
	public function testTheSubstituteLookupNeedsExactlyOne(): void {
		$this->assertNull($this->resolver->substituteOf(people: $this->people(), person: 'acc'));
		$this->assertNull($this->resolver->substituteOf(people: $this->people(), person: 'nobody'));
		$this->assertSame('s.only', $this->resolver->substituteOf(people: $this->people(), person: 'solo'));
	}//end testTheSubstituteLookupNeedsExactlyOne()
}//end class
