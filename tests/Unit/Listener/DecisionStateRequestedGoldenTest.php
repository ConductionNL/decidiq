<?php

/**
 * Golden runs of DecisionStateRequestedListener::handle().
 *
 * Pins, for every outcome the read rule can give, the state the event is left
 * in (handled, permitted, found, envelope) and every log line, in order: no
 * decision id, no actor, an unresolvable decision, a refused caller, an allowed
 * caller with and without an envelope, and a failing lookup. Pinned before the
 * listener was split into named steps.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude golden test of the decision-state read, no behaviour of its own
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Event\DecisionStateRequestedEvent;
use OCA\Decidiq\Listener\DecisionStateRequestedListener;
use OCA\Decidiq\Service\DecisionIntegrationAuthorizationGuard;
use OCA\Decidiq\Service\DecisionIntegrationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class DecisionStateRequestedGoldenTest extends TestCase {

	/**
	 * Everything the listener did, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $trace = [];

	/**
	 * The cases: decision id, actor, the guard's answer, the envelope (or 'throw').
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>|string|null, 4: array<string, mixed>}>
	 */
	public static function cases(): array {
		$envelope = ['status' => 'adopted', 'decisionId' => 'd-1'];
		return [
			'no decision id' => ['  ', 'alice', 'allowed', $envelope, ['handled' => true, 'permitted' => false, 'found' => false, 'envelope' => null, 'trace' => ['warning']]],
			'no actor' => ['d-1', ' ', 'allowed', $envelope, ['handled' => true, 'permitted' => false, 'found' => false, 'envelope' => null, 'trace' => ['warning']]],
			'unresolved decision' => ['d-1', 'alice', DecisionIntegrationAuthorizationGuard::READ_UNRESOLVED, $envelope, ['handled' => false, 'permitted' => false, 'found' => false, 'envelope' => null, 'trace' => ['guard', 'warning']]],
			'refused caller' => ['d-1', 'mallory', DecisionIntegrationAuthorizationGuard::READ_DENIED, $envelope, ['handled' => true, 'permitted' => false, 'found' => false, 'envelope' => null, 'trace' => ['guard', 'warning']]],
			'unknown answer is refused' => ['d-1', 'mallory', 'maybe', $envelope, ['handled' => true, 'permitted' => false, 'found' => false, 'envelope' => null, 'trace' => ['guard', 'warning']]],
			'allowed and found' => [' d-1 ', ' alice ', DecisionIntegrationAuthorizationGuard::READ_ALLOWED, $envelope, ['handled' => true, 'permitted' => true, 'found' => true, 'envelope' => $envelope, 'trace' => ['guard', 'envelope', 'debug']]],
			'allowed and missing' => ['d-1', 'alice', DecisionIntegrationAuthorizationGuard::READ_ALLOWED, null, ['handled' => true, 'permitted' => true, 'found' => false, 'envelope' => null, 'trace' => ['guard', 'envelope']]],
			'failing lookup' => ['d-1', 'alice', DecisionIntegrationAuthorizationGuard::READ_ALLOWED, 'throw', ['handled' => false, 'permitted' => true, 'found' => false, 'envelope' => null, 'trace' => ['guard', 'envelope', 'error']]],
		];
	}//end cases()

	/**
	 * The event's state and the trace match each case.
	 *
	 * @param string                           $decisionId The decision asked for.
	 * @param string                           $actorId    The asking identity.
	 * @param string                           $access     What the read rule answers.
	 * @param array<string, mixed>|string|null $envelope   The envelope, or 'throw'.
	 * @param array<string, mixed>             $expected   The expected state and trace kinds.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testTheReadOutcomeIsUnchanged(string $decisionId, string $actorId, string $access, array|string|null $envelope, array $expected): void {
		$event = new DecisionStateRequestedEvent('procest', $decisionId, $actorId);
		$this->listener($access, $envelope)->handle($event);

		$actual = [
			'handled' => $event->isHandled(),
			'permitted' => $event->isPermitted(),
			'found' => $event->isFound(),
			'envelope' => $event->getEnvelope(),
			'trace' => array_map(static fn (array $entry): string => (string)array_key_first($entry), $this->trace),
		];
		self::assertSame($expected, $actual);
		foreach ($this->trace as $entry) {
			if (isset($entry['guard']) === true) {
				self::assertSame(['decisionId' => trim($decisionId), 'callerUid' => trim($actorId)], $entry['guard']);
			}

			if (isset($entry['context']) === true) {
				self::assertSame('procest', $entry['context']['sourceApp']);
			}
		}
	}//end testTheReadOutcomeIsUnchanged()

	/**
	 * The log messages and contexts, exactly.
	 *
	 * @return void
	 */
	public function testTheLogLinesAreUnchanged(): void {
		$lines = [];
		foreach (self::cases() as $name => [$decisionId, $actorId, $access, $envelope]) {
			$this->trace = [];
			$this->listener($access, $envelope)->handle(new DecisionStateRequestedEvent('procest', $decisionId, $actorId));
			$lines[$name] = array_values(array_filter($this->trace, static fn (array $entry): bool => isset($entry['context'])));
		}

		self::assertSame(
			[
				'no decision id' => [['warning' => 'Decidiq: refusing a decision-state read with no decision id or no actor', 'context' => ['sourceApp' => 'procest', 'decisionId' => '', 'hasActor' => true]]],
				'no actor' => [['warning' => 'Decidiq: refusing a decision-state read with no decision id or no actor', 'context' => ['sourceApp' => 'procest', 'decisionId' => 'd-1', 'hasActor' => false]]],
				'unresolved decision' => [['warning' => 'Decidiq: could not resolve a Decision for a state read; leaving it unanswered', 'context' => ['sourceApp' => 'procest', 'decisionId' => 'd-1', 'actor' => 'alice']]],
				'refused caller' => [['warning' => 'Decidiq: refused a decision-state read', 'context' => ['sourceApp' => 'procest', 'decisionId' => 'd-1', 'actor' => 'mallory']]],
				'unknown answer is refused' => [['warning' => 'Decidiq: refused a decision-state read', 'context' => ['sourceApp' => 'procest', 'decisionId' => 'd-1', 'actor' => 'mallory']]],
				'allowed and found' => [['debug' => 'Decidiq: answered a decision-state read', 'context' => ['sourceApp' => 'procest', 'decisionId' => 'd-1', 'status' => 'adopted']]],
				'allowed and missing' => [],
				'failing lookup' => [['error' => 'Decidiq: DecisionStateRequestedListener failed', 'context' => ['sourceApp' => 'procest', 'decisionId' => 'd-1', 'exception' => 'store down']]],
			],
			$lines
		);
	}//end testTheLogLinesAreUnchanged()

	/**
	 * The listener over recording doubles.
	 *
	 * @param string                           $access   What the read rule answers.
	 * @param array<string, mixed>|string|null $envelope The envelope, or 'throw'.
	 *
	 * @return DecisionStateRequestedListener The listener.
	 */
	private function listener(string $access, array|string|null $envelope): DecisionStateRequestedListener {
		$guard = $this->createMock(DecisionIntegrationAuthorizationGuard::class);
		$guard->method('resolveOutcomeReadAccess')->willReturnCallback(
			function (string $decisionId, string $callerUid) use ($access): string {
				$this->trace[] = ['guard' => ['decisionId' => $decisionId, 'callerUid' => $callerUid]];
				return $access;
			}
		);
		$integration = $this->createMock(DecisionIntegrationService::class);
		$integration->method('getOutcomeEnvelope')->willReturnCallback(
			function (string $decisionId) use ($envelope): ?array {
				$this->trace[] = ['envelope' => $decisionId];
				if ($envelope === 'throw') {
					throw new RuntimeException('store down');
				}

				return $envelope;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->trace[] = [$level => $message, 'context' => $context];
				}
			);
		}

		return new DecisionStateRequestedListener($integration, $guard, $logger);
	}//end listener()
}//end class
