<?php

/**
 * Unit tests for ApprovalActorResolver.
 *
 * Every test here is a refusal, because every refusal is the feature: a rule
 * that resolves to the wrong person produces a signature nobody gave, and a
 * signature nobody gave is indistinguishable from a real one once it is stored.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-012, REQ-AR-013)
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ApprovalActorResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers the rule grammar and every way resolution fails closed.
 */
final class ApprovalActorResolverTest extends TestCase {
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
	 * An organisation record.
	 *
	 * @return array<int, array<string, mixed>> The people.
	 */
	private function people(): array {
		return [
			['id' => 'j.jansen', 'manager' => 'd.devries', 'substitute' => 'p.peters'],
			['id' => 'd.devries', 'manager' => 'b.bakker'],
			['id' => 'k.kok', 'manager' => ['a.aarts', 'w.willems']],
			['id' => 'm.mulder'],
		];
	}//end people()

	/**
	 * A step naming both a person and a rule has two answers to one question.
	 *
	 * @return void
	 */
	public function testAStepNamingBothAnActorAndARuleIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('it can carry one or the other');

		$this->resolver->assertStepIsResolvable(
			['actor' => 'j.jansen', 'actorRule' => ApprovalActorResolver::RULE_MANAGER_OF_ACTOR]
		);
	}//end testAStepNamingBothAnActorAndARuleIsRefused()

	/**
	 * A rule that reads a person has to say which person.
	 *
	 * @return void
	 */
	public function testARuleWithoutItsSubjectIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('has to say whose manager or substitute it means');

		$this->resolver->assertStepIsResolvable(
			['actorRule' => ApprovalActorResolver::RULE_MANAGER_OF_ACTOR]
		);
	}//end testARuleWithoutItsSubjectIsRefused()

	/**
	 * The manager of the named person, with the provenance the stage records.
	 *
	 * @return void
	 */
	public function testTheManagerOfTheNamedPersonIsResolved(): void {
		$resolved = $this->resolver->resolve(
			step: [
				'actorRule' => ApprovalActorResolver::RULE_MANAGER_OF_ACTOR,
				'actorRuleSubject' => 'j.jansen',
			],
			people: $this->people(),
			resolvedAt: '2026-09-18T09:00:00+00:00',
		);

		$this->assertSame('d.devries', $resolved['actor']);
		$this->assertSame(ApprovalActorResolver::RULE_MANAGER_OF_ACTOR, $resolved['actorResolvedBy']);
		$this->assertSame('2026-09-18T09:00:00+00:00', $resolved['actorResolvedAt']);
	}//end testTheManagerOfTheNamedPersonIsResolved()

	/**
	 * The owner's manager, for the rule that reads the subject rather than the
	 * step.
	 *
	 * @return void
	 */
	public function testTheSubjectOwnersManagerIsResolved(): void {
		$resolved = $this->resolver->resolve(
			step: ['actorRule' => ApprovalActorResolver::RULE_MANAGER_OF_SUBJECT_OWNER],
			people: $this->people(),
			subjectOwner: 'd.devries',
		);

		$this->assertSame('b.bakker', $resolved['actor']);
	}//end testTheSubjectOwnersManagerIsResolved()

	/**
	 * A later reorganisation reaches the steps not yet asked, and only those.
	 *
	 * The stage already resolved keeps what it recorded; this asserts the other
	 * half, that a second read against a changed record answers the new manager.
	 *
	 * @return void
	 */
	public function testAChangedRecordAnswersTheNewManager(): void {
		$before = $this->resolver->resolve(
			step: ['actorRule' => ApprovalActorResolver::RULE_MANAGER_OF_SUBJECT_OWNER],
			people: $this->people(),
			subjectOwner: 'j.jansen',
		);
		$this->assertSame('d.devries', $before['actor']);

		$after = $this->resolver->resolve(
			step: ['actorRule' => ApprovalActorResolver::RULE_MANAGER_OF_SUBJECT_OWNER],
			people: [['id' => 'j.jansen', 'manager' => 'n.nieuwenhuis']],
			subjectOwner: 'j.jansen',
		);

		$this->assertSame('n.nieuwenhuis', $after['actor']);
	}//end testAChangedRecordAnswersTheNewManager()

	/**
	 * No organisation record at all refuses, by name.
	 *
	 * @return void
	 */
	public function testNoPersonRecordRefusesAndNamesTheRule(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no readable organisation record implements the person kind');

		$this->resolver->resolve(
			step: ['actorRule' => ApprovalActorResolver::RULE_MANAGER_OF_SUBJECT_OWNER],
			people: [],
			subjectOwner: 'j.jansen',
		);
	}//end testNoPersonRecordRefusesAndNamesTheRule()

	/**
	 * Two candidates is a refusal that names both, never a pick.
	 *
	 * @return void
	 */
	public function testTwoCandidatesRefuseAndNameBoth(): void {
		try {
			$this->resolver->resolve(
				step: [
					'actorRule' => ApprovalActorResolver::RULE_MANAGER_OF_ACTOR,
					'actorRuleSubject' => 'k.kok',
				],
				people: $this->people(),
			);
			$this->fail('Two candidate managers should have been refused.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('a.aarts', $e->getMessage());
			$this->assertStringContainsString('w.willems', $e->getMessage());
		}
	}//end testTwoCandidatesRefuseAndNameBoth()

	/**
	 * A person the record names but the instance does not know cannot sign.
	 *
	 * @return void
	 */
	public function testAResolvedPersonWithNoAccountIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('has no account on this instance');

		$this->resolver->resolve(
			step: [
				'actorRule' => ApprovalActorResolver::RULE_MANAGER_OF_ACTOR,
				'actorRuleSubject' => 'j.jansen',
			],
			people: $this->people(),
			hasAccount: static fn (string $uid): bool => false,
		);
	}//end testAResolvedPersonWithNoAccountIsRefused()

	/**
	 * Nobody at all refuses rather than leaving the step open to anyone.
	 *
	 * @return void
	 */
	public function testNoManagerOnRecordRefuses(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('resolved to nobody');

		$this->resolver->resolve(
			step: [
				'actorRule' => ApprovalActorResolver::RULE_MANAGER_OF_ACTOR,
				'actorRuleSubject' => 'm.mulder',
			],
			people: $this->people(),
		);
	}//end testNoManagerOnRecordRefuses()

	/**
	 * A substitute is a best effort, so it answers null instead of throwing.
	 *
	 * @return void
	 */
	public function testSubstituteAnswersNullRatherThanRefusing(): void {
		$this->assertSame('p.peters', $this->resolver->substituteOf(people: $this->people(), person: 'j.jansen'));
		$this->assertNull($this->resolver->substituteOf(people: $this->people(), person: 'm.mulder'));
	}//end testSubstituteAnswersNullRatherThanRefusing()

	/**
	 * The resolver reaches into no sibling app.
	 *
	 * A structural assertion, not a style one: a cross-app service lookup here
	 * would make decidiq unable to boot without that app, for a query.
	 *
	 * @return void
	 */
	public function testTheResolverReachesIntoNoSiblingApp(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../lib/Service/ApprovalActorResolver.php');

		$this->assertStringNotContainsString('ContainerInterface', $source);
		$this->assertStringNotContainsString('IClientService', $source);
		$this->assertDoesNotMatchRegularExpression('/OCA\\\\(?!Decidiq)[A-Za-z]+\\\\/', $source);
	}//end testTheResolverReachesIntoNoSiblingApp()
}//end class
