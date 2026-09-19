<?php

/**
 * A declaration about how a leaf loads must never be why it does not load.
 *
 * Both of decidiq's leaf listeners name `LeafDescriptor::LOADS_VIA_OWN_SCRIPT`.
 * That constant, and the `loadStrategy` constructor parameter beside it, arrived
 * together in openregister#3956. Decidiq does not choose which OpenRegister an
 * admin runs it next to, and on an older one the constant read is an `Error`.
 * Each listener catches `Throwable` so a single bad leaf cannot take the whole
 * catalogue down, so the failure is not an error anywhere: the leaf is simply
 * absent, and a warning in nextcloud.log is the only trace.
 *
 * hermiq measured exactly that on a live instance, seven occurrences in the log
 * with the agent leaf never registering at all. planninq#627 and #629 fixed the
 * same shape. This file is the assertion that keeps it fixed here.
 *
 * WHY THE SUITE COULD NOT SEE IT. `tests/Stubs/Service/Integration/LeafDescriptor.php`
 * mirrors openregister `development`, so it carries the constant and the
 * parameter and the unguarded call worked perfectly. A green board was the
 * reason nobody looked. The degradation therefore has to be driven through the
 * listener's own seam rather than by swapping the descriptor, since two versions
 * of one class cannot both be loaded in one process.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\RegisterApprovalChainLeafListener;
use OCA\Decidiq\Listener\RegisterDecisionsLeafListener;
use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * The load-strategy declaration degrades instead of costing the leaf.
 *
 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
 */
class LeafLoadStrategyCapabilityTest extends TestCase {

	/**
	 * Every warning a listener logged during the current test.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * Reset the recorded warnings before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->warnings = [];
	}//end setUp()

	/**
	 * What the listener said when it decided not to register its leaf.
	 *
	 * This string is the failure message on every count assertion below. Without
	 * it the report reads `actual size 0 matches expected size 1`, which is what
	 * planninq stared at for three days while the reason, an `Error` naming a
	 * constant, sat in a warning nobody captured.
	 *
	 * @return string The recorded warnings, or a note that there were none.
	 */
	private function swallowed(): string {
		if ($this->warnings === []) {
			return 'The listener logged no warning, so nothing was swallowed: whatever failed below '
				. 'happened on a path that did not throw.';
		}

		return 'The listener swallowed: ' . implode(' | ', $this->warnings);
	}//end swallowed()

	/**
	 * An l10n that returns its own source string.
	 *
	 * @return IL10N The identity translator.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return $l10n;
	}//end l10n()

	/**
	 * A logger that records what the listener swallowed.
	 *
	 * @return LoggerInterface The recording logger.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message): void {
				$this->warnings[] = (string) $message;
			}
		);

		return $logger;
	}//end logger()

	/**
	 * The two listeners and the leaf id each must contribute.
	 *
	 * @return array<string, array{0: class-string, 1: string}> Listener class and leaf id.
	 */
	public static function leafListeners(): array {
		return [
			'approval chain' => [RegisterApprovalChainLeafListener::class, 'decidiq-approval-chain'],
			'decisions' => [RegisterDecisionsLeafListener::class, 'decidesk-decisions'],
		];
	}//end leafListeners()

	/**
	 * CONTROL. The seam exists on both listeners, protected, taking no argument.
	 *
	 * Every degradation test below overrides this method. An override of a method
	 * the parent does not declare simply ADDS one, and the test would then pass
	 * while asserting nothing about the listener's real behaviour. This is the
	 * check that separates "the guard works" from "the guard is not there".
	 *
	 * @param class-string $listenerClass The listener under test.
	 * @param string       $leafId        The leaf id it contributes.
	 *
	 * @return void
	 *
	 * @dataProvider leafListeners
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
	 */
	public function testTheCapabilityCheckIsAProtectedSeamOnTheListenerItself(string $listenerClass, string $leafId): void {
		$this->assertTrue(
			(new ReflectionClass($listenerClass))->hasMethod('descriptorSupportsLoadStrategy'),
			sprintf(
				'%s must declare descriptorSupportsLoadStrategy() itself, or the anonymous overrides '
					. 'below add a method nobody calls and assert nothing about the %s leaf.',
				$listenerClass,
				$leafId
			)
		);

		$seam = new ReflectionMethod($listenerClass, 'descriptorSupportsLoadStrategy');
		$this->assertTrue($seam->isProtected(), 'The capability check must stay drivable from a subclass.');
		$this->assertSame(0, $seam->getNumberOfParameters());
	}//end testTheCapabilityCheckIsAProtectedSeamOnTheListenerItself()

	/**
	 * CONTROL. The descriptor in this suite really does accept a load strategy.
	 *
	 * Both positive tests below would pass vacuously against a descriptor that
	 * had neither half, because the guard would correctly decline and the leaf
	 * would carry no strategy. So the suite states which OpenRegister it is
	 * running against before asserting anything about the declaration.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
	 */
	public function testTheDescriptorBesideThisSuiteCarriesBothHalvesOfTheFeature(): void {
		$this->assertTrue(
			defined(LeafDescriptor::class . '::LOADS_VIA_OWN_SCRIPT'),
			'The LeafDescriptor beside this suite predates openregister#3956: it has no LOADS_VIA_OWN_SCRIPT.'
		);

		$constructor = (new ReflectionClass(LeafDescriptor::class))->getConstructor();
		$this->assertNotNull($constructor);

		$parameters = array_map(
			static fn ($parameter): string => $parameter->getName(),
			$constructor->getParameters()
		);

		$this->assertContains(
			'loadStrategy',
			$parameters,
			'The LeafDescriptor beside this suite carries the constant but not the parameter. '
				. 'That is the partial-backport case, and passing the argument to it throws '
				. '"Unknown named parameter $loadStrategy".'
		);
	}//end testTheDescriptorBesideThisSuiteCarriesBothHalvesOfTheFeature()

	/**
	 * Against the current descriptor, both leaves declare `own-script`.
	 *
	 * The guard must not cost the declaration on the version that accepts it.
	 *
	 * @param class-string $listenerClass The listener under test.
	 * @param string       $leafId        The leaf id it contributes.
	 *
	 * @return void
	 *
	 * @dataProvider leafListeners
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
	 */
	public function testTheLeafStillDeclaresItsOwnScriptWhereTheDescriptorAcceptsOne(string $listenerClass, string $leafId): void {
		$event = new RegisterLeafProvidersEvent();
		(new $listenerClass($this->l10n(), $this->logger()))->handle($event);

		$this->assertCount(1, $event->getLeaves(), $this->swallowed());

		$descriptor = $event->getLeaves()[0]['descriptor'];
		$this->assertSame($leafId, $descriptor->getId(), $this->swallowed());
		$this->assertSame(
			LeafDescriptor::LOADS_VIA_OWN_SCRIPT,
			$descriptor->getLoadStrategy(),
			$this->swallowed()
		);
	}//end testTheLeafStillDeclaresItsOwnScriptWhereTheDescriptorAcceptsOne()

	/**
	 * 🔴 An OpenRegister without the load strategy still gets the approval-chain leaf.
	 *
	 * Degrading to "registers, and says nothing about how it loads" is the whole
	 * point. Degrading to "does not register" is the bug: the paraferen tab and
	 * the approval-chain timeline are absent on every host object, and nothing
	 * anywhere reports a fault.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
	 */
	public function testTheApprovalChainLeafSurvivesAnOpenRegisterWithoutTheLoadStrategy(): void {
		$listener = new class($this->l10n(), $this->logger()) extends RegisterApprovalChainLeafListener {
			/**
			 * Stand in for a pre-openregister#3956 descriptor.
			 *
			 * @return bool Always false.
			 */
			protected function descriptorSupportsLoadStrategy(): bool {
				return false;
			}//end descriptorSupportsLoadStrategy()
		};

		$event = new RegisterLeafProvidersEvent();
		$listener->handle($event);

		$this->assertCount(1, $event->getLeaves(), $this->swallowed());

		$descriptor = $event->getLeaves()[0]['descriptor'];
		$this->assertSame('decidiq-approval-chain', $descriptor->getId(), $this->swallowed());
		$this->assertNull(
			$descriptor->getLoadStrategy(),
			'A listener that declined to declare a load strategy must not have declared one anyway.'
		);
	}//end testTheApprovalChainLeafSurvivesAnOpenRegisterWithoutTheLoadStrategy()

	/**
	 * 🔴 An OpenRegister without the load strategy still gets the decisions leaf.
	 *
	 * Written out per listener rather than driven from the data provider: PHP
	 * cannot extend a class named by a variable, and the anonymous subclass is
	 * the only seam that does not require two versions of LeafDescriptor to be
	 * loaded at once.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
	 */
	public function testTheDecisionsLeafSurvivesAnOpenRegisterWithoutTheLoadStrategy(): void {
		$listener = new class($this->l10n(), $this->logger()) extends RegisterDecisionsLeafListener {
			/**
			 * Stand in for a pre-openregister#3956 descriptor.
			 *
			 * @return bool Always false.
			 */
			protected function descriptorSupportsLoadStrategy(): bool {
				return false;
			}//end descriptorSupportsLoadStrategy()
		};

		$event = new RegisterLeafProvidersEvent();
		$listener->handle($event);

		$this->assertCount(1, $event->getLeaves(), $this->swallowed());

		$descriptor = $event->getLeaves()[0]['descriptor'];
		$this->assertSame('decidesk-decisions', $descriptor->getId(), $this->swallowed());
		$this->assertNull(
			$descriptor->getLoadStrategy(),
			'A listener that declined to declare a load strategy must not have declared one anyway.'
		);
	}//end testTheDecisionsLeafSurvivesAnOpenRegisterWithoutTheLoadStrategy()

	/**
	 * Run the probe fixture for one LeafDescriptor shape and read its report.
	 *
	 * The two tests above drive the seam by overriding it, which says what
	 * `handle()` does with an answer but nothing about whether the answer is
	 * right. This runs the listeners for real, in a process holding the
	 * descriptor version named by `$mode`, because one process can hold only one
	 * version of a class and this one already holds the current stub.
	 *
	 * @param string $mode One of `old`, `partial`, `new`.
	 *
	 * @return array{exit: int, stdout: string, stderr: string, report: array<string, mixed>|null} The run.
	 */
	private function runProbe(string $mode): array {
		$probe = __DIR__ . '/../../fixtures/leaf-load-strategy-probe.php';
		$this->assertFileExists($probe, 'The leaf load-strategy probe must exist, or these tests assert nothing.');

		$pipes = [];
		$process = proc_open(
			[PHP_BINARY, $probe, $mode],
			[
				1 => ['pipe', 'w'],
				2 => ['pipe', 'w'],
			],
			$pipes
		);

		$this->assertIsResource($process, 'The probe could not be started.');

		$stdout = (string) stream_get_contents($pipes[1]);
		$stderr = (string) stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exit = proc_close($process);

		$report = json_decode($stdout, true);

		return [
			'exit' => $exit,
			'stdout' => $stdout,
			'stderr' => $stderr,
			'report' => is_array($report) ? $report : null,
		];
	}//end runProbe()

	/**
	 * CONTROL. The probe can report a failure, so a pass from it means something.
	 *
	 * An unreadable fixture, a missing interpreter or a swallowed fatal would all
	 * make the runs below look uniform. Asking the probe for a mode it refuses
	 * proves the channel carries a non-zero exit and an empty report.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
	 */
	public function testTheProbeReportsAFailureWhenThereIsOne(): void {
		$run = $this->runProbe('not-a-descriptor-version');

		$this->assertSame(2, $run['exit'], 'The probe must refuse a mode it does not know.');
		$this->assertNull($run['report'], 'A refused run must produce no report: ' . $run['stdout']);
		$this->assertStringContainsString('usage:', $run['stderr']);
	}//end testTheProbeReportsAFailureWhenThereIsOne()

	/**
	 * The LeafDescriptor shapes that exist in the fleet, and what each must yield.
	 *
	 * @return array<string, array{0: string, 1: string|null}> Probe mode and expected load strategy.
	 */
	public static function descriptorShapes(): array {
		return [
			// The control: the version the rest of this suite runs against.
			'post-#3956, accepts the argument' => ['new', 'own-script'],
			// A backport that took the constants and not the parameter. `defined()`
			// answers yes; the constructor throws `Unknown named parameter
			// $loadStrategy`.
			'partial backport, constant only' => ['partial', null],
			// What an instance on an older OpenRegister actually has. Reading the
			// constant is `Error: Undefined constant ...LOADS_VIA_OWN_SCRIPT`.
			'pre-#3956, neither half' => ['old', null],
		];
	}//end descriptorShapes()

	/**
	 * 🔴 BOTH LEAVES REGISTER BESIDE EVERY LEAFDESCRIPTOR SHAPE IN THE FLEET.
	 *
	 * This is the assertion the bug would have reddened. Before the guard, `old`
	 * and `partial` both contributed 0 of 2 leaves while every check in decidiq
	 * stayed green, because the suite's own stub is the `new` shape and the
	 * listeners' `catch (Throwable)` turned the failure into a log line.
	 *
	 * The failure message carries whatever the listener swallowed, so a red here
	 * names the constant or the parameter rather than saying `actual size 0
	 * matches expected size 1`.
	 *
	 * @param string      $mode     The descriptor shape to run beside.
	 * @param string|null $expected The load strategy each leaf must then carry.
	 *
	 * @return void
	 *
	 * @dataProvider descriptorShapes
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
	 */
	public function testBothLeavesRegisterBesideEveryDescriptorShape(string $mode, ?string $expected): void {
		$run = $this->runProbe($mode);

		$this->assertNotNull(
			$run['report'],
			sprintf('The probe produced no report for mode "%s". stderr: %s', $mode, $run['stderr'])
		);

		$report = $run['report'];
		$swallowed = json_encode($report['leaves'], JSON_UNESCAPED_SLASHES);

		$this->assertSame(
			2,
			$report['registered'],
			sprintf(
				'Both leaves must register beside a "%s" LeafDescriptor. A leaf that does not register is '
					. 'absent from every host object with nothing reporting a fault. What the listeners '
					. 'swallowed: %s',
				$mode,
				$swallowed
			)
		);

		foreach (['decidiq-approval-chain', 'decidesk-decisions'] as $leafId) {
			$this->assertSame(1, $report['leaves'][$leafId]['count'], $swallowed);
			$this->assertSame($leafId, $report['leaves'][$leafId]['id'], $swallowed);
			$this->assertSame(
				$expected,
				$report['leaves'][$leafId]['loadStrategy'],
				sprintf(
					'Beside a "%s" LeafDescriptor the %s leaf must carry %s as its load strategy. %s',
					$mode,
					$leafId,
					var_export($expected, true),
					$swallowed
				)
			);
		}

		$this->assertSame(0, $run['exit'], $swallowed);
	}//end testBothLeavesRegisterBesideEveryDescriptorShape()
}//end class
