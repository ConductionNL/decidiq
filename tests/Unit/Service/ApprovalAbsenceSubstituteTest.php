<?php

/**
 * Unit tests for handing sign-offs to a substitute during an absence.
 *
 * A user who set a delegate and an absence period in their Decidiq settings
 * has every approval step that becomes live for them in that period put to
 * the delegate as well, and the server lets that substitute sign it.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/specs/decision-route/spec.md#requirement-req-ras-001-a-substitute-approves-while-someone-is-away
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Decidiq\Service\ApprovalActorResolver;
use OCA\Decidiq\Service\ApprovalStageActivator;
use OCA\Decidiq\Service\ApprovalStageGuard;
use OCA\Decidiq\Service\MandateDirectory;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\Decidiq\Service\RegisterObjectStore;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Covers the activation patch, the guard and the wiring.
 */
final class ApprovalAbsenceSubstituteTest extends TestCase {

	/**
	 * The activator under test, with a preference service that answers the
	 * given delegate for the given person.
	 *
	 * @param array<string, string> $delegates Person id to active delegate.
	 *
	 * @return ApprovalStageActivator The activator.
	 */
	private function activator(array $delegates): ApprovalStageActivator {
		$store = $this->createMock(RegisterObjectStore::class);
		$store->method('findAll')->willReturn(
			[
				['id' => 'p1', 'userId' => 'a.jansen', 'manager' => 'm.devries'],
				['id' => 'p2', 'userId' => 'm.devries'],
			]
		);

		$preferences = $this->createMock(NotificationPreferenceService::class);
		$preferences->method('getActiveDelegate')->willReturnCallback(
			static function (string $personId, ?DateTimeImmutable $today = null) use ($delegates): ?string {
				return ($delegates[$personId] ?? null);
			}
		);

		return new ApprovalStageActivator(
			store: $store,
			resolver: new ApprovalActorResolver(),
			preferences: $preferences,
		);
	}//end activator()

	/**
	 * A step for somebody who is away names their delegate as substitute.
	 *
	 * @return void
	 */
	public function testAStepForAnAbsentPersonNamesTheirDelegate(): void {
		$now = new DateTimeImmutable('2026-08-05T09:00:00+02:00');
		$patch = $this->activator(['a.jansen' => 'p.devries'])->activationPatch(
			stage: ['id' => 's1', 'assignedPerson' => 'a.jansen'],
			now: $now,
		);

		self::assertSame('active', $patch['status']);
		self::assertSame('p.devries', ($patch['substituteActor'] ?? null));
		self::assertSame($now->format(DateTimeImmutable::ATOM), ($patch['substituteAskedAt'] ?? null));
		self::assertArrayNotHasKey('assignedPerson', $patch, 'The step stays assigned to the absent person, who may still sign.');

	}//end testAStepForAnAbsentPersonNamesTheirDelegate()

	/**
	 * Nobody away, nobody asked.
	 *
	 * @return void
	 */
	public function testAStepForSomebodyPresentNamesNoSubstitute(): void {
		$patch = $this->activator([])->activationPatch(
			stage: ['id' => 's1', 'assignedPerson' => 'a.jansen'],
			now: new DateTimeImmutable('2026-08-05T09:00:00+02:00'),
		);

		self::assertArrayNotHasKey('substituteActor', $patch);

	}//end testAStepForSomebodyPresentNamesNoSubstitute()

	/**
	 * A rule resolves first, and the absence is read for the person it
	 * resolved to.
	 *
	 * @return void
	 */
	public function testARuleResolvedToAnAbsentManagerNamesTheManagersDelegate(): void {
		$patch = $this->activator(['m.devries' => 'k.bakker'])->activationPatch(
			stage: ['id' => 's1', 'actorRule' => 'manager-of-actor', 'actorRuleSubject' => 'a.jansen'],
			now: new DateTimeImmutable('2026-08-05T09:00:00+02:00'),
		);

		self::assertSame('m.devries', $patch['assignedPerson']);
		self::assertSame('k.bakker', ($patch['substituteActor'] ?? null));

	}//end testARuleResolvedToAnAbsentManagerNamesTheManagersDelegate()

	/**
	 * A delegate who is the person themself is no substitute.
	 *
	 * @return void
	 */
	public function testADelegateNamingThemselfIsIgnored(): void {
		$patch = $this->activator(['a.jansen' => 'a.jansen'])->activationPatch(
			stage: ['id' => 's1', 'assignedPerson' => 'a.jansen'],
			now: new DateTimeImmutable('2026-08-05T09:00:00+02:00'),
		);

		self::assertArrayNotHasKey('substituteActor', $patch);

	}//end testADelegateNamingThemselfIsIgnored()

	/**
	 * The substitute the stage names may act; somebody else may not.
	 *
	 * @spec openspec/specs/decision-route/spec.md#requirement-req-ras-002-the-server-lets-a-steps-substitute-act
	 *
	 * @return void
	 */
	public function testTheStagesSubstituteMayActAndNobodyElse(): void {
		$store = $this->createMock(RegisterObjectStore::class);
		$guard = new ApprovalStageGuard(new MandateDirectory($store));
		$stage = ['id' => 's1', 'assignedPerson' => 'a.jansen', 'substituteActor' => 'p.devries'];

		self::assertSame('s1', $guard->stageForAction([$stage], ['actor' => 'p.devries'])['id']);
		self::assertSame(
			's1',
			$guard->stageForAction([$stage, ['id' => 's2', 'assignedPerson' => 'j.smit']], ['actor' => 'p.devries'])['id'],
			'In a parallel group the substitute lands on the stage that names them.'
		);

		$this->expectException(RuntimeException::class);
		$guard->stageForAction([$stage], ['actor' => 'j.smit']);

	}//end testTheStagesSubstituteMayActAndNobodyElse()

	/**
	 * The activator asks for the preference service by type, so Nextcloud's
	 * autowiring hands it over in production.
	 *
	 * @return void
	 */
	public function testTheActivatorTakesThePreferenceServiceByType(): void {
		$types = [];
		foreach ((new ReflectionClass(ApprovalStageActivator::class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			if ($type instanceof ReflectionNamedType) {
				$types[$parameter->getName()] = $type->getName();
			}
		}

		self::assertSame(NotificationPreferenceService::class, ($types['preferences'] ?? null));

	}//end testTheActivatorTakesThePreferenceServiceByType()

	/**
	 * The fields the patch writes are declared on the merged DecisionStage
	 * schema, and the patch validates against them.
	 *
	 * @return void
	 */
	public function testThePatchValidatesAgainstTheMergedStageSchema(): void {
		$settings = __DIR__ . '/../../../lib/Settings/';
		$stage = [];
		$files = array_merge([$settings . 'decidesk_register.json'], (glob($settings . 'register.d/*.json') ?: []));
		foreach ($files as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			$fragment = ($doc['components']['schemas']['DecisionStage'] ?? null);
			if (is_array($fragment) === true) {
				$stage = array_replace_recursive($stage, $fragment);
			}
		}

		$patch = $this->activator(['a.jansen' => 'p.devries'])->activationPatch(
			stage: ['id' => 's1', 'assignedPerson' => 'a.jansen'],
			now: new DateTimeImmutable('2026-08-05T09:00:00+02:00'),
		);

		$properties = [];
		foreach (array_keys($patch) as $field) {
			self::assertArrayHasKey($field, $stage['properties'], $field . ' must be declared on DecisionStage');
			$properties[$field] = $stage['properties'][$field];
		}

		$schema = json_decode((string)json_encode(['type' => 'object', 'properties' => $properties]));
		$validator = new Validator();
		$validator->parser()->setOption('allowFormats', true);
		self::assertTrue($validator->validate(json_decode((string)json_encode($patch)), $schema)->isValid());
		self::assertFalse(
			$validator->validate(json_decode('{"substituteAskedAt":"next tuesday"}'), $schema)->isValid(),
			'The validator must reject a non date-time, or it proves nothing'
		);

	}//end testThePatchValidatesAgainstTheMergedStageSchema()

}//end class
