<?php

/**
 * Decision lifecycle vocabulary conformance.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Lifecycle\DecisionTransitionGuard;
use OCA\Decidiq\Lifecycle\MotionLifecycleTransitioner;
use OCA\Decidiq\Service\AmendmentOrderService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every lifecycle word the app writes must be a word the schema accepts.
 *
 * WHAT THIS GUARDS. ADR-005 folded `Motion` and `Amendment` into `Decision`.
 * The SLUG migrated and the schemas were deleted, but the VOCABULARY did not:
 * thirteen files went on writing `submitted | debating | adopted | rejected`,
 * none of which `Decision.lifecycle` can hold. The measured symptom was a 400
 * on every transition — the same payload with `deliberating` validates where
 * `debating` is refused.
 *
 * That defect was invisible to the whole unit suite, because every test that
 * touched a lifecycle used the SAME retired words as the code under test. Two
 * halves agreeing with each other is not a measurement. The only thing that
 * could have caught it is a comparison against the SHIPPED SCHEMA, which is
 * what this file does: it reads `lib/Settings/decidesk_register.json` off disk
 * and holds the service constants against the enum the register actually
 * declares.
 *
 * It is deliberately NOT written against a hardcoded list of the eight states.
 * A copy of the enum in a test file is just a fourteenth place for the
 * vocabulary to drift; the register is the authority, so the register is what
 * is read.
 */
final class DecisionLifecycleVocabularyTest extends TestCase {

	/**
	 * The `Decision.lifecycle` enum as the shipped register declares it.
	 *
	 * @var string[]
	 */
	private array $enum = [];

	/**
	 * Load the lifecycle enum from the shipped register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$registerPath = __DIR__ . '/../../../lib/Settings/decidesk_register.json';
		self::assertFileExists($registerPath, 'the shipped register is the authority for this test');

		$raw = file_get_contents($registerPath);
		self::assertIsString($raw);

		$register = json_decode($raw, true);
		self::assertIsArray($register, 'the shipped register must be valid JSON');

		// The register keys schemas by TITLE (`Decision`); the slug (`decision`)
		// is what the object API is addressed with. Reading the wrong one yields
		// null, and a null that is not asserted on would have made this whole
		// file pass over an empty enum — the exact "check that did not run"
		// shape it exists to prevent. Hence the explicit assertion below.
		$decision = ($register['components']['schemas']['Decision'] ?? null);
		self::assertIsArray($decision, 'the register must declare a `Decision` schema');

		$enum = ($decision['properties']['lifecycle']['enum'] ?? null);
		self::assertIsArray($enum, 'Decision.lifecycle must declare an enum');
		self::assertNotEmpty($enum);

		$this->enum = array_map('strval', $enum);

	}//end setUp()

	/**
	 * Positive control: these classes resolve to THIS worktree.
	 *
	 * Nextcloud's autoloader hijacks `OCA\<App>\*` to the INSTALLED app once
	 * `base.php` has been loaded, so a suite run inside a provisioned container
	 * can reflect the DEPLOYED code while appearing to test the checkout. Every
	 * assertion below reads a constant off a class; if that class came from
	 * somewhere else, the whole file is measuring the wrong program and would
	 * report green over an unmigrated tree.
	 *
	 * @return void
	 */
	public function testClassesUnderTestResolveToThisRepository(): void {
		$repoRoot = realpath(__DIR__ . '/../../..');
		self::assertIsString($repoRoot);

		foreach ([MotionLifecycleTransitioner::class, AmendmentOrderService::class, DecisionTransitionGuard::class] as $class) {
			$file = (new ReflectionClass($class))->getFileName();
			self::assertIsString($file, "$class must be loadable from a file");
			self::assertStringStartsWith(
				$repoRoot . DIRECTORY_SEPARATOR,
				(string)realpath($file),
				"$class resolved to $file, which is outside this worktree — the autoloader is serving a different copy and this suite is not testing the code under review"
			);
		}

	}//end testClassesUnderTestResolveToThisRepository()

	/**
	 * Every state MotionService can transition to is in the schema enum.
	 *
	 * @return void
	 */
	public function testMotionTransitionTablesUseOnlySchemaStates(): void {
		foreach (['MOTION_TRANSITIONS', 'AMENDMENT_TRANSITIONS'] as $constant) {
			$table = $this->constantOf(MotionLifecycleTransitioner::class, $constant);

			foreach ($table as $from => $targets) {
				self::assertContains(
					(string)$from,
					$this->enum,
					"$constant declares a source state '$from' that Decision.lifecycle cannot hold"
				);

				foreach ($targets as $to) {
					self::assertContains(
						(string)$to,
						$this->enum,
						"$constant declares a target state '$to' that Decision.lifecycle cannot hold — a transition to it would be refused by the register"
					);
				}
			}
		}

	}//end testMotionTransitionTablesUseOnlySchemaStates()

	/**
	 * The outcome vocabulary never leaks into the lifecycle tables.
	 *
	 * This is the specific shape of the original defect: `adopted` and
	 * `rejected` sat in the transition tables as if they were states. They are
	 * values of `Decision.outcome`, an orthogonal axis, and the schema's own
	 * description says so. The assertion is separate from the enum check above
	 * because it must keep failing even if someone later widened the lifecycle
	 * enum to contain them.
	 *
	 * @return void
	 */
	public function testOutcomeValuesAreNotLifecycleStates(): void {
		foreach (['MOTION_TRANSITIONS', 'AMENDMENT_TRANSITIONS'] as $constant) {
			$table = $this->constantOf(MotionLifecycleTransitioner::class, $constant);
			$states = array_unique(array_merge(array_keys($table), ...array_values($table)));

			foreach (DecisionTransitionGuard::OUTCOME_VALUES as $outcome) {
				self::assertNotContains(
					$outcome,
					array_map('strval', $states),
					"$constant treats the outcome value '$outcome' as a lifecycle state; ADR-005 keeps the two axes separate"
				);
			}
		}

	}//end testOutcomeValuesAreNotLifecycleStates()

	/**
	 * AmendmentOrderService's two state lists partition the enum exactly.
	 *
	 * They are used in OPPOSITE directions — UNDECIDED_STATES to block, and
	 * DECIDED_STATES to wave through — so a state missing from one fails open
	 * while a state missing from the other fails closed. Neither gap announces
	 * itself, and before the ADR-005 migration both were wrong at once. Asserting
	 * a partition catches a new schema state on either side.
	 *
	 * @return void
	 */
	public function testAmendmentOrderStatesPartitionTheEnum(): void {
		$undecided = array_map('strval', $this->constantOf(AmendmentOrderService::class, 'UNDECIDED_STATES'));
		$decided = array_map('strval', $this->constantOf(AmendmentOrderService::class, 'DECIDED_STATES'));

		self::assertSame(
			[],
			array_values(array_intersect($undecided, $decided)),
			'a state cannot be both undecided and decided'
		);

		$covered = array_merge($undecided, $decided);
		sort($covered);
		$expected = $this->enum;
		sort($expected);

		self::assertSame(
			$expected,
			$covered,
			'every Decision.lifecycle state must be classified as either undecided or decided; an unclassified state silently fails open in assertAmendmentsDecided() and fails closed in the ordering check'
		);

	}//end testAmendmentOrderStatesPartitionTheEnum()

	/**
	 * The guard's terminal states are a subset of the schema enum.
	 *
	 * @return void
	 */
	public function testTerminalOutcomeStatesAreSchemaStates(): void {
		foreach (DecisionTransitionGuard::TERMINAL_OUTCOME_STATES as $state) {
			self::assertContains(
				$state,
				$this->enum,
				"terminal state '$state' is not a Decision.lifecycle value"
			);
		}

	}//end testTerminalOutcomeStatesAreSchemaStates()

	/**
	 * Read a (possibly private) class constant.
	 *
	 * @param class-string $class The class holding the constant
	 * @param string $constant The constant name
	 *
	 * @return array<mixed> The constant value
	 */
	private function constantOf(string $class, string $constant): array {
		$reflection = new ReflectionClass($class);
		self::assertTrue($reflection->hasConstant($constant), "$class::$constant must exist");

		$value = $reflection->getConstant($constant);
		self::assertIsArray($value);

		return $value;
	}//end constantOf()
}//end class
