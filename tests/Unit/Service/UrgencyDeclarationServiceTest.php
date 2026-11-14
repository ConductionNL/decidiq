<?php

/**
 * Declaring a decision urgent, end to end over an in-memory register.
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
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTime;
use OCA\Decidiq\Kernel\Contracts\ParticipantResolverInterface;
use OCA\Decidiq\Kernel\Contracts\ProcessTemplateServiceInterface;
use OCA\Decidiq\Lifecycle\UrgencyTriggerGuard;
use OCA\Decidiq\Service\DecisionContextResolver;
use OCA\Decidiq\Service\UrgencyDeclarationService;
use OCA\Decidiq\Service\UrgencyWriteGrant;
use OCA\Decidiq\Service\UrgentRatificationService;
use OCA\Decidiq\Tests\Unit\Support\UrgentProcedureStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Chair declares, others are refused, no policy fails closed, the ratification follows.
 *
 * @covers \OCA\Decidiq\Service\UrgencyDeclarationService
 * @uses   \OCA\Decidiq\Lifecycle\UrgencyTriggerGuard
 * @uses   \OCA\Decidiq\Service\DecisionContextResolver
 * @uses   \OCA\Decidiq\Service\UrgencyWriteGrant
 * @uses   \OCA\Decidiq\Service\UrgentRatificationService
 */
class UrgencyDeclarationServiceTest extends TestCase {

	/**
	 * The policy of the college's template.
	 *
	 * @var array<string, mixed>
	 */
	private const POLICY = ['allowedTriggerRoles' => ['chair'], 'ratificationRequired' => true, 'ratifyingBody' => 'body-raad', 'minimumNoticeFloorHours' => 24, 'responseDeadlineHours' => ['min' => 12, 'max' => 96]];

	/**
	 * The register: a proposed decision of the college meeting chaired by the burgemeester.
	 *
	 * @var UrgentProcedureStore
	 */
	private UrgentProcedureStore $store;

	/**
	 * The grant, to see it is revoked after the save.
	 *
	 * @var UrgencyWriteGrant
	 */
	private UrgencyWriteGrant $grant;

	/**
	 * Build the register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new UrgentProcedureStore();
		$this->store->put(schema: 'decision', object: ['id' => 'dec-1', 'title' => 'Noodopvang statushouders', 'lifecycle' => 'proposed', 'meeting' => 'meet-college']);
		$this->store->put(schema: 'meeting', object: ['id' => 'meet-college', 'governanceBody' => 'body-college', 'chair' => 'part-burgemeester']);
		$this->store->put(schema: 'participant', object: ['id' => 'part-burgemeester', 'nextcloudUserId' => 'burgemeester']);
		$this->grant = new UrgencyWriteGrant();
	}//end setUp()

	/**
	 * The service, with the body's policy (or none).
	 *
	 * @param array<string, mixed>|null $policy The college's urgency policy.
	 * @param array<string, bool>       $roles  Roster answers per user.
	 *
	 * @return UrgencyDeclarationService
	 */
	private function service(?array $policy = self::POLICY, array $roles = []): UrgencyDeclarationService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($this->store);
		$templates = $this->createMock(ProcessTemplateServiceInterface::class);
		$templates->method('resolveUrgencyPolicyForBody')->willReturnCallback(
			static fn (?string $body): ?array => $body === 'body-college' ? $policy : null
		);
		$participants = $this->createMock(ParticipantResolverInterface::class);
		$participants->method('hasRole')->willReturnCallback(static fn (string $meeting, string $uid): bool => ($roles[$uid] ?? false));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-06-02T09:15:00+02:00'));

		return new UrgencyDeclarationService(
			container: $container,
			context: new DecisionContextResolver(logger: new NullLogger()),
			templates: $templates,
			guard: new UrgencyTriggerGuard(participants: $participants),
			grant: $this->grant,
			ratification: new UrgentRatificationService(timeFactory: $time),
			timeFactory: $time,
			logger: new NullLogger(),
		);
	}//end service()

	/**
	 * The chair declares: fields stamped, lifecycle untouched, ratifying stage appended.
	 *
	 * @return void
	 */
	public function testTheChairDeclaresAndTheRatificationFollows(): void {
		$result = $this->service()->declare(decisionId: 'dec-1', userId: 'burgemeester', reason: '  Acute opvangcrisis; eerstvolgende raadsvergadering is pas over drie weken ');

		$this->assertSame(200, $result['status']);
		$this->assertSame(['decision', 'dec-1'], [$this->store->saves[0][0], $this->store->saves[0][1]]);
		$this->assertSame(
			[
				'id' => 'dec-1', 'title' => 'Noodopvang statushouders', 'lifecycle' => 'proposed', 'meeting' => 'meet-college',
				'isUrgent' => true, 'urgencyReason' => 'Acute opvangcrisis; eerstvolgende raadsvergadering is pas over drie weken',
				'urgencyDeclaredBy' => 'burgemeester', 'urgencyDeclaredAt' => '2026-06-02T09:15:00+02:00',
			],
			$this->store->saves[0][2]
		);
		$this->assertSame('ratifying', $result['data']['ratification']['stage']['stageType']);
		$this->assertSame('body-raad', $result['data']['ratification']['stage']['assignedBody']);
		$this->assertTrue($result['data']['ratification']['stage']['placementPending']);
		$this->assertFalse($this->grant->isGranted(decisionId: 'dec-1'));
	}//end testTheChairDeclaresAndTheRatificationFollows()

	/**
	 * Someone who is not the chair is refused and nothing is saved.
	 *
	 * @return void
	 */
	public function testAnotherUserIsRefusedAndNothingChanges(): void {
		$result = $this->service()->declare(decisionId: 'dec-1', userId: 'raadslid', reason: 'Spoed');

		$this->assertSame(403, $result['status']);
		$this->assertSame([], $this->store->saves);
	}//end testAnotherUserIsRefusedAndNothingChanges()

	/**
	 * A body without an urgency policy fails closed, for the chair too.
	 *
	 * @return void
	 */
	public function testABodyWithoutAPolicyFailsClosed(): void {
		$result = $this->service(policy: null)->declare(decisionId: 'dec-1', userId: 'burgemeester', reason: 'Spoed');

		$this->assertSame(['status' => 422, 'data' => ['message' => 'The urgent procedure is not configured for this body.']], $result);
		$this->assertSame([], $this->store->saves);
	}//end testABodyWithoutAPolicyFailsClosed()

	/**
	 * An empty reason is refused naming urgencyReason.
	 *
	 * @return void
	 */
	public function testAnEmptyReasonIsRefused(): void {
		$result = $this->service()->declare(decisionId: 'dec-1', userId: 'burgemeester', reason: '');

		$this->assertSame(['status' => 422, 'data' => ['message' => 'urgencyReason is required.']], $result);
	}//end testAnEmptyReasonIsRefused()

	/**
	 * An allowed role other than chair may declare; an override names the ratifying body.
	 *
	 * @return void
	 */
	public function testAnAllowedRoleDeclaresWithABodyOverride(): void {
		$policy = array_merge(self::POLICY, ['allowedTriggerRoles' => ['chair', 'secretary']]);

		$result = $this->service(policy: $policy, roles: ['secretaris' => true])->declare(decisionId: 'dec-1', userId: 'secretaris', reason: 'Spoed', bodyOverride: ' body-rvc ');

		$this->assertSame(200, $result['status']);
		$this->assertSame('body-rvc', $result['data']['ratification']['stage']['assignedBody']);
	}//end testAnAllowedRoleDeclaresWithABodyOverride()

	/**
	 * An unknown decision is 404; an already urgent one is 409.
	 *
	 * @return void
	 */
	public function testUnknownAndAlreadyUrgentDecisions(): void {
		$this->assertSame(404, $this->service()->declare(decisionId: 'nope', userId: 'burgemeester', reason: 'x')['status']);

		$this->store->put(schema: 'decision', object: ['id' => 'dec-1', 'meeting' => 'meet-college', 'isUrgent' => true]);
		$this->assertSame(409, $this->service()->declare(decisionId: 'dec-1', userId: 'burgemeester', reason: 'x')['status']);
		$this->assertSame([], $this->store->saves);
	}//end testUnknownAndAlreadyUrgentDecisions()

	/**
	 * A failed save answers 500 and still revokes the grant.
	 *
	 * @return void
	 */
	public function testAFailedSaveRevokesTheGrant(): void {
		$this->store->failingSaves = ['decision'];

		$result = $this->service()->declare(decisionId: 'dec-1', userId: 'burgemeester', reason: 'Spoed');

		$this->assertSame(500, $result['status']);
		$this->assertFalse($this->grant->isGranted(decisionId: 'dec-1'));
	}//end testAFailedSaveRevokesTheGrant()

	/**
	 * When the ratification cannot be appended the urgency stands and the answer warns.
	 *
	 * @return void
	 */
	public function testAFailedRatificationIsReportedNotHidden(): void {
		$this->store->failingSaves = ['decision-stage'];

		$result = $this->service()->declare(decisionId: 'dec-1', userId: 'burgemeester', reason: 'Spoed');

		$this->assertSame(200, $result['status']);
		$this->assertNull($result['data']['ratification']);
		$this->assertStringStartsWith('The decision is urgent, but its ratification stage could not be added', $result['data']['warning']);
	}//end testAFailedRatificationIsReportedNotHidden()
}//end class
