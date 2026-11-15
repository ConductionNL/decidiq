<?php

/**
 * Golden runs of the three requested-event listeners' outcome paths.
 *
 * For a governance body, an approval route (template path) and a decision
 * request, pins the event's result slots, the service arguments, every log
 * line and every announced event, on success and on failure. Pinned before
 * the listeners' handle() methods were split into named steps.
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
 * @spec exclude golden test of the requested-event listeners, no behaviour of its own
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Event\ApprovalRouteRequestedEvent;
use OCA\Decidiq\Event\DecisionRequestedEvent;
use OCA\Decidiq\Event\GovernanceBodyCreatedEvent;
use OCA\Decidiq\Event\GovernanceBodyRequestedEvent;
use OCA\Decidiq\Listener\ApprovalRouteRequestedListener;
use OCA\Decidiq\Listener\DecisionRequestedListener;
use OCA\Decidiq\Listener\GovernanceBodyRequestedListener;
use OCA\Decidiq\Service\ApprovalRouteCommandService;
use OCA\Decidiq\Service\ApprovalRouteService;
use OCA\Decidiq\Service\DecisionIntegrationService;
use OCA\Decidiq\Service\GovernanceBodyCommandService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class RequestedListenersGoldenTest extends TestCase {

	/**
	 * Everything the listener did, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $trace = [];

	/**
	 * A governance body request: upserted, answered, logged and announced; or logged and left unhandled.
	 *
	 * @return void
	 */
	public function testGovernanceBody(): void {
		foreach ([true, false] as $succeeds) {
			$this->trace = [];
			$service = $this->createMock(GovernanceBodyCommandService::class);
			$service->method('upsert')->willReturnCallback(
				function (string $sourceApp, string $externalReference, array $body, array $members) use ($succeeds): array {
					$this->trace[] = ['upsert', $sourceApp, $externalReference, $body, $members];
					if ($succeeds === false) {
						throw new RuntimeException('down');
					}

					return ['id' => 'body-1', 'created' => true];
				}
			);
			$dispatcher = $this->createMock(IEventDispatcher::class);
			$dispatcher->method('dispatchTyped')->willReturnCallback(
				function (GovernanceBodyCreatedEvent $created): void {
					$this->trace[] = ['announce', $created->getGovernanceBodyId(), $created->getSourceApp(), $created->getExternalReference(), $created->isCreated(), $created->getCorrelationId()];
				}
			);
			$event = new GovernanceBodyRequestedEvent('procest', 'ext-1', 'Raad', 'council', 'gemeente', true, ['x' => 1], [['uid' => 'a']], 'alice', 'corr-1');
			(new GovernanceBodyRequestedListener($service, $dispatcher, $this->logger()))->handle($event);
			$this->trace[] = ['event', $event->isHandled(), $event->getGovernanceBodyId(), $event->isCreated()];
			$runs[$succeeds ? 'ok' : 'fail'] = $this->trace;
		}

		self::assertSame(
			[
				['upsert', 'procest', 'ext-1', ['x' => 1, 'name' => 'Raad', 'bodyType' => 'council', 'domain' => 'gemeente', 'active' => true], [['uid' => 'a']]],
				['info', 'Decidiq: handled GovernanceBodyRequestedEvent', ['sourceApp' => 'procest', 'externalReference' => 'ext-1', 'governanceBodyId' => 'body-1', 'created' => true]],
				['announce', 'body-1', 'procest', 'ext-1', true, 'corr-1'],
				['event', true, 'body-1', true],
			],
			$runs['ok']
		);
		self::assertSame(['error', 'Decidiq: GovernanceBodyRequestedEvent not handled', ['sourceApp', 'externalReference', 'exception']], [$runs['fail'][1][0], $runs['fail'][1][1], array_keys($runs['fail'][1][2])]);
		self::assertSame(['event', false, '', false], $runs['fail'][2]);
		self::assertCount(3, $runs['fail']);
	}//end testGovernanceBody()

	/**
	 * An approval route request on the template path: held, answered and logged; or logged and left unhandled.
	 *
	 * @return void
	 */
	public function testApprovalRoute(): void {
		foreach ([true, false] as $succeeds) {
			$this->trace = [];
			$service = $this->createMock(ApprovalRouteCommandService::class);
			$service->method('holdRoute')->willReturnCallback(
				function (string $sourceApp, string $externalReference, array $template, string $subject = '', string $subjectSchema = '') use ($succeeds): array {
					$this->trace[] = ['holdRoute', $sourceApp, $externalReference, $template, $subject, $subjectSchema];
					if ($succeeds === false) {
						throw new RuntimeException('down');
					}

					return ['id' => 'route-1', 'created' => false, 'stageCount' => 3];
				}
			);
			$event = new ApprovalRouteRequestedEvent('dossiq', 'ext-2', 'Paraaf', [['role' => 'manager']], 'document', 'desc', true, 'doc-1', 'document');
			(new ApprovalRouteRequestedListener($service, $this->logger(), $this->createMock(ApprovalRouteService::class)))->handle($event);
			$this->trace[] = ['event', $event->isHandled(), $event->getRouteId(), $event->isCreated(), $event->getStageCount()];
			$runs[$succeeds ? 'ok' : 'fail'] = $this->trace;
		}

		self::assertSame(
			[
				['holdRoute', 'dossiq', 'ext-2', ['name' => 'Paraaf', 'steps' => [['role' => 'manager']], 'subjectType' => 'document', 'description' => 'desc', 'isDefault' => true], 'doc-1', 'document'],
				['info', 'Decidiq: handled ApprovalRouteRequestedEvent', ['sourceApp' => 'dossiq', 'externalReference' => 'ext-2', 'routeId' => 'route-1', 'created' => false, 'stageCount' => 3]],
				['event', true, 'route-1', false, 3],
			],
			$runs['ok']
		);
		self::assertSame(['error', 'Decidiq: ApprovalRouteRequestedEvent not handled'], [$runs['fail'][1][0], $runs['fail'][1][1]]);
		self::assertSame(['event', false, '', false, 0], $runs['fail'][2]);
	}//end testApprovalRoute()

	/**
	 * A decision request: created with and without an id, refused with and without a message, and failing.
	 *
	 * @return void
	 */
	public function testDecision(): void {
		$answers = [
			'with id' => ['success' => true, 'decisionId' => 'dec-1', 'created' => true],
			'without id' => ['success' => true],
			'refused' => ['success' => false, 'message' => 'no'],
			'refused silently' => ['success' => false],
			'throws' => null,
		];
		$runs = [];
		foreach ($answers as $name => $answer) {
			$this->trace = [];
			$service = $this->createMock(DecisionIntegrationService::class);
			$service->method('createDecision')->willReturnCallback(
				function (array $data, ?string $actor = null) use ($answer): array {
					$this->trace[] = ['create', $data['decisionType'] ?? null, $actor];
					if ($answer === null) {
						throw new RuntimeException('down');
					}

					return $answer;
				}
			);
			$event = new DecisionRequestedEvent(sourceApp: 'procest', subjectRegister: 'procest', subjectSchema: 'case', subjectId: 'case-1', decisionType: 'advice', actorId: 'alice');
			(new DecisionRequestedListener($service, $this->logger()))->handle($event);
			$this->trace[] = ['event', $event->isHandled(), $event->getDecisionId()];
			$runs[$name] = $this->trace;
		}

		self::assertSame(
			[
				'with id' => [['create', 'advice', 'alice'], ['info', 'Decidiq: handled DecisionRequestedEvent', ['sourceApp' => 'procest', 'subjectId' => 'case-1', 'decisionId' => 'dec-1', 'created' => true]], ['event', true, 'dec-1']],
				'without id' => [['create', 'advice', 'alice'], ['info', 'Decidiq: handled DecisionRequestedEvent', ['sourceApp' => 'procest', 'subjectId' => 'case-1', 'decisionId' => '', 'created' => null]], ['event', true, null]],
				'refused' => [['create', 'advice', 'alice'], ['warning', 'Decidiq: DecisionRequestedEvent not handled', ['sourceApp' => 'procest', 'subjectId' => 'case-1', 'message' => 'no']], ['event', false, null]],
				'refused silently' => [['create', 'advice', 'alice'], ['warning', 'Decidiq: DecisionRequestedEvent not handled', ['sourceApp' => 'procest', 'subjectId' => 'case-1', 'message' => 'unknown']], ['event', false, null]],
				'throws' => [['create', 'advice', 'alice'], ['error', 'Decidiq: DecisionRequestedListener failed', ['sourceApp' => 'procest', 'subjectId' => 'case-1', 'exception' => 'down']], ['event', false, null]],
			],
			$runs
		);
	}//end testDecision()

	/**
	 * A recording logger.
	 *
	 * @return LoggerInterface The logger.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->trace[] = [$level, $message, $context];
				}
			);
		}

		return $logger;
	}//end logger()
}//end class
