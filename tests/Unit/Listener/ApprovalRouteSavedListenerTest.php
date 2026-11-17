<?php

/**
 * Tests: an approval route that needs a missing OpenRegister feature is refused when saved.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\ApprovalRouteSavedListener;
use OCA\Decidiq\Listener\Registrar\SaveGuardSubscriptions;
use OCA\Decidiq\Service\ApprovalRouteFlowCapabilities;
use OCA\Decidiq\Service\ApprovalRouteFlowCompiler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Listener\ApprovalRouteSavedListener
 * @uses   \OCA\Decidiq\Listener\SaveGuardEvent
 * @uses   \OCA\Decidiq\Service\ApprovalRouteFlowCompiler
 * @uses   \OCA\Decidiq\Service\ApprovalRouteFlowCapabilities
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-009-a-route-that-needs-a-missing-openregister-feature-is-refused-when-saved
 */
class ApprovalRouteSavedListenerTest extends TestCase {

	/**
	 * A two-step route whose second step asks for the manager of the owning organisation.
	 *
	 * @var array<string, mixed>
	 */
	private const MANAGER_ROUTE = [
		'_schemaSlug' => 'approval-route',
		'name' => 'Parafeerroute inkoop',
		'subjectType' => 'decision',
		'active' => true,
		'steps' => [
			['order' => 1, 'stageType' => 'preparatory', 'actorType' => 'person', 'actor' => 'sanne'],
			['order' => 2, 'stageType' => 'endorsement', 'actorRule' => 'manager-of-owning-organisation', 'actorRuleSubject' => 'sanne'],
		],
	];

	/**
	 * @param array<string, mixed> $row The saved object.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		return $entity;
	}//end entity()

	/**
	 * The guard against an OpenRegister without a rule strategy.
	 *
	 * @param list<string> $offered The features the OpenRegister offers.
	 *
	 * @return ApprovalRouteSavedListener The guard.
	 */
	private static function guard(array $offered = []): ApprovalRouteSavedListener {
		return new ApprovalRouteSavedListener(new ApprovalRouteFlowCompiler(new ApprovalRouteFlowCapabilities($offered)));
	}//end guard()

	/**
	 * Scenario: a step names the manager rule on an OpenRegister without it.
	 *
	 * @return void
	 */
	public function testARouteWithAManagerRuleIsRefusedNamingStepTwoAndTheRule(): void {
		$event = new ObjectCreatingEvent($this->entity(self::MANAGER_ROUTE));

		self::guard()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(
			'The approval route was not saved. Step 2 uses performer by rule, which the installed OpenRegister does not offer, so the route cannot be saved.',
			$event->getErrors()['message']
		);
	}//end testARouteWithAManagerRuleIsRefusedNamingStepTwoAndTheRule()

	/**
	 * The same refusal holds on an update.
	 *
	 * @return void
	 */
	public function testAnUpdateThatAddsTheRuleIsRefused(): void {
		$old   = $this->entity(['_schemaSlug' => 'approval-route', 'steps' => [['order' => 1, 'actorType' => 'person', 'actor' => 'sanne']]]);
		$event = new ObjectUpdatingEvent($this->entity(self::MANAGER_ROUTE), $old);

		self::guard()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertStringContainsString('Step 2', (string)$event->getErrors()['message']);
	}//end testAnUpdateThatAddsTheRuleIsRefused()

	/**
	 * Once OpenRegister offers the rule, the same route saves.
	 *
	 * @return void
	 */
	public function testTheRouteSavesWhereOpenRegisterOffersTheRule(): void {
		$event = new ObjectCreatingEvent($this->entity(self::MANAGER_ROUTE));

		self::guard([ApprovalRouteFlowCapabilities::PERFORMER_RULE])->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testTheRouteSavesWhereOpenRegisterOffersTheRule()

	/**
	 * An inactive route is a draft and is not judged until it is switched on.
	 *
	 * @return void
	 */
	public function testAnInactiveRouteIsNotJudged(): void {
		self::assertNull(self::guard()->refusal(['active' => false] + self::MANAGER_ROUTE));
		self::assertNotNull(self::guard()->refusal(self::MANAGER_ROUTE));
	}//end testAnInactiveRouteIsNotJudged()

	/**
	 * Another schema's record is never judged.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsIgnored(): void {
		$event = new ObjectCreatingEvent($this->entity(['_schemaSlug' => 'decision'] + self::MANAGER_ROUTE));

		self::guard()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemaIsIgnored()

	/**
	 * The guard runs on create and on update of approval routes.
	 *
	 * @return void
	 */
	public function testTheGuardIsSubscribedOnCreateAndUpdate(): void {
		$events = [];
		foreach (SaveGuardSubscriptions::ALL as $subscription) {
			if ($subscription['listener'] === ApprovalRouteSavedListener::class) {
				$events[] = $subscription['event'];
				self::assertSame(['approval-route'], $subscription['schemas']);
			}
		}

		self::assertSame([ObjectCreatingEvent::class, ObjectUpdatingEvent::class], $events);
	}//end testTheGuardIsSubscribedOnCreateAndUpdate()
}//end class
