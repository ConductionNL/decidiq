<?php

/**
 * Unit tests for ApprovalStageActivator.
 *
 * Covers the activation patch for named and rule-based stages, the account
 * check, an unreadable organisation record or absence preference, and the
 * manager / substitute lookups escalation relies on.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-012, REQ-AR-013, REQ-AR-015, REQ-AR-016)
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Decidiq\Service\ApprovalActorResolver;
use OCA\Decidiq\Service\ApprovalStageActivator;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ApprovalStageActivator.
 */
final class ApprovalStageActivatorTest extends TestCase {

	/**
	 * The organisation record the store answers with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const PEOPLE = [
		['id' => 'j.jansen', 'manager' => 'd.devries', 'substitute' => 'p.peters'],
		['id' => 'd.devries', 'manager' => 'b.bakker'],
		['id' => 'k.kok', 'substitute' => ['a.aarts', 'w.willems']],
	];

	/**
	 * Mock register store.
	 *
	 * @var RegisterObjectStore&MockObject
	 */
	private RegisterObjectStore&MockObject $store;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = $this->createMock(RegisterObjectStore::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The activator under test.
	 *
	 * @param IUserManager|null $userManager The account check, or none.
	 * @param NotificationPreferenceService|null $preferences The absence source, or none.
	 *
	 * @return ApprovalStageActivator
	 */
	private function activator(?IUserManager $userManager = null, ?NotificationPreferenceService $preferences = null): ApprovalStageActivator {
		return new ApprovalStageActivator(
			store: $this->store,
			resolver: new ApprovalActorResolver(),
			userManager: $userManager,
			logger: $this->logger,
			preferences: $preferences,
		);

	}//end activator()

	/**
	 * A stage naming a person, with no preference service, gets exactly the status patch.
	 *
	 * @return void
	 */
	public function testANamedStageWithoutPreferencesGetsOnlyTheStatusPatch(): void {
		$this->store->expects(self::never())->method('findAll');
		$now = new DateTimeImmutable('2026-03-02T10:00:00+01:00');

		$patch = $this->activator()->activationPatch(stage: ['assignedPerson' => 'j.jansen'], now: $now);

		self::assertSame(['status' => 'active', 'activatedAt' => '2026-03-02T10:00:00+01:00'], $patch);

	}//end testANamedStageWithoutPreferencesGetsOnlyTheStatusPatch()

	/**
	 * A rule is resolved against the organisation record at activation, with
	 * the account check consulted and the provenance stamped with the clock.
	 *
	 * @return void
	 */
	public function testARuleIsResolvedAtActivation(): void {
		$this->store->expects(self::once())->method('findAll')
			->with('person', [])->willReturn(self::PEOPLE);
		$users = $this->createMock(IUserManager::class);
		$users->expects(self::once())->method('userExists')->with('d.devries')->willReturn(true);

		$patch = $this->activator(userManager: $users)->activationPatch(
			stage: ['actorRule' => ' manager-of-subject-owner ', 'assignedPerson' => 'ignored'],
			subjectOwner: 'j.jansen',
			now: new DateTimeImmutable('2026-09-01T08:00:00+00:00'),
		);

		self::assertSame(
			[
				'status' => 'active',
				'activatedAt' => '2026-09-01T08:00:00+00:00',
				'assignedPerson' => 'd.devries',
				'actorResolvedBy' => 'manager-of-subject-owner',
				'actorResolvedAt' => '2026-09-01T08:00:00+00:00',
				'decisionMakerType' => 'person',
			],
			$patch
		);

	}//end testARuleIsResolvedAtActivation()

	/**
	 * A rule resolving to somebody without an account refuses the activation.
	 *
	 * @return void
	 */
	public function testARuleResolvingToSomebodyWithoutAnAccountRefuses(): void {
		$this->store->method('findAll')->willReturn(self::PEOPLE);
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn(false);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('resolved to d.devries, who has no account');
		$this->activator(userManager: $users)->activationPatch(
			stage: ['actorRule' => 'manager-of-actor', 'actorRuleSubject' => 'j.jansen'],
		);

	}//end testARuleResolvingToSomebodyWithoutAnAccountRefuses()

	/**
	 * An unreadable organisation record is logged and becomes "no people",
	 * which the resolver refuses rather than leaving the stage open to anyone.
	 *
	 * @return void
	 */
	public function testAnUnreadableOrganisationRecordRefusesTheRule(): void {
		$this->store->method('findAll')->willThrowException(new RuntimeException('schema missing'));
		$this->logger->expects(self::once())->method('warning')->with(
			'Decidiq: could not read the organisation record for an actor rule',
			['reason' => 'schema missing']
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no readable organisation record implements the person kind');
		$this->activator()->activationPatch(stage: ['actorRule' => 'manager-of-actor', 'actorRuleSubject' => 'j.jansen']);

	}//end testAnUnreadableOrganisationRecordRefusesTheRule()

	/**
	 * An unreadable absence preference is logged and names no substitute.
	 *
	 * @return void
	 */
	public function testAnUnreadablePreferenceNamesNoSubstitute(): void {
		$now = new DateTimeImmutable('2026-03-02T10:00:00+01:00');
		$preferences = $this->createMock(NotificationPreferenceService::class);
		$preferences->expects(self::once())->method('getActiveDelegate')
			->with('j.jansen', $now)
			->willThrowException(new RuntimeException('preference store down'));
		$this->logger->expects(self::once())->method('warning')->with(
			'Decidiq: could not read the absence of an approval stage actor',
			['actor' => 'j.jansen', 'reason' => 'preference store down']
		);

		$patch = $this->activator(preferences: $preferences)->activationPatch(stage: ['assignedPerson' => 'j.jansen'], now: $now);

		self::assertSame(['status' => 'active', 'activatedAt' => '2026-03-02T10:00:00+01:00'], $patch);

	}//end testAnUnreadablePreferenceNamesNoSubstitute()

	/**
	 * substituteOf() answers from the organisation record, and null for a blank
	 * person or an ambiguous record.
	 *
	 * @return void
	 */
	public function testSubstituteOf(): void {
		$this->store->expects(self::exactly(2))->method('findAll')->with('person', [])->willReturn(self::PEOPLE);
		$activator = $this->activator();

		self::assertNull($activator->substituteOf(person: '  '));
		self::assertSame('p.peters', $activator->substituteOf(person: 'j.jansen'));
		self::assertNull($activator->substituteOf(person: 'k.kok'), 'Two substitutes is no basis to pick one.');

	}//end testSubstituteOf()

	/**
	 * managerOf() resolves the manager through the account check.
	 *
	 * @return void
	 */
	public function testManagerOfResolvesTheManager(): void {
		$this->store->method('findAll')->willReturn(self::PEOPLE);
		$users = $this->createMock(IUserManager::class);
		$users->expects(self::once())->method('userExists')->with('b.bakker')->willReturn(true);

		self::assertSame('b.bakker', $this->activator(userManager: $users)->managerOf(person: 'd.devries'));

	}//end testManagerOfResolvesTheManager()

	/**
	 * managerOf() holds (null) instead of throwing when nobody resolves, and
	 * answers null for a blank person without reading the record.
	 *
	 * @return void
	 */
	public function testManagerOfHoldsWhenNoManagerResolves(): void {
		$this->store->expects(self::once())->method('findAll')->willReturn(self::PEOPLE);
		$this->logger->expects(self::once())->method('info')->with(
			'Decidiq: no manager resolved for an escalating approval stage',
			self::callback(
				static fn (array $context): bool => $context['person'] === 'b.bakker'
					&& str_contains($context['reason'], 'resolved to nobody for b.bakker')
			)
		);
		$activator = $this->activator();

		self::assertNull($activator->managerOf(person: ''));
		self::assertNull($activator->managerOf(person: 'b.bakker'));

	}//end testManagerOfHoldsWhenNoManagerResolves()
}//end class
