<?php

/**
 * Unit tests for DecisionContextResolver::resolveDomain().
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
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

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Lifecycle\DecisionTransitionGuard;
use OCA\Decidiq\Service\DecisionContextResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression tests for #1291: the governance domain is read off the
 * governance BODY (the only schema that declares `domain`) and falls back to
 * the restricted default-deny policy, never to the permissive `operations`.
 *
 * @spec openspec/specs/decision-management/spec.md
 */
class DecisionContextResolverTest extends TestCase {

	/**
	 * Resolver under test.
	 *
	 * @var DecisionContextResolver
	 */
	private DecisionContextResolver $resolver;

	/**
	 * Mock OpenRegister ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->resolver = new DecisionContextResolver(logger: $this->createMock(LoggerInterface::class));

	}//end setUp()

	/**
	 * Build an ObjectEntity mock that serializes to the given array.
	 *
	 * @param array<string, mixed> $data Object payload
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;

	}//end entity()

	/**
	 * Wire `find()` to serve governance bodies keyed by id; any other lookup
	 * (or an unknown id) returns null.
	 *
	 * @param array<string, array<string, mixed>> $bodies Body payloads keyed by id
	 *
	 * @return void
	 */
	private function wireBodies(array $bodies): void {
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) use ($bodies) {
				if ($schema !== 'governance-body' || isset($bodies[$id]) === false) {
					return null;
				}

				return $this->entity($bodies[$id]);
			}
		);

	}//end wireBodies()

	/**
	 * Each policy domain declared on the meeting's governance body is returned
	 * verbatim — before #1291 every one of these resolved to `operations`.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function bodyDomainProvider(): array {
		return [
			'legislative' => ['legislative'],
			'association' => ['association'],
			'corporate' => ['corporate'],
			'operations' => ['operations'],
			'citizen' => ['citizen'],
		];

	}//end bodyDomainProvider()

	/**
	 * The domain comes from the governance body linked through the meeting.
	 *
	 * @param string $domain The body's declared domain
	 *
	 * @dataProvider bodyDomainProvider
	 *
	 * @return void
	 */
	public function testDomainIsReadFromTheMeetingsGovernanceBody(string $domain): void {
		$this->wireBodies(['body-1' => ['id' => 'body-1', 'domain' => $domain]]);

		$resolved = $this->resolver->resolveDomain(
			objectService: $this->objectService,
			decision: ['id' => 'dec-1', 'meeting' => 'meet-1'],
			meeting: ['id' => 'meet-1', 'governanceBody' => 'body-1'],
		);

		self::assertSame(expected: $domain, actual: $resolved);

	}//end testDomainIsReadFromTheMeetingsGovernanceBody()

	/**
	 * A standalone decision carrying its own governanceBody resolves through
	 * it, and it wins over the meeting's body — the same precedence
	 * resolveGovernanceBodyId() uses for the process-template override, so the
	 * domain and the override always describe the same body.
	 *
	 * @return void
	 */
	public function testDecisionGovernanceBodyTakesPrecedenceLikeThePolicyOverride(): void {
		$this->wireBodies(
			[
				'body-dec' => ['id' => 'body-dec', 'domain' => 'corporate'],
				'body-meet' => ['id' => 'body-meet', 'domain' => 'legislative'],
			]
		);

		$decision = ['id' => 'dec-1', 'governanceBody' => 'body-dec'];
		$meeting = ['id' => 'meet-1', 'governanceBody' => 'body-meet'];

		self::assertSame(
			expected: 'corporate',
			actual: $this->resolver->resolveDomain(objectService: $this->objectService, decision: $decision, meeting: $meeting)
		);
		self::assertSame(
			expected: 'body-dec',
			actual: $this->resolver->resolveGovernanceBodyId(decision: $decision, meeting: $meeting)
		);
		self::assertSame(
			expected: 'corporate',
			actual: $this->resolver->resolveDomain(objectService: $this->objectService, decision: $decision, meeting: null)
		);

	}//end testDecisionGovernanceBodyTakesPrecedenceLikeThePolicyOverride()

	/**
	 * A relation-shaped body reference (`{id: ...}`) resolves the same way.
	 *
	 * @return void
	 */
	public function testRelationShapedBodyReferenceResolves(): void {
		$this->wireBodies(['body-1' => ['id' => 'body-1', 'domain' => 'legislative']]);

		$resolved = $this->resolver->resolveDomain(
			objectService: $this->objectService,
			decision: ['id' => 'dec-1'],
			meeting: ['id' => 'meet-1', 'governanceBody' => ['id' => 'body-1']],
		);

		self::assertSame(expected: 'legislative', actual: $resolved);

	}//end testRelationShapedBodyReferenceResolves()

	/**
	 * `domain` written onto the decision or meeting is ignored: neither schema
	 * declares it, and honouring it would let a writer downgrade the policy.
	 *
	 * @return void
	 */
	public function testUndeclaredDomainOnDecisionAndMeetingIsIgnored(): void {
		$this->wireBodies(['body-1' => ['id' => 'body-1', 'domain' => 'legislative']]);

		$withBody = $this->resolver->resolveDomain(
			objectService: $this->objectService,
			decision: ['id' => 'dec-1', 'domain' => 'operations'],
			meeting: ['id' => 'meet-1', 'domain' => 'operations', 'governanceBody' => 'body-1'],
		);
		self::assertSame(expected: 'legislative', actual: $withBody);

		$withoutBody = $this->resolver->resolveDomain(
			objectService: $this->objectService,
			decision: ['id' => 'dec-1', 'domain' => 'operations'],
			meeting: ['id' => 'meet-1', 'domain' => 'operations'],
		);
		self::assertSame(expected: DecisionContextResolver::UNRESOLVED_DOMAIN, actual: $withoutBody);

	}//end testUndeclaredDomainOnDecisionAndMeetingIsIgnored()

	/**
	 * No linked body at all: restricted, and no lookup is attempted.
	 *
	 * @return void
	 */
	public function testNoGovernanceBodyFallsBackToRestricted(): void {
		$this->objectService->expects($this->never())->method('find');

		self::assertSame(
			expected: DecisionContextResolver::UNRESOLVED_DOMAIN,
			actual: $this->resolver->resolveDomain(objectService: $this->objectService, decision: ['id' => 'dec-1'], meeting: null)
		);
		self::assertSame(
			expected: DecisionContextResolver::UNRESOLVED_DOMAIN,
			actual: $this->resolver->resolveDomain(
				objectService: $this->objectService,
				decision: ['id' => 'dec-1', 'governanceBody' => ''],
				meeting: ['id' => 'meet-1', 'governanceBody' => null]
			)
		);

	}//end testNoGovernanceBodyFallsBackToRestricted()

	/**
	 * A body the session user cannot read (find() returns null) falls back to
	 * restricted.
	 *
	 * @return void
	 */
	public function testUnreadableBodyFallsBackToRestricted(): void {
		$this->wireBodies([]);

		$resolved = $this->resolver->resolveDomain(
			objectService: $this->objectService,
			decision: ['id' => 'dec-1'],
			meeting: ['id' => 'meet-1', 'governanceBody' => 'body-gone'],
		);

		self::assertSame(expected: DecisionContextResolver::UNRESOLVED_DOMAIN, actual: $resolved);

	}//end testUnreadableBodyFallsBackToRestricted()

	/**
	 * A deleted body (find() throws DoesNotExistException) falls back to
	 * restricted.
	 *
	 * @return void
	 */
	public function testMissingBodyFallsBackToRestricted(): void {
		$this->objectService->method('find')->willThrowException(new DoesNotExistException('gone'));

		$resolved = $this->resolver->resolveDomain(
			objectService: $this->objectService,
			decision: ['id' => 'dec-1', 'governanceBody' => 'body-gone'],
			meeting: null,
		);

		self::assertSame(expected: DecisionContextResolver::UNRESOLVED_DOMAIN, actual: $resolved);

	}//end testMissingBodyFallsBackToRestricted()

	/**
	 * A body without a usable domain value falls back to restricted.
	 *
	 * @return void
	 */
	public function testBodyWithoutDomainFallsBackToRestricted(): void {
		$this->wireBodies(
			[
				'no-domain' => ['id' => 'no-domain'],
				'empty' => ['id' => 'empty', 'domain' => ''],
				'not-a-string' => ['id' => 'not-a-string', 'domain' => ['operations']],
			]
		);

		foreach (['no-domain', 'empty', 'not-a-string'] as $bodyId) {
			self::assertSame(
				expected: DecisionContextResolver::UNRESOLVED_DOMAIN,
				actual: $this->resolver->resolveDomain(
					objectService: $this->objectService,
					decision: ['id' => 'dec-1', 'governanceBody' => $bodyId],
					meeting: null
				),
				message: "Body '$bodyId' should resolve to the restricted fallback."
			);
		}

	}//end testBodyWithoutDomainFallsBackToRestricted()

	/**
	 * The fallback value, and body presets outside the policy table, map to
	 * the guard's restricted default-deny policy, never to `operations`.
	 *
	 * @return void
	 */
	public function testFallbackAndUnknownPresetsGetTheRestrictedPolicy(): void {
		$guard = new DecisionTransitionGuard();
		$operations = $guard->getDomainPolicy(domain: 'operations');
		$legislative = $guard->getDomainPolicy(domain: 'legislative');

		foreach ([DecisionContextResolver::UNRESOLVED_DOMAIN, 'municipal', 'water-board'] as $domain) {
			$policy = $guard->getDomainPolicy(domain: $domain);
			self::assertNotSame(expected: $operations, actual: $policy, message: "'$domain' must not get the operations policy.");
			self::assertTrue(condition: $policy['quorumEnforced']);
			self::assertFalse(condition: $policy['allowDecideWithoutVote']);
			self::assertSame(expected: $legislative['chairOnlyTransitions'], actual: $policy['chairOnlyTransitions']);
		}

	}//end testFallbackAndUnknownPresetsGetTheRestrictedPolicy()
}//end class
