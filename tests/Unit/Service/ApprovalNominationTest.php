<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ApprovalNomination;
use OCA\Decidiq\Service\ApprovalRouteFlowCapabilities;
use OCA\Decidiq\Service\ApprovalRouteRefusals;
use OCA\Decidiq\Service\ApprovalRouteStepMapper;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A requester names the approver of a step, inside the step's group (REQ-DWP-009).
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-009)
 */
class ApprovalNominationTest extends TestCase {
	/**
	 * The nomination service over a group manager where only `dirk` is in `tekenbevoegden`.
	 *
	 * @return ApprovalNomination The service.
	 */
	private function nomination(): ApprovalNomination {
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $user, string $group): bool => ($user === 'dirk' && $group === 'tekenbevoegden')
		);

		return new ApprovalNomination(groups: $groups);
	}

	/**
	 * A route whose second step takes its approver from `tekenbevoegden`.
	 *
	 * @return array<string, mixed> The route.
	 */
	private function route(): array {
		return [
			'name' => 'Ondertekening',
			'steps' => [
				['order' => 1, 'stageType' => 'endorsement', 'actorType' => 'person', 'actor' => 'alice', 'label' => 'Parafering'],
				[
					'order' => 2,
					'stageType' => 'decisive',
					'actorType' => 'role',
					'actor' => 'secretary',
					'actorRule' => 'manager-of-owner',
					'label' => 'Tekenen',
					'nominationGroup' => 'tekenbevoegden',
				],
			],
		];
	}

	/**
	 * A member of the group, nominated, becomes the step's person.
	 *
	 * @return void
	 */
	public function testANominatedMemberOfTheGroupIsAssignedTheStep(): void {
		$route = $this->nomination()->applyTo(route: $this->route(), nominations: ['2' => 'dirk'], requester: 'bea');

		$step = $route['steps'][1];
		$this->assertSame(expected: 'person', actual: $step['actorType']);
		$this->assertSame(expected: 'dirk', actual: $step['actor']);
		$this->assertSame(expected: 'bea', actual: $step['nominatedBy']);
		$this->assertArrayNotHasKey(key: 'actorRule', array: $step, message: 'A rule left on the step would replace the nominee when the stage goes live.');

		$mapper = new ApprovalRouteStepMapper();
		$this->assertSame(expected: 'dirk', actual: $mapper->assignedPerson(step: $step));
		$fields = $mapper->declaredStepFields(step: $step);
		$this->assertSame(expected: 'tekenbevoegden', actual: $fields['nominationGroup']);
		$this->assertSame(expected: 'bea', actual: $fields['nominatedBy']);
	}

	/**
	 * A nominee outside the group is refused, and the refusal names the group.
	 *
	 * @return void
	 */
	public function testANomineeOutsideTheGroupIsRefusedNamingTheBound(): void {
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'tekenbevoegden');

		$this->nomination()->applyTo(route: $this->route(), nominations: [2 => 'mallory'], requester: 'bea');
	}

	/**
	 * A step that takes a nomination is refused when nobody is named.
	 *
	 * @return void
	 */
	public function testANominationStepWithNobodyNamedIsRefused(): void {
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'nobody was named');

		$this->nomination()->applyTo(route: $this->route(), nominations: [], requester: 'bea');
	}

	/**
	 * A step without a bound ignores a nomination sent to it.
	 *
	 * @return void
	 */
	public function testAStepWithoutABoundIgnoresANomination(): void {
		$route = $this->nomination()->applyTo(
			route: $this->route(),
			nominations: [1 => 'mallory', 2 => 'dirk'],
			requester: 'bea',
		);

		$this->assertSame(expected: 'alice', actual: $route['steps'][0]['actor']);
		$this->assertArrayNotHasKey(key: 'nominatedBy', array: $route['steps'][0]);
	}

	/**
	 * A route with no nomination step comes back as it was sent.
	 *
	 * @return void
	 */
	public function testARouteWithoutNominationStepsIsUnchanged(): void {
		$route = $this->route();
		unset($route['steps'][1]['nominationGroup']);

		$this->assertSame(
			expected: $route,
			actual: $this->nomination()->applyTo(route: $route, nominations: [2 => 'dirk'], requester: 'bea'),
		);
	}

	/**
	 * Steps stored as JSON text are read too.
	 *
	 * @return void
	 */
	public function testStepsStoredAsJsonAreRead(): void {
		$route = $this->route();
		$route['steps'] = json_encode($route['steps']);

		$applied = $this->nomination()->applyTo(route: $route, nominations: [2 => 'dirk'], requester: 'bea');

		$this->assertSame(expected: 'dirk', actual: $applied['steps'][1]['actor']);
	}

	/**
	 * A route compiled to a flow refuses a nomination step while OpenRegister offers none.
	 *
	 * Without the refusal the flow would assign the step's own actor and drop
	 * the person the requester named.
	 *
	 * @return void
	 */
	public function testAFlowRefusesANominationStepUntilOpenRegisterOffersIt(): void {
		$step = $this->route()['steps'][1];
		unset($step['actorRule']);

		$without = new ApprovalRouteRefusals(capabilities: new ApprovalRouteFlowCapabilities(offered: []));
		$refusals = $without->ofStep(step: $step, number: 2);
		$this->assertCount(expectedCount: 1, haystack: $refusals);
		$this->assertStringContainsString(needle: ApprovalRouteFlowCapabilities::NOMINATION, haystack: $refusals[0]);

		$with = new ApprovalRouteRefusals(capabilities: new ApprovalRouteFlowCapabilities(offered: ApprovalRouteFlowCapabilities::ALL));
		$this->assertSame(expected: [], actual: $with->ofStep(step: $step, number: 2));
	}

	/**
	 * The engine's mapper reads the nominations off the route, applies them and takes them off.
	 *
	 * @return void
	 */
	public function testTheMapperAppliesTheNominationsTheRouteCarries(): void {
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isInGroup')->willReturn(true);

		$route = $this->route() + ['nominations' => ['2' => 'dirk'], 'nominatedBy' => 'bea'];
		$applied = (new ApprovalRouteStepMapper(groups: $groups))->withNominations(route: $route);

		$this->assertSame(expected: 'dirk', actual: $applied['steps'][1]['actor']);
		$this->assertSame(expected: 'bea', actual: $applied['steps'][1]['nominatedBy']);
		$this->assertArrayNotHasKey(key: 'nominations', array: $applied);
		$this->assertArrayNotHasKey(key: 'nominatedBy', array: $applied);
	}

	/**
	 * A mapper built without a group manager refuses a nomination step rather than skip the check.
	 *
	 * @return void
	 */
	public function testWithoutAGroupManagerANominationIsRefused(): void {
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'cannot be checked');

		(new ApprovalRouteStepMapper())->withNominations(route: $this->route() + ['nominations' => ['2' => 'dirk']]);
	}
}
