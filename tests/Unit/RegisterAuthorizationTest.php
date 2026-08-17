<?php

/**
 * Contract tests for decidesk's OpenRegister `authorization` baseline.
 *
 * Every decidesk object is reachable at
 * `/apps/openregister/api/objects/decidesk/<schema>` — the API the frontend uses
 * directly under ADR-022 — and NO decidesk controller guard sits in front of it.
 * What decides who may write there is the `authorization` block on the schema, or
 * failing that on the REGISTER row.
 *
 * This tree had neither. OpenRegister's
 * `Service/Object/PermissionHandler::hasGroupPermission()` tests
 * `empty($authorization)`, and PHP's `empty()` is true for `null` and `[]` alike,
 * so an ABSENT block takes the same default-OPEN branch as an empty one:
 *
 *     if (empty($authorization) === true || $publicOptIn === true) {
 *         if ($this->isDefaultClosedEnforced() === true
 *             && in_array($action, self::DEFAULT_CLOSED_WRITE_ACTIONS, true)
 *             && $publicOptIn === false) { return false; }
 *         return true;                       // <- create / update / delete, granted
 *     }
 *
 * `enforce_default_closed` reads `IAppConfig` with `default: false`, so on a stock
 * instance that guard never fires. Measured before the fix: 93 schemas, 24 with a
 * block (all `read`-only), and this register row with none — 69 schemas, Decision
 * / VotingRound / Vote / Participant / EngagementRecord among them, granting
 * create, update AND delete to any logged-in account.
 *
 * These tests do not re-implement OpenRegister's evaluator — an instrument built
 * from the same source as the bug reports zero. They pin the DECLARATION the
 * evaluator reads, which is the part this repository owns, and they pin the two
 * version fields without which the declaration never reaches an instance.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-006-the-register-declares-an-authorization-baseline-so-an-absent-block-cannot-grant-writes
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The register-level authorization baseline is declared, complete, and deployable.
 *
 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-006-the-register-declares-an-authorization-baseline-so-an-absent-block-cannot-grant-writes
 */
class RegisterAuthorizationTest extends TestCase {

	/**
	 * Actions OpenRegister treats as writes on an object.
	 *
	 * `PermissionHandler::DEFAULT_CLOSED_WRITE_ACTIONS` and
	 * `ANONYMOUS_FAIL_CLOSED_WRITE_ACTIONS` are both exactly this set.
	 *
	 * @var array<int,string>
	 */
	private const WRITE_ACTIONS = ['create', 'update', 'delete'];

	/**
	 * Every canonical action, from `PermissionHandler::CANONICAL_ACTIONS`.
	 *
	 * @var array<int,string>
	 */
	private const CANONICAL_ACTIONS = ['read', 'create', 'update', 'delete', 'list'];

	/**
	 * The decoded main register file.
	 *
	 * @var array<string,mixed>
	 */
	private array $register;

	/**
	 * Decode the shipped register JSON.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$path = __DIR__ . '/../../lib/Settings/decidesk_register.json';
		$this->assertFileExists($path);

		$raw = file_get_contents($path);
		$this->assertIsString($raw);

		$decoded = json_decode($raw, true);
		$this->assertIsArray($decoded, 'decidesk_register.json must be valid JSON.');

		$this->register = $decoded;
	}//end setUp()

	/**
	 * The `decidesk` register row itself.
	 *
	 * @return array<string,mixed> The register row.
	 */
	private function registerRow(): array {
		$row = $this->register['components']['registers']['decidesk'] ?? null;
		$this->assertIsArray($row, 'The decidesk register row must exist.');

		return $row;
	}//end registerRow()

	/**
	 * The register row declares an authorization block naming EVERY canonical action.
	 *
	 * Completeness is the load-bearing half. Once a block is non-empty,
	 * `hasGroupPermission()` reaches `if (empty($authorization[$action])) return false;`
	 * — so an action this block forgets to name is DENIED to everyone but the
	 * object owner and admin. A block that omitted `create` or `list` would not
	 * secure the app, it would break it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-006-the-register-declares-an-authorization-baseline-so-an-absent-block-cannot-grant-writes
	 */
	public function testTheRegisterDeclaresACompleteAuthorizationBlock(): void {
		$authorization = $this->registerRow()['authorization'] ?? null;

		$this->assertIsArray(
			$authorization,
			'The decidesk register row must declare an `authorization` block. Without one, '
				. 'PermissionHandler::hasGroupPermission() takes its default-OPEN branch for every '
				. 'schema that declares no block of its own, granting create/update/delete on '
				. 'Decision, Vote, VotingRound, Participant and EngagementRecord to every '
				. 'authenticated user through OpenRegister own object API.'
		);
		$this->assertNotEmpty($authorization, 'An EMPTY block is evaluated identically to an absent one.');

		foreach (self::CANONICAL_ACTIONS as $action) {
			$this->assertArrayHasKey(
				$action,
				$authorization,
				sprintf(
					'The block must name `%s`: OpenRegister DENIES any action a non-empty block omits, '
						. 'so an unnamed action breaks the app rather than securing it.',
					$action
				)
			);
			$this->assertNotEmpty(
				$authorization[$action],
				sprintf('`%s` must grant to someone — an empty rule list reads as "grant to nobody".', $action)
			);
		}
	}//end testTheRegisterDeclaresACompleteAuthorizationBlock()

	/**
	 * Reads and creates stay open to any authenticated user.
	 *
	 * This is what makes the change safe to land: no read goes dark and every
	 * member can still raise a decision, cast a vote or file a reaction. If this
	 * test ever goes red, the fix has become an outage.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-006-the-register-declares-an-authorization-baseline-so-an-absent-block-cannot-grant-writes
	 */
	public function testReadListAndCreateStayOpenToAuthenticatedUsers(): void {
		$authorization = $this->registerRow()['authorization'];

		foreach (['read', 'list', 'create'] as $action) {
			$this->assertContains(
				'authenticated',
				$authorization[$action],
				sprintf('`%s` must stay granted to `authenticated` — narrowing it is an outage, not a fix.', $action)
			);
		}
	}//end testReadListAndCreateStayOpenToAuthenticatedUsers()

	/**
	 * Update and delete are NOT granted to every authenticated user.
	 *
	 * This is the whole security change, and it is the assertion that would have
	 * failed on `development`. It says nothing about the object OWNER: OpenRegister
	 * bypasses the owner unconditionally, SQL-side, before any rule is consulted, so
	 * an author keeps full control of their own object and only OTHER users lose the
	 * ability to rewrite or destroy it.
	 *
	 * The `public` pseudo-group is asserted absent separately: granting it would
	 * re-open anonymous writes that OpenRegister#1955 closed by default.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-006-the-register-declares-an-authorization-baseline-so-an-absent-block-cannot-grant-writes
	 */
	public function testUpdateAndDeleteAreNotOpenToEveryAuthenticatedUser(): void {
		$authorization = $this->registerRow()['authorization'];

		foreach (['update', 'delete'] as $action) {
			$this->assertNotContains(
				'authenticated',
				$authorization[$action],
				sprintf(
					'`%s` must NOT be granted to `authenticated`: that is exactly the default-open state '
						. 'this block exists to close, and it is the shape that let a plain user overwrite '
						. "another user's record in docudesk#631.",
					$action
				)
			);
			$this->assertNotContains(
				'public',
				$authorization[$action],
				sprintf('`%s` must not be granted to the `public` pseudo-group.', $action)
			);
		}
	}//end testUpdateAndDeleteAreNotOpenToEveryAuthenticatedUser()

	/**
	 * No write action is granted to the `public` pseudo-group anywhere.
	 *
	 * `publicGroupExplicitlyGranted()` is the one thing that re-opens anonymous
	 * writes past OpenRegister#1955's fail-closed rule, so a stray `public` entry
	 * on a write action would be a wider hole than the one being closed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-006-the-register-declares-an-authorization-baseline-so-an-absent-block-cannot-grant-writes
	 */
	public function testNoWriteActionGrantsThePublicPseudoGroup(): void {
		$authorization = $this->registerRow()['authorization'];

		foreach (self::WRITE_ACTIONS as $action) {
			foreach ($authorization[$action] as $entry) {
				$group = is_array($entry) ? ($entry['group'] ?? null) : $entry;
				$this->assertNotSame(
					'public',
					$group,
					sprintf('`%s` must not grant the `public` pseudo-group — that re-opens anonymous writes.', $action)
				);
			}
		}
	}//end testNoWriteActionGrantsThePublicPseudoGroup()

	/**
	 * The register version was bumped, or the block never reaches an instance.
	 *
	 * `ImportHandler`'s register path skips outright when the incoming version is
	 * `<=` the stored one, and — unlike the SCHEMA path, which falls back to a
	 * content comparison — it has NO content-differs escape. A correct block with
	 * an unbumped version is a fix that deploys nowhere and reports success.
	 *
	 * The assertion is against the last version that shipped WITHOUT the block, so
	 * it stays meaningful as the register keeps evolving.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-006-the-register-declares-an-authorization-baseline-so-an-absent-block-cannot-grant-writes
	 */
	public function testTheRegisterVersionMovedPastTheUnprotectedRelease(): void {
		$lastUnprotected = '0.7.0';

		$this->assertGreaterThan(
			0,
			version_compare($this->registerRow()['version'] ?? '0.0.0', $lastUnprotected),
			sprintf(
				'The register version must be greater than %s (the last release with no authorization '
					. 'block), or ImportHandler skips the import and the fix never lands.',
				$lastUnprotected
			)
		);
		$this->assertGreaterThan(
			0,
			version_compare($this->register['info']['version'] ?? '0.0.0', $lastUnprotected),
			'The configuration version must move with it.'
		);
	}//end testTheRegisterVersionMovedPastTheUnprotectedRelease()

	/**
	 * The app version moved too, or `occ upgrade` never runs the import.
	 *
	 * `InitializeSettings` is a `<post-migration>` repair step, and post-migration
	 * steps run only on `occ upgrade` — which is a NO-OP when the app version has
	 * not changed. Both bumps are needed; either alone is a fix that sits on disk.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-006-the-register-declares-an-authorization-baseline-so-an-absent-block-cannot-grant-writes
	 */
	public function testTheAppVersionMovedSoTheRepairStepRuns(): void {
		$path = __DIR__ . '/../../appinfo/info.xml';
		$this->assertFileExists($path);

		$xml = simplexml_load_file($path);
		$this->assertNotFalse($xml, 'appinfo/info.xml must be readable XML.');

		$this->assertGreaterThan(
			0,
			version_compare((string)$xml->version, '0.4.6'),
			'The app version must be greater than 0.4.6 (the last release with no authorization '
				. 'block), or `occ upgrade` is a no-op and InitializeSettings never re-imports the register.'
		);

		// Positive control on the same reader: the file really was parsed, so a
		// failure above means "not bumped", never "read an empty document".
		$this->assertSame('decidesk', (string)$xml->id);
	}//end testTheAppVersionMovedSoTheRepairStepRuns()

	/**
	 * The 24 schemas that already declare their own block are untouched.
	 *
	 * `PermissionHandler::resolveAuthorization()` uses a schema's own block when it
	 * has one and falls back to the register's only when it does not. That cascade
	 * is the entire reason this baseline could be applied in one place, and it is
	 * also the thing that would silently break the public-read rules if a schema
	 * block were "helpfully" removed later. Those blocks grant `read` to the
	 * `public` group under a publication-date match; they name no write action, so
	 * they already fail closed on writes.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-006-the-register-declares-an-authorization-baseline-so-an-absent-block-cannot-grant-writes
	 */
	public function testSchemasWithTheirOwnBlockStillDeclareOnlyReads(): void {
		$files = array_merge(
			[__DIR__ . '/../../lib/Settings/decidesk_register.json'],
			glob(__DIR__ . '/../../lib/Settings/register.d/*.json') ?: []
		);

		$withBlock = 0;
		foreach ($files as $file) {
			$decoded = json_decode((string)file_get_contents($file), true);
			foreach (($decoded['components']['schemas'] ?? []) as $name => $schema) {
				if (is_array($schema) === false || isset($schema['authorization']) === false) {
					continue;
				}

				$withBlock++;
				foreach (self::WRITE_ACTIONS as $action) {
					$this->assertArrayNotHasKey(
						$action,
						$schema['authorization'],
						sprintf(
							'Schema `%s` (%s) declares its own authorization block, which SHADOWS the '
								. 'register baseline entirely. Adding a write action here opts that schema '
								. 'out of the baseline — do it deliberately and update this test, never by '
								. 'accident.',
							$name,
							basename($file)
						)
					);
				}
			}
		}

		// The count is the positive control: without it this loop passes vacuously
		// if the schemas move, are renamed, or stop being found at all.
		$this->assertSame(
			24,
			$withBlock,
			'Expected 24 schema-level authorization blocks. A different number means schemas gained or '
				. 'lost their own block, which changes which ones the register baseline governs.'
		);
	}//end testSchemasWithTheirOwnBlockStillDeclareOnlyReads()
}//end class
