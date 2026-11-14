<?php

/**
 * Who may declare a decision urgent.
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
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Lifecycle;

use OCA\Decidiq\Kernel\Contracts\ParticipantResolverInterface;
use OCA\Decidiq\Lifecycle\UrgencyTriggerGuard;
use PHPUnit\Framework\TestCase;

/**
 * The guard matrix: configured or not, chair, allowed role, other, unresolvable.
 *
 * @covers \OCA\Decidiq\Lifecycle\UrgencyTriggerGuard
 */
class UrgencyTriggerGuardTest extends TestCase {

	/**
	 * A policy that allows the given roles.
	 *
	 * @param list<string> $roles The allowed trigger roles.
	 *
	 * @return array<string, mixed>
	 */
	private static function policy(array $roles): array {
		return ['allowedTriggerRoles' => $roles, 'ratificationRequired' => true, 'ratifyingBody' => 'body-raad'];
	}//end policy()

	/**
	 * A guard whose roster answers hasRole with the given value, recording the calls.
	 *
	 * @param bool              $hasRole The roster's answer.
	 * @param list<array>|null  $calls   Receives the calls.
	 *
	 * @return UrgencyTriggerGuard
	 */
	private function guard(bool $hasRole, ?array &$calls = null): UrgencyTriggerGuard {
		$calls        = [];
		$participants = $this->createMock(ParticipantResolverInterface::class);
		$participants->method('hasRole')->willReturnCallback(
			static function (string $meetingId, string $nextcloudUid, array $roles) use ($hasRole, &$calls): bool {
				$calls[] = [$meetingId, $nextcloudUid, $roles];
				return $hasRole;
			}
		);

		return new UrgencyTriggerGuard(participants: $participants);
	}//end guard()

	/**
	 * Without a policy the procedure is unavailable, for the chair too.
	 *
	 * @return void
	 */
	public function testWithoutAPolicyTheProcedureIsUnavailable(): void {
		$refusal = $this->guard(hasRole: true)->refusal(policy: null, meetingId: 'meet-1', chairUserId: 'burgemeester', userId: 'burgemeester', reason: 'Acute crisis');

		$this->assertSame(['status' => 422, 'message' => 'The urgent procedure is not configured for this body.'], $refusal);
	}//end testWithoutAPolicyTheProcedureIsUnavailable()

	/**
	 * The resolved chair may declare urgency without a roster lookup.
	 *
	 * @return void
	 */
	public function testTheChairMayDeclare(): void {
		$guard = $this->guard(hasRole: false, calls: $calls);

		$this->assertNull($guard->refusal(policy: self::policy(['chair']), meetingId: 'meet-1', chairUserId: 'burgemeester', userId: 'burgemeester', reason: 'Acute crisis'));
		$this->assertSame([], $calls);
	}//end testTheChairMayDeclare()

	/**
	 * A holder of an allowed role may declare; the roster is asked for exactly those roles.
	 *
	 * @return void
	 */
	public function testAnAllowedRoleMayDeclare(): void {
		$guard = $this->guard(hasRole: true, calls: $calls);

		$this->assertNull($guard->refusal(policy: self::policy(['chair', 'secretary']), meetingId: 'meet-1', chairUserId: 'ceo', userId: 'secretaris', reason: 'Datalek'));
		$this->assertSame([['meet-1', 'secretaris', ['chair', 'secretary']]], $calls);
	}//end testAnAllowedRoleMayDeclare()

	/**
	 * Anyone else is refused with 403, before the reason is looked at.
	 *
	 * @return void
	 */
	public function testAnotherUserIsRefused(): void {
		$refusal = $this->guard(hasRole: false)->refusal(policy: self::policy(['chair']), meetingId: 'meet-1', chairUserId: 'burgemeester', userId: 'raadslid', reason: '');

		$this->assertSame(403, $refusal['status']);
	}//end testAnotherUserIsRefused()

	/**
	 * Without a meeting no role can be resolved: refused, and the roster is not asked.
	 *
	 * @return void
	 */
	public function testAnUnresolvableRoleIsRefused(): void {
		$guard = $this->guard(hasRole: true, calls: $calls);

		$this->assertSame(403, $guard->refusal(policy: self::policy(['chair']), meetingId: null, chairUserId: null, userId: 'burgemeester', reason: 'x')['status']);
		$this->assertSame([], $calls);
	}//end testAnUnresolvableRoleIsRefused()

	/**
	 * A policy with no roles allows nobody.
	 *
	 * @return void
	 */
	public function testAPolicyWithoutRolesAllowsNobody(): void {
		$this->assertFalse($this->guard(hasRole: true)->mayTrigger(policy: self::policy([]), meetingId: 'meet-1', chairUserId: 'burgemeester', userId: 'burgemeester'));
	}//end testAPolicyWithoutRolesAllowsNobody()

	/**
	 * The chair counts only when chair is an allowed role.
	 *
	 * @return void
	 */
	public function testTheChairCountsOnlyWhenAllowed(): void {
		$guard = $this->guard(hasRole: false);

		$this->assertFalse($guard->mayTrigger(policy: self::policy(['secretary']), meetingId: 'meet-1', chairUserId: 'burgemeester', userId: 'burgemeester'));
	}//end testTheChairCountsOnlyWhenAllowed()

	/**
	 * An empty or over-long reason is refused with 422 naming urgencyReason.
	 *
	 * @return void
	 */
	public function testTheReasonIsRequiredAndBounded(): void {
		$guard = $this->guard(hasRole: false);

		$this->assertSame(['status' => 422, 'message' => 'urgencyReason is required.'], $guard->refusal(policy: self::policy(['chair']), meetingId: 'meet-1', chairUserId: 'burgemeester', userId: 'burgemeester', reason: "  \n "));
		$tooLong = $guard->refusal(policy: self::policy(['chair']), meetingId: 'meet-1', chairUserId: 'burgemeester', userId: 'burgemeester', reason: str_repeat('a', UrgencyTriggerGuard::MAX_REASON_LENGTH + 1));
		$this->assertSame(422, $tooLong['status']);
		$this->assertStringContainsString('urgencyReason', $tooLong['message']);
	}//end testTheReasonIsRequiredAndBounded()
}//end class
