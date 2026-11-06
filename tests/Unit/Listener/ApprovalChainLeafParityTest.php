<?php

/**
 * Cross-layer parity test for the `decidiq-approval-chain` leaf.
 *
 * The two halves of one leaf registration live in different languages and
 * different build outputs, so nothing in either compiler can notice when they
 * disagree — and a leaf declared on one half only renders in one
 * place and is invisible in another, with nothing to say so. This reads the JS source and compares each of its
 * declarations to the value the PHP listener contributes.
 *
 * Reading source text from a unit test is unusual and is the point: the
 * assertion has to hold over the two DECLARATIONS, and there is no runtime in
 * this process where both exist.
 *
 * It also covers two fields gate-24's static reader silently skips on this repo:
 * `requiredApp` (written `Application::APP_ID`, which the checker cannot resolve)
 * and `label` (written `$this->l10n->t(self::LABEL_SOURCE)`, likewise). The gate
 * treats an unresolvable value as "not compared, never a failure", so those two
 * fields are unguarded by it — here they are compared for real, off the built
 * descriptor.
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
 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\AppInfo\Registrar\IntegrationLeafRegistrar;
use OCA\Decidiq\Listener\RegisterApprovalChainLeafListener;
use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Both registration halves describe the SAME leaf.
 *
 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
 */
class ApprovalChainLeafParityTest extends TestCase {

	/**
	 * Path to the JS half, relative to this file.
	 *
	 * @var string
	 */
	private const JS_HALF = '/../../../src/integrations/registerApprovalChainLeaf.js';

	/**
	 * The JS half's source text.
	 *
	 * Asserted readable rather than assumed: a moved or renamed file would
	 * otherwise turn every comparison below into a comparison against an empty
	 * string, and an empty string matches nothing — the test would fail for the
	 * wrong reason, or (if a matcher were written loosely) pass over nothing.
	 *
	 * @return string The JS source.
	 */
	private function jsSource(): string {
		$path = __DIR__ . self::JS_HALF;
		$this->assertFileExists($path, 'The JS half of the decidiq-approval-chain leaf must exist.');

		$source = file_get_contents($path);
		$this->assertIsString($source);
		$this->assertNotSame('', $source);

		return $source;
	}//end jsSource()

	/**
	 * The descriptor the PHP half contributes.
	 *
	 * @return LeafDescriptor The contributed descriptor.
	 */
	private function serverDescriptor(): LeafDescriptor {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		// The listener catches Throwable so one bad leaf cannot take the whole
		// catalogue down, and a plain mock logger is then also what hides the
		// reason. Recorded and read back as the failure message below, because
		// `actual size 0 matches expected size 1` told planninq nothing for three
		// days while the reason sat in a warning nobody captured.
		$swallowed = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message) use (&$swallowed): void {
				$swallowed[] = (string) $message;
			}
		);

		$event = new RegisterLeafProvidersEvent();
		(new RegisterApprovalChainLeafListener($l10n, $logger))->handle($event);

		$leaves = $event->getLeaves();
		$this->assertCount(
			1,
			$leaves,
			$swallowed === []
				? 'The listener logged no warning, so it did not throw: the leaf was never contributed.'
				: 'The listener swallowed: ' . implode(' | ', $swallowed)
		);

		return $leaves[0]['descriptor'];
	}//end serverDescriptor()

	/**
	 * Read one single-quoted scalar member of the JS descriptor object.
	 *
	 * @param string $source The JS source.
	 * @param string $key The member name.
	 *
	 * @return string The declared value.
	 */
	private function jsScalar(string $source, string $key): string {
		$matched = preg_match('/\n\t' . preg_quote($key, '/') . ":\\s*'([^']*)',/", $source, $matches);
		$this->assertSame(
			1,
			$matched,
			sprintf('The JS half must declare `%s` explicitly on the descriptor object.', $key)
		);

		return $matches[1];
	}//end jsScalar()

	/**
	 * The two halves declare the same identity, metadata and render mode.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
	 */
	public function testBothHalvesDeclareTheSameLeaf(): void {
		$source = $this->jsSource();
		$descriptor = $this->serverDescriptor();

		// id — declared through the exported JS constant, not inline.
		$matchedId = preg_match(
			"/export const APPROVAL_CHAIN_INTEGRATION_ID = '([^']*)'/",
			$source,
			$idMatch
		);
		$this->assertSame(1, $matchedId, 'The JS half must export APPROVAL_CHAIN_INTEGRATION_ID.');
		$this->assertSame($idMatch[1], $descriptor->getId());
		$this->assertSame(RegisterApprovalChainLeafListener::LEAF_ID, $descriptor->getId());

		// label — the l10n SOURCE key each side hands to its own translator.
		$matchedLabel = preg_match("/\n\tlabel: t\('decidiq', '([^']*)'\),/", $source, $labelMatch);
		$this->assertSame(
			1,
			$matchedLabel,
			"The JS half must declare `label: t('decidiq', '…')` on the descriptor object."
		);
		$this->assertSame($labelMatch[1], $descriptor->getLabel());

		$this->assertSame($this->jsScalar($source, 'icon'), $descriptor->getIcon());
		$this->assertSame($this->jsScalar($source, 'group'), $descriptor->getGroup());
		$this->assertSame($this->jsScalar($source, 'requiredApp'), $descriptor->getRequiredApp());
		$this->assertSame(
			$this->jsScalar($source, 'referenceType'),
			$descriptor->getReferenceType()
		);
		$this->assertSame($this->jsScalar($source, 'renderMode'), $descriptor->getRenderMode());
	}//end testBothHalvesDeclareTheSameLeaf()

	/**
	 * Both halves declare the SAME surface set, explicitly, in the same order.
	 *
	 * A half that declares its surfaces by OMISSION gives this comparison nothing
	 * to read, which is how hermiq's two halves drifted apart unnoticed — so the
	 * presence of the explicit key is asserted before its contents.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
	 */
	public function testBothHalvesDeclareTheSameSurfaceSet(): void {
		$source = $this->jsSource();

		$this->assertMatchesRegularExpression(
			'/\n\tsurfaces: SURFACES,/',
			$source,
			'The JS half must pass an EXPLICIT surfaces list on the descriptor object.'
		);

		$matched = preg_match('/const SURFACES = \[([^\]]*)\]/', $source, $matches);
		$this->assertSame(1, $matched, 'The JS half must declare a SURFACES list.');

		$surfaces = [];
		foreach (explode(',', $matches[1]) as $entry) {
			$entry = trim($entry, " \t\n'\"");
			if ($entry !== '') {
				$surfaces[] = $entry;
			}
		}

		$this->assertSame(RegisterApprovalChainLeafListener::SURFACES, $surfaces);
		$this->assertSame($surfaces, $this->serverDescriptor()->getSurfaces());
	}//end testBothHalvesDeclareTheSameSurfaceSet()

	/**
	 * The JS half really does ship the mount PAIR the declared renderMode needs.
	 *
	 * `renderMode: 'mount'` with only one of `mount` / `unmount` is an incomplete
	 * render pair (ADR-019 AD-11/AD-13, ADR-066 decision 7): it renders once and
	 * then leaks its Vue app on every teardown. The server half advertises the
	 * mode, so this asserts the JS half can actually honour it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
	 */
	public function testTheMountModeRenderPairIsComplete(): void {
		$source = $this->jsSource();

		$this->assertSame(
			LeafDescriptor::RENDER_MODE_MOUNT,
			$this->serverDescriptor()->getRenderMode()
		);
		$this->assertMatchesRegularExpression('/\n\tmount,/', $source);
		$this->assertMatchesRegularExpression('/\n\tunmount,/', $source);
	}//end testTheMountModeRenderPairIsComplete()

	/**
	 * The PHP half is actually SUBSCRIBED, and the JS half is actually loaded.
	 *
	 * A listener class that exists and is never registered contributes nothing,
	 * and a JS descriptor that no bundle imports registers nothing. Both halves
	 * can be perfectly correct and perfectly invisible; this asserts each one is
	 * wired to the thing that runs it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
	 */
	public function testBothHalvesAreWiredToSomethingThatRunsThem(): void {
		$subscriptions = [];

		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')
			->willReturnCallback(
				static function (string $event, string $listener) use (&$subscriptions): void {
					$subscriptions[$event][] = $listener;
				}
			);

		(new IntegrationLeafRegistrar())->register($context);

		$this->assertContains(
			RegisterApprovalChainLeafListener::class,
			($subscriptions[RegisterLeafProvidersEvent::class] ?? []),
			'The approval-chain listener must be subscribed to the leaf collect event, '
				. 'or the server half contributes nothing however correct it is.'
		);

		// The JS half reaches the page through decidiq's own init bundle, which
		// is the `own-script` strategy both halves declare. An import that is
		// not there is a leaf registered nowhere.
		$init = file_get_contents(__DIR__ . '/../../../src/integration-init.js');
		$this->assertIsString($init);
		$this->assertStringContainsString('registerApprovalChainLeaf', $init);
		$this->assertMatchesRegularExpression('/\nregisterApprovalChainLeaf\(\)/', $init);
	}//end testBothHalvesAreWiredToSomethingThatRunsThem()

	/**
	 * Both halves say the same thing about how the bundle reaches the page.
	 *
	 * decidiq declared `own-script` in #1345. It ships no `decidiq-leaves.js`,
	 * and openregister#3954 once read that absence as proof the surface was dark
	 * and refused the registration. Declaring the strategy on BOTH halves is what
	 * stops the next reader deriving the wrong answer from the filesystem.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
	 */
	public function testBothHalvesDeclareTheOwnScriptLoadStrategy(): void {
		$source = $this->jsSource();

		$this->assertSame('own-script', $this->jsScalar($source, 'loadStrategy'));
		$this->assertSame('own-script', $this->serverDescriptor()->getLoadStrategy());
	}//end testBothHalvesDeclareTheOwnScriptLoadStrategy()
}//end class
