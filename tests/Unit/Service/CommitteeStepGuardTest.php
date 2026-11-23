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

use OCA\Decidiq\Service\CommitteeStepGuard;
use OCA\Decidiq\Service\RegisterObjectStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A committee step is decided by its body, never by one actor (REQ-DWP-010).
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
 */
class CommitteeStepGuardTest extends TestCase {
	/**
	 * A guard over a store that knows one body.
	 *
	 * @param array<string, mixed>|null $body What the store returns for the body.
	 *
	 * @return CommitteeStepGuard The guard.
	 */
	private function guard(?array $body): CommitteeStepGuard {
		$store = $this->createMock(originalClassName: RegisterObjectStore::class);
		$store->method('find')->willReturnCallback(
			static function (string $schema, string $uuid) use ($body): ?array {
				if ($schema !== 'governance-body' || $uuid !== 'body-1') {
					return null;
				}

				return $body;
			}
		);

		return new CommitteeStepGuard(store: $store);
	}

	/**
	 * The refusal names the body that has to decide.
	 *
	 * @return void
	 */
	public function testTheRefusalNamesTheBody(): void {
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'This is a committee step: College van B&W decides it in a meeting. One person cannot close it.');

		$this->guard(body: ['name' => 'College van B&W'])->assertNotClosedByOneActor(
			stage: ['stepKind' => 'committee', 'committeeBody' => 'body-1']
		);
	}

	/**
	 * A body stored as a relation object is read by its id.
	 *
	 * @return void
	 */
	public function testABodyStoredAsAnObjectIsRead(): void {
		$this->expectExceptionMessage(message: 'College van B&W decides it');

		$this->guard(body: ['name' => 'College van B&W'])->assertNotClosedByOneActor(
			stage: ['stepKind' => 'committee', 'committeeBody' => ['id' => 'body-1']]
		);
	}

	/**
	 * A body that cannot be read is named by its id, so the refusal still points somewhere.
	 *
	 * @return void
	 */
	public function testAnUnreadableBodyIsNamedByItsId(): void {
		$this->expectExceptionMessage(message: 'This is a committee step: body-1 decides it');

		$this->guard(body: null)->assertNotClosedByOneActor(
			stage: ['stepKind' => 'committee', 'committeeBody' => 'body-1']
		);
	}

	/**
	 * A committee step that names no body is still refused.
	 *
	 * @return void
	 */
	public function testACommitteeStepWithoutABodyIsStillRefused(): void {
		$this->expectExceptionMessage(message: 'but it names no body. One person cannot close it.');

		$this->guard(body: null)->assertNotClosedByOneActor(stage: ['stepKind' => 'committee']);
	}

	/**
	 * Any other step kind passes untouched.
	 *
	 * @return void
	 */
	public function testOtherStepKindsPass(): void {
		$guard = $this->guard(body: null);

		foreach ([[], ['stepKind' => 'approval'], ['stepKind' => 'intake']] as $stage) {
			$guard->assertNotClosedByOneActor(stage: $stage);
			$this->assertFalse(condition: $guard->isCommitteeStage(stage: $stage));
		}

		$this->assertTrue(condition: $guard->isCommitteeStage(stage: ['stepKind' => 'committee']));
	}
}
