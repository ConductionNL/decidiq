<?php

/**
 * Golden test for MeetingService's lifecycle transitions.
 *
 * Runs transition() and availableActionsFor() for every action over every
 * lifecycle, with domain rules that allow or refuse, a chair-only gate that
 * applies or not, callers who are the chair, another member, a forged chair
 * id for another body, or nobody, every shape of the governance body link,
 * quorum required or not and met or not, meetings missing, unreadable or
 * throwing, saves that throw, cost results and failures on close, and the
 * activity publish failing. It records every collaborator call by parameter
 * name and in order, every log line, and what each call returns. Times are
 * masked. The expected values live in
 * tests/fixtures/meeting-transition-golden.json; run with
 * RECORD_MEETING_TRANSITION_GOLDEN=1 to write them, then run again without it.
 *
 * The chair gate is decided by GovernanceScopeGuard::isInBodyScope for the
 * acting user only: the scope double answers true for one user on one body
 * in the chair scope, so a gate that asked about another user, another body
 * or another scope changes the recording.
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
 *
 * @spec openspec/specs/meeting-management/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Lifecycle\MeetingTransitionGuard;
use OCA\Decidiq\Service\ActivityPublisherService;
use OCA\Decidiq\Service\GovernanceScopeGuard;
use OCA\Decidiq\Service\MeetingCostService;
use OCA\Decidiq\Service\MeetingService;
use OCA\Decidiq\Service\WorkflowService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for the meeting lifecycle.
 *
 * @covers \OCA\Decidiq\Service\MeetingService
 */
class MeetingTransitionGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/meeting-transition-golden.json';

	private const ACTIONS = ['schedule', 'open', 'pause', 'resume', 'adjourn', 'close', 'dance'];

	private const STATES = ['draft', 'scheduled', 'opened', 'paused', 'adjourned', 'closed'];

	/**
	 * Every recorded call of the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * The case being run.
	 *
	 * @var array<string, mixed>
	 */
	private array $case = [];

	/**
	 * Every transition and action list answers as recorded.
	 *
	 * @return void
	 */
	public function testEveryTransitionMatchesTheRecording(): void {
		$actual = [];
		foreach (self::STATES as $state) {
			foreach (self::ACTIONS as $action) {
				$actual['matrix:' . $state . ':' . $action] = $this->runCall(case: ['meeting' => ['lifecycle' => $state]], call: ['transition', $action, 'chair-1']);
			}

			$actual['actions:' . $state] = $this->runCall(case: ['meeting' => ['lifecycle' => $state]], call: ['actions', '', 'chair-1']);
		}

		foreach ($this->cases() as $name => $case) {
			foreach ($case['calls'] as $call) {
				$actual[$name . ':' . implode('/', array_map('strval', $call))] = $this->runCall(case: $case, call: $call);
			}
		}

		$golden = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('RECORD_MEETING_TRANSITION_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		self::assertSame(array_keys($golden), array_keys($actual));
		foreach ($actual as $name => $value) {
			self::assertSame(json_encode($golden[$name]), json_encode($value), $name);
		}
	}//end testEveryTransitionMatchesTheRecording()

	/**
	 * The named cases: the meeting, how the collaborators answer, and the calls.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$chairGated = ['chairOnly' => ['scheduled:opened', 'opened:closed', 'paused:opened']];
		$all        = [['transition', 'open', 'chair-1'], ['transition', 'open', 'member-1'], ['transition', 'open', null], ['actions', '', 'chair-1'], ['actions', '', 'member-1']];
		return [
			'chair-gate-relations'      => $chairGated + ['meeting' => ['lifecycle' => 'scheduled', 'relations' => ['governanceBody' => 'body-1']], 'calls' => $all],
			'chair-gate-relations-pascal' => $chairGated + ['meeting' => ['lifecycle' => 'scheduled', 'relations' => ['GovernanceBody' => ['id' => 'body-1']]], 'calls' => $all],
			'chair-gate-both-spellings' => $chairGated + ['meeting' => ['lifecycle' => 'scheduled', 'relations' => ['GovernanceBody' => 'body-2', 'governanceBody' => 'body-1']], 'calls' => $all],
			'chair-gate-flat'           => $chairGated + ['meeting' => ['lifecycle' => 'scheduled', 'governanceBody' => 'body-1'], 'calls' => $all],
			'chair-gate-flat-pascal'    => $chairGated + ['meeting' => ['lifecycle' => 'scheduled', 'GovernanceBody' => ['body-1']], 'calls' => $all],
			'chair-gate-relation-first' => $chairGated + ['meeting' => ['lifecycle' => 'scheduled', 'relations' => ['governanceBody' => 'body-2'], 'governanceBody' => 'body-1'], 'calls' => $all],
			'chair-gate-empty-relation' => $chairGated + ['meeting' => ['lifecycle' => 'scheduled', 'relations' => ['governanceBody' => ''], 'governanceBody' => 'body-1'], 'calls' => $all],
			'chair-gate-no-body'        => $chairGated + ['meeting' => ['lifecycle' => 'scheduled'], 'calls' => $all],
			'chair-gate-junk-body'      => $chairGated + ['meeting' => ['lifecycle' => 'scheduled', 'relations' => 'body-1', 'governanceBody' => 7], 'calls' => $all],
			'chair-gate-forged-body'    => $chairGated + ['meeting' => ['lifecycle' => 'scheduled', 'relations' => ['governanceBody' => 'body-9']], 'calls' => $all],
			'chair-gate-close'          => $chairGated + ['meeting' => ['lifecycle' => 'opened', 'governanceBody' => 'body-1'], 'calls' => [['transition', 'close', 'chair-1'], ['transition', 'close', 'member-1'], ['transition', 'pause', 'member-1'], ['actions', '', 'member-1']]],
			'domain-refuses'            => ['refuse' => ['corporate:opened:paused'], 'meeting' => ['lifecycle' => 'opened', 'domain' => 'corporate'], 'calls' => [['transition', 'pause', 'chair-1'], ['transition', 'adjourn', 'chair-1'], ['actions', '', 'chair-1']]],
			'quorum-required-not-met'   => ['quorum' => true, 'quorumMet' => false, 'meeting' => ['lifecycle' => 'scheduled'], 'calls' => [['transition', 'open', 'chair-1'], ['actions', '', 'chair-1']]],
			'quorum-required-met'       => ['quorum' => true, 'quorumMet' => true, 'meeting' => ['lifecycle' => 'adjourned', 'openedAt' => 'gisteren'], 'calls' => [['transition', 'open', 'chair-1']]],
			'quorum-not-required'       => ['quorum' => false, 'quorumMet' => false, 'meeting' => ['lifecycle' => 'scheduled', 'openedAt' => ''], 'calls' => [['transition', 'open', 'chair-1']]],
			'close-with-cost'           => ['cost' => 123.45, 'meeting' => ['lifecycle' => 'paused', 'title' => 'Raad'], 'calls' => [['transition', 'close', 'chair-1']]],
			'close-cost-null'           => ['cost' => null, 'meeting' => ['lifecycle' => 'adjourned'], 'calls' => [['transition', 'close', 'chair-1']]],
			'close-cost-throws'         => ['cost' => 'throw', 'meeting' => ['lifecycle' => 'scheduled'], 'calls' => [['transition', 'close', 'chair-1']]],
			'missing'                   => ['find' => null, 'calls' => [['transition', 'open', 'chair-1'], ['actions', '', 'chair-1']]],
			'does-not-exist'            => ['find' => 'missing', 'calls' => [['transition', 'open', 'chair-1'], ['actions', '', 'chair-1']]],
			'find-throws'               => ['find' => 'throw', 'calls' => [['transition', 'open', 'chair-1']]],
			'save-throws'               => ['save' => 'throw', 'meeting' => ['lifecycle' => 'scheduled'], 'calls' => [['transition', 'open', 'chair-1']]],
			'activity-fails'            => ['activity' => 'throw', 'meeting' => ['lifecycle' => 'draft'], 'calls' => [['transition', 'schedule', 'chair-1']]],
			'defaults'                  => ['meeting' => [], 'calls' => [['transition', 'schedule', 'chair-1'], ['actions', '', 'chair-1']]],
		];
	}//end cases()

	/**
	 * Run one call over one case.
	 *
	 * @param array<string, mixed> $case The case
	 * @param array<int, mixed>    $call [kind, action, user]
	 *
	 * @return array<string, mixed>
	 */
	private function runCall(array $case, array $call): array {
		$this->log  = [];
		$this->case = $case;
		$service    = $this->service();
		if ($call[0] === 'actions') {
			$returns = $service->availableActionsFor('mt-1', (string)$call[2]);
		} else {
			$returns = $service->transition('mt-1', (string)$call[1], $call[2]);
		}

		$json = (string)json_encode(['returns' => $returns, 'calls' => $this->log], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		$json = (string)preg_replace('/20\d\d-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d/', '<time>', $json);
		return json_decode($json, true);
	}//end runCall()

	/**
	 * The service over the current case.
	 *
	 * @return MeetingService
	 */
	private function service(): MeetingService {
		$case    = $this->case;
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (mixed $id) use ($case): ?ObjectEntity {
				$this->log[] = ['find' => $id];
				$find        = (array_key_exists('find', $case) === true ? $case['find'] : 'found');
				if ($find === 'missing') {
					throw new DoesNotExistException('gone');
				}

				if ($find === 'throw') {
					throw new RuntimeException('register down');
				}

				if ($find === null) {
					return null;
				}

				return $this->entity(data: ($case['meeting'] ?? []));
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (mixed $object, mixed ...$rest) use ($case): ObjectEntity {
				$this->log[] = ['saveObject' => [$object, $rest]];
				if (($case['save'] ?? null) === 'throw') {
					throw new RuntimeException('no write access');
				}

				return $this->entity(data: ['saved' => $object]);
			}
		);

		return new MeetingService(
			container: $this->container(),
			logger: $this->logger(),
			workflowService: $this->workflow(),
			transitionGuard: $this->quorumGuard(),
			meetingCostService: $this->cost(),
			scopeGuard: $this->scope(),
			objectService: $objects,
		);
	}//end service()

	/**
	 * The workflow rules: transitions refused per domain, chair-only steps, quorum.
	 *
	 * @return WorkflowService
	 */
	private function workflow(): WorkflowService {
		$case     = $this->case;
		$workflow = $this->createMock(WorkflowService::class);
		$workflow->method('isTransitionAllowed')->willReturnCallback(
			function (string $domain, string $fromState, string $toState) use ($case): bool {
				$this->log[] = ['isTransitionAllowed' => [$domain, $fromState, $toState]];
				return in_array($domain . ':' . $fromState . ':' . $toState, ($case['refuse'] ?? []), true) === false;
			}
		);
		$workflow->method('requiresChairAuthorization')->willReturnCallback(
			function (string $domain, string $from, string $to) use ($case): bool {
				$this->log[] = ['requiresChairAuthorization' => [$domain, $from, $to]];
				return in_array($from . ':' . $to, ($case['chairOnly'] ?? []), true);
			}
		);
		$workflow->method('isQuorumRequired')->willReturnCallback(
			function (string $domain) use ($case): bool {
				$this->log[] = ['isQuorumRequired' => $domain];
				return ($case['quorum'] ?? false);
			}
		);
		return $workflow;
	}//end workflow()

	/**
	 * The quorum guard.
	 *
	 * @return MeetingTransitionGuard
	 */
	private function quorumGuard(): MeetingTransitionGuard {
		$case  = $this->case;
		$guard = $this->createMock(MeetingTransitionGuard::class);
		$guard->method('isOpenAllowed')->willReturnCallback(
			function (array $meeting) use ($case): bool {
				$this->log[] = ['isOpenAllowed' => $meeting];
				return ($case['quorumMet'] ?? true);
			}
		);
		return $guard;
	}//end quorumGuard()

	/**
	 * The chair scope: only chair-1, only on body-1, only for the chair scope.
	 *
	 * @return GovernanceScopeGuard
	 */
	private function scope(): GovernanceScopeGuard {
		$scope = $this->createMock(GovernanceScopeGuard::class);
		$scope->method('isInBodyScope')->willReturnCallback(
			function (string $userId, string $bodyId, string $scopeName): bool {
				$this->log[] = ['isInBodyScope' => [$userId, $bodyId, $scopeName]];
				return $userId === 'chair-1' && $bodyId === 'body-1' && $scopeName === GovernanceScopeGuard::SCOPE_CHAIR;
			}
		);
		return $scope;
	}//end scope()

	/**
	 * The meeting cost service.
	 *
	 * @return MeetingCostService
	 */
	private function cost(): MeetingCostService {
		$case = $this->case;
		$cost = $this->createMock(MeetingCostService::class);
		$cost->method('calculateForMeeting')->willReturnCallback(
			function (string $meetingId, ?array $meeting = null) use ($case): ?float {
				$this->log[] = ['calculateForMeeting' => [$meetingId, $meeting]];
				if (($case['cost'] ?? null) === 'throw') {
					throw new RuntimeException('cost down');
				}

				return ($case['cost'] ?? null);
			}
		);
		return $cost;
	}//end cost()

	/**
	 * The container, answering with the activity publisher.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$case      = $this->case;
		$publisher = $this->createMock(ActivityPublisherService::class);
		$publisher->method('publishGovernanceEvent')->willReturnCallback(
			function (string $subject, string $title, string $status, string $objectType, string $objectUuid, string $segment) use ($case): int {
				$this->log[] = ['publishGovernanceEvent' => [$subject, $title, $status, $objectType, $objectUuid, $segment]];
				if (($case['activity'] ?? null) === 'throw') {
					throw new RuntimeException('activity down');
				}

				return 1;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($publisher): object {
				$this->log[] = ['container' => $id];
				return $publisher;
			}
		);
		return $container;
	}//end container()

	/**
	 * A logger that records every line.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level => $message, 'context' => $context];
				}
			);
		}

		return $logger;
	}//end logger()

	/**
	 * An entity double carrying the given data.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)->disableOriginalConstructor()->onlyMethods(['getObject', 'jsonSerialize'])->getMock();
		$entity->method('getObject')->willReturn($data);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()
}//end class
