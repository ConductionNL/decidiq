<?php

/**
 * Unit tests for RegisterDecisionsLeafListener (ADR-066 leaf registration).
 *
 * Asserts that decidesk contributes exactly one `decidesk-decisions` leaf,
 * render-only (null provider), through OpenRegister's `RegisterLeafProvidersEvent`
 * — i.e. that the leaf is discoverable SERVER-side, without evaluating any of
 * decidesk's JavaScript.
 *
 * This is the half that has no rendered state. The leaf renders today from its JS
 * half alone, so no browser test, no screenshot and no e2e run can observe the
 * server half being absent — which is exactly why it was absent for as long as it
 * was. The assertion has to live here.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Listener
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

namespace OCA\Decidesk\Tests\Unit\Listener;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\AppInfo\Registrar\IntegrationLeafRegistrar;
use OCA\Decidesk\Listener\RegisterDecisionsLeafListener;
use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for RegisterDecisionsLeafListener.
 *
 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
 */
class RegisterDecisionsLeafListenerTest extends TestCase {

	/**
	 * Build the listener with an identity l10n and a null logger.
	 *
	 * The identity `t()` is deliberate: what both halves have to agree on is the
	 * l10n SOURCE key, because that is what each side hands to its own translator.
	 * Comparing translated output would compare the catalogue, not the leaf.
	 *
	 * @return RegisterDecisionsLeafListener The listener under test.
	 */
	private function listener(): RegisterDecisionsLeafListener {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new RegisterDecisionsLeafListener($l10n, $this->createMock(LoggerInterface::class));
	}//end listener()

	/**
	 * Run the listener and return the single descriptor it contributed.
	 *
	 * @return array{descriptor: LeafDescriptor, provider: mixed} The contributed leaf.
	 */
	private function contributedLeaf(): array {
		$event = new RegisterLeafProvidersEvent();
		$this->listener()->handle($event);

		$leaves = $event->getLeaves();
		$this->assertCount(
			1,
			$leaves,
			'decidesk must contribute exactly one leaf to the OpenRegister catalogue.'
		);

		return $leaves[0];
	}//end contributedLeaf()

	/**
	 * The listener contributes the decidesk-decisions leaf, render-only.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
	 */
	public function testRegistersDecisionsLeaf(): void {
		$leaf = $this->contributedLeaf();

		/** @var LeafDescriptor $descriptor */
		$descriptor = $leaf['descriptor'];
		$this->assertInstanceOf(LeafDescriptor::class, $descriptor);
		$this->assertSame('decidesk-decisions', $descriptor->getId());
		$this->assertSame('decidesk', $descriptor->getRequiredApp());
		$this->assertTrue($descriptor->hasKind(LeafDescriptor::KIND_RENDER_SURFACE));

		// Render-and-read boundary (ADR-066 decision 2): this leaf serves no
		// app-local store and runs no agent, so it declares neither of the other
		// two kinds. Asserted rather than left unstated — the kinds set is what
		// tells a consumer what the leaf is allowed to do.
		$this->assertFalse($descriptor->hasKind(LeafDescriptor::KIND_DATA_PROVIDER));
		$this->assertFalse($descriptor->hasKind(LeafDescriptor::KIND_AGENT_RUNNER));
		$this->assertSame([LeafDescriptor::KIND_RENDER_SURFACE], $descriptor->getKinds());

		// Vue 3 leaf under a possibly-Vue-2.7 host: the JS half renders through a
		// mount/unmount DOM hand-off, so this half must say the same or the
		// surface blanks (openregister#2127, ADR-066 decision 7).
		$this->assertSame(LeafDescriptor::RENDER_MODE_MOUNT, $descriptor->getRenderMode());

		// Render-only: no data provider.
		$this->assertNull($leaf['provider']);
	}//end testRegistersDecisionsLeaf()

	/**
	 * The descriptor reaches the capability payload OpenRegister actually emits.
	 *
	 * `LeafRegistry::describeForCapabilities()` publishes `toArray()` plus a
	 * `usable` flag, and that payload IS the server-side discoverability this
	 * listener exists to provide. Asserting the row rather than only the getters
	 * pins the thing a consumer reads.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
	 */
	public function testTheDescriptorIsEnumerableThroughTheCapabilityRow(): void {
		/** @var LeafDescriptor $descriptor */
		$descriptor = $this->contributedLeaf()['descriptor'];

		$this->assertSame(
			[
				'id' => 'decidesk-decisions',
				'label' => 'Besluitvorming',
				'icon' => 'Gavel',
				'requiredApp' => 'decidesk',
				'group' => 'workflow',
				'surfaces' => ['user-dashboard', 'app-dashboard', 'detail-page', 'single-entity'],
				'kinds' => ['render-surface'],
				'renderMode' => 'mount',
			],
			$descriptor->toArray()
		);
	}//end testTheDescriptorIsEnumerableThroughTheCapabilityRow()

	/**
	 * Every declared surface is in OpenRegister's own vocabulary.
	 *
	 * A typo here would advertise a surface nothing ever renders, and neither
	 * compiler would notice.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
	 */
	public function testEverySurfaceIsInTheOpenRegisterVocabulary(): void {
		foreach (RegisterDecisionsLeafListener::SURFACES as $surface) {
			$this->assertContains($surface, LeafDescriptor::VALID_SURFACES);
		}
	}//end testEverySurfaceIsInTheOpenRegisterVocabulary()

	/**
	 * A non-matching event contributes nothing and does not throw.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
	 */
	public function testIgnoresNonMatchingEvent(): void {
		$this->expectNotToPerformAssertions();
		$this->listener()->handle(new Event());
	}//end testIgnoresNonMatchingEvent()

	/**
	 * The listener is actually SUBSCRIBED, not merely implemented.
	 *
	 * The three tests above all pass on a listener nothing ever calls — the exact
	 * shape of the orphan-auth defect class (gate-6): implemented, spec'd done,
	 * and never invoked. This asserts the wiring itself, so the face cannot stop
	 * being registered without a red test.
	 *
	 * `registerEventListener()` is called with NAMED arguments in the registrar,
	 * and a PHPUnit mock cannot observe those — it sees its own parameter
	 * defaults. So the assertion is made on the POSITIONAL values the mock does
	 * see, which is what `willReturnCallback` receives regardless of call style.
	 *
	 * The final two assertions are here because of what the red control showed:
	 * with the listener class DELETED, every other test in this file errored and
	 * this one still PASSED. `::class` is resolved by the compiler to a plain
	 * string and `registerEventListener()` only stores strings, so a subscription
	 * to a class that does not exist looks identical to a working one right up
	 * until OpenRegister dispatches the event and Nextcloud cannot build the
	 * listener. Naming the class is not the same as having it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
	 */
	public function testTheListenerIsSubscribedToTheLeafCollectEvent(): void {
		$subscriptions = [];

		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')
			->willReturnCallback(
				static function (string $event, string $listener) use (&$subscriptions): void {
					$subscriptions[$event] = $listener;
				}
			);

		(new IntegrationLeafRegistrar())->register($context);

		$this->assertArrayHasKey(
			RegisterLeafProvidersEvent::class,
			$subscriptions,
			'IntegrationLeafRegistrar must subscribe a listener to RegisterLeafProvidersEvent, '
				. 'or decidesk contributes no leaf and the JS registration is an ADR-066 orphan again.'
		);

		$subscribed = $subscriptions[RegisterLeafProvidersEvent::class];
		$this->assertSame(RegisterDecisionsLeafListener::class, $subscribed);

		$this->assertTrue(
			class_exists($subscribed),
			sprintf(
				'The subscribed listener "%s" must be a real, loadable class — registerEventListener() '
					. 'stores a plain string, so a subscription to a missing class is indistinguishable '
					. 'from a working one until the event is dispatched.',
				$subscribed
			)
		);
		$this->assertContains(
			IEventListener::class,
			class_implements($subscribed),
			'The subscribed listener must implement IEventListener, or Nextcloud will refuse it at dispatch.'
		);
	}//end testTheListenerIsSubscribedToTheLeafCollectEvent()

	/**
	 * The registrar is REACHED — `Application::register()` actually runs it.
	 *
	 * The test above proves the registrar subscribes the listener. It says nothing
	 * about whether anything ever calls the registrar, and a registrar with no
	 * caller registers exactly as much as no registrar at all. `Application` cannot
	 * be instantiated here (its constructor builds a Nextcloud app container), so
	 * the chain is closed by reading the composition root's own source through
	 * reflection rather than by executing it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
	 */
	public function testTheCompositionRootRunsTheLeafRegistrar(): void {
		$method = new ReflectionMethod(Application::class, 'register');

		$source = file($method->getFileName());
		$this->assertIsArray($source);

		$body = implode(
			'',
			array_slice(
				$source,
				($method->getStartLine() - 1),
				(($method->getEndLine() - $method->getStartLine()) + 1)
			)
		);

		// Positive control on the same instrument: a registrar known to be wired
		// must be found by it, so an assertion failure below means "not wired",
		// never "the reader read nothing".
		$this->assertStringContainsString('new PlatformIntegrationRegistrar()', $body);

		$this->assertStringContainsString(
			'new IntegrationLeafRegistrar()',
			$body,
			'Application::register() must run IntegrationLeafRegistrar, or the leaf listener is '
				. 'subscribed by a registrar nothing ever calls.'
		);
	}//end testTheCompositionRootRunsTheLeafRegistrar()
}//end class
