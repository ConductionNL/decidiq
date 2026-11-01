<?php
/**
 * Golden test for the two render-leaf listeners.
 *
 * Pins, per listener, the descriptor it contributes with and without the
 * own-script load strategy, the warning it logs when the registration is
 * refused, and that it ignores every other event. The expected values live in
 * tests/fixtures/render-leaf-listener-golden.json; run with
 * RECORD_RENDER_LEAF_GOLDEN=1 to write them, then run again without it.
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
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for the render-leaf listeners.
 */
class RenderLeafListenerGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/render-leaf-listener-golden.json';

	/**
	 * Warnings the listener logged, with whether the context carried the thrown exception.
	 *
	 * @var array<int, array{message: string, exceptionIsThrown: bool}>
	 */
	private array $warnings = [];

	/**
	 * The exception the refusing event throws.
	 *
	 * @var RuntimeException|null
	 */
	private ?RuntimeException $thrown = null;

	/**
	 * The two listeners.
	 *
	 * @return array<string, array{0: class-string}> Listener class per case.
	 */
	public static function listeners(): array {
		return [
			'approval chain' => [RegisterApprovalChainLeafListener::class],
			'decisions'      => [RegisterDecisionsLeafListener::class],
		];
	}//end listeners()

	/**
	 * Build a listener with a marking l10n and a recording logger.
	 *
	 * @param class-string $listenerClass The listener class.
	 * @param bool|null    $loadStrategy  Forced answer of the capability check, null for the real one.
	 *
	 * @return object The listener.
	 */
	private function listener(string $listenerClass, ?bool $loadStrategy): object {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'T:' . $text);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$this->warnings[] = [
					'message'           => (string) $message,
					'exceptionIsThrown' => ($context['exception'] ?? null) === $this->thrown,
				];
			}
		);

		if ($loadStrategy === null) {
			return new $listenerClass($l10n, $logger);
		}

		$probe = $listenerClass === RegisterDecisionsLeafListener::class
			? new class($l10n, $logger) extends RegisterDecisionsLeafListener {
				/**
				 * Forced capability answer.
				 *
				 * @var boolean
				 */
				public bool $answer = false;

				/**
				 * Return the forced answer.
				 *
				 * @return bool The answer.
				 */
				protected function descriptorSupportsLoadStrategy(): bool {
					return $this->answer;
				}//end descriptorSupportsLoadStrategy()
			}
			: new class($l10n, $logger) extends RegisterApprovalChainLeafListener {
				/**
				 * Forced capability answer.
				 *
				 * @var boolean
				 */
				public bool $answer = false;

				/**
				 * Return the forced answer.
				 *
				 * @return bool The answer.
				 */
				protected function descriptorSupportsLoadStrategy(): bool {
					return $this->answer;
				}//end descriptorSupportsLoadStrategy()
			};
		$probe->answer = $loadStrategy;
		return $probe;
	}//end listener()

	/**
	 * Run one listener over the four cases and describe what it did.
	 *
	 * @param class-string $listenerClass The listener class.
	 *
	 * @return array<string, mixed> The observed behaviour.
	 */
	private function observe(string $listenerClass): array {
		$observed = [];
		foreach (['real' => null, 'withStrategy' => true, 'withoutStrategy' => false] as $case => $answer) {
			$event = new RegisterLeafProvidersEvent();
			$this->listener($listenerClass, $answer)->handle($event);
			$observed[$case] = array_map(
				static fn (array $leaf): array => [
					'descriptor'         => $leaf['descriptor']->toArray(),
					'loadStrategy'       => $leaf['descriptor']->getLoadStrategy(),
					'referenceType'      => $leaf['descriptor']->getReferenceType(),
					'requiresPermission' => $leaf['descriptor']->requiresPermission(),
					'provider'           => $leaf['provider'],
				],
				$event->getLeaves()
			);
		}

		$this->thrown = new RuntimeException('registration refused');
		$refusing     = new class($this->thrown) extends RegisterLeafProvidersEvent {
			/**
			 * Remember the exception to throw.
			 *
			 * @param RuntimeException $error The exception.
			 */
			public function __construct(private readonly RuntimeException $error) {
			}//end __construct()

			/**
			 * Refuse every leaf.
			 *
			 * @param LeafDescriptor           $descriptor The descriptor.
			 * @param IntegrationProvider|null $provider   The provider.
			 *
			 * @return void
			 *
			 * @throws RuntimeException Always.
			 */
			public function registerLeaf(LeafDescriptor $descriptor, ?IntegrationProvider $provider = null): void {
				throw $this->error;
			}//end registerLeaf()
		};
		$this->listener($listenerClass, null)->handle($refusing);
		$observed['refusedWarnings'] = $this->warnings;

		$this->warnings = [];
		$this->listener($listenerClass, null)->handle(new Event());
		$observed['otherEventWarnings'] = $this->warnings;
		$this->warnings = [];

		return $observed;
	}//end observe()

	/**
	 * Each listener behaves as recorded.
	 *
	 * @param class-string $listenerClass The listener class.
	 *
	 * @return void
	 *
	 * @dataProvider listeners
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
	 */
	public function testTheListenerBehavesAsRecorded(string $listenerClass): void {
		$observed = $this->observe($listenerClass);
		$golden   = is_file(self::GOLDEN) === true ? json_decode((string) file_get_contents(self::GOLDEN), true) : [];

		if (getenv('RECORD_RENDER_LEAF_GOLDEN') === '1') {
			$golden[$listenerClass] = $observed;
			ksort($golden);
			file_put_contents(self::GOLDEN, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($listenerClass, $golden, 'No golden recorded for this listener.');
		$this->assertSame($golden[$listenerClass], json_decode((string) json_encode($observed), true));
	}//end testTheListenerBehavesAsRecorded()
}//end class
