<?php

/**
 * Golden test for a decision lifecycle transition.
 *
 * Runs DecisionLifecycleService::transition() over legislative, association,
 * operations and template-governed decisions: the chair-only transitions for
 * the meeting chair, another user, a case variant of the chair's uid, an empty
 * uid and no user, with a chair that has no Nextcloud uid, an owner instead,
 * a missing chair participant and no chair at all; quorum, outcome, terminal
 * completeness and appointment pairing gates; enacting with a resolution
 * record and appointment memberships; a delegated decision announcing its
 * conclusion; an audit append that fails; and a missing, unreadable or
 * unwritable decision. It records every object-service call by parameter
 * name, the audit entry with its actor, every log line, every dispatched
 * event and the result (enactedAt masked). The expected values live in
 * tests/fixtures/decision-transition-golden.json; run with
 * RECORD_DECISION_TRANSITION_GOLDEN=1 to write them, then run again without it.
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
 * @spec openspec/specs/decision-management/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Tests\Unit\Support\SaveCallShape;
use ArrayObject;
use OCA\Decidiq\Lifecycle\DecisionTransitionGuard;
use OCA\Decidiq\Service\AuditLogService;
use OCA\Decidiq\Service\DecisionIntegrationService;
use OCA\Decidiq\Service\DecisionLifecycleService;
use OCA\Decidiq\Service\ProcessTemplateService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionObject;
use RuntimeException;
use Stringable;

/**
 * Golden test for a decision lifecycle transition.
 *
 * @covers \OCA\Decidiq\Service\DecisionLifecycleService
 * @uses   \OCA\Decidiq\Service\DecisionContextResolver
 * @uses   \OCA\Decidiq\Service\DecisionTransitionRun
 * @uses   \OCA\Decidiq\Lifecycle\DecisionTransitionGuard
 * @uses   \OCA\Decidiq\Event\DecisionConcludedEvent
 */
class DecisionTransitionGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/decision-transition-golden.json';

	/**
	 * Every recorded call of the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * Every transition matches the recording.
	 *
	 * @return void
	 */
	public function testEveryTransitionMatchesTheRecording(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->log = [];
			$returns   = $this->service(case: $case)->transition(...$case['args']);
			$actual[$name] = $this->masked(value: ['returns' => $returns, 'calls' => $this->log]);
		}

		$golden = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('RECORD_DECISION_TRANSITION_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		self::assertSame(array_keys($golden), array_keys($actual));
		foreach ($actual as $name => $value) {
			self::assertSame(json_encode($golden[$name]), json_encode($value), $name);
		}
	}//end testEveryTransitionMatchesTheRecording()

	/**
	 * The cases: transition()'s named arguments plus how the doubles answer.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$chair = ['decisionId' => 'd-leg-delib', 'action' => 'openVoting', 'currentUserId' => 'chair-uid', 'comment' => 'Stemming open'];

		return [
			'chair-opens-voting'           => ['args' => $chair],
			'other-user-opens-voting'      => ['args' => ['currentUserId' => 'mallory'] + $chair],
			'case-variant-opens-voting'    => ['args' => ['currentUserId' => 'CHAIR-UID'] + $chair],
			'empty-user-opens-voting'      => ['args' => ['currentUserId' => ''] + $chair],
			'no-user-opens-voting'         => ['args' => ['currentUserId' => null] + $chair],
			'default-user-and-comment'     => ['args' => ['decisionId' => 'd-leg-delib', 'action' => 'openVoting']],
			'chair-participant-id-as-user' => ['args' => ['currentUserId' => 'chair-p'] + $chair],
			'chair-without-uid-owner'      => ['args' => ['decisionId' => 'd-owner-chair', 'currentUserId' => 'owner-uid'] + $chair],
			'chair-without-uid-or-owner'   => ['args' => ['decisionId' => 'd-blank-chair', 'currentUserId' => ''] + $chair],
			'chair-participant-missing'    => ['args' => ['decisionId' => 'd-ghost-chair'] + $chair],
			'chair-participant-throws'     => ['args' => ['decisionId' => 'd-gone-chair'] + $chair],
			'meeting-without-chair'        => ['args' => ['decisionId' => 'd-no-chair'] + $chair],
			'no-meeting-legislative'       => ['args' => ['decisionId' => 'd-leg-nomeeting'] + $chair],
			'quorum-not-met'               => ['args' => ['decisionId' => 'd-noquorum'] + $chair],
			'chair-decides'                => ['args' => ['decisionId' => 'd-leg-voting', 'action' => 'decide'] + $chair],
			'other-decides'                => ['args' => ['decisionId' => 'd-leg-voting', 'action' => 'decide', 'currentUserId' => 'mallory'] + $chair],
			'decide-without-result'        => ['args' => ['decisionId' => 'd-leg-voting-bare', 'action' => 'decide'] + $chair],
			'association-decide-anyone'    => ['args' => ['decisionId' => 'd-assoc-voting', 'action' => 'decide', 'currentUserId' => 'mallory'] + $chair],
			'association-open-other'       => ['args' => ['decisionId' => 'd-assoc-delib', 'currentUserId' => 'mallory'] + $chair],
			'meeting-domain'               => ['args' => ['decisionId' => 'd-meeting-domain', 'currentUserId' => 'mallory'] + $chair],
			'unknown-domain'               => ['args' => ['decisionId' => 'd-odd-domain', 'action' => 'propose', 'currentUserId' => 'chair-uid'] + $chair],
			'template-chair-only'          => ['args' => ['decisionId' => 'd-template', 'action' => 'deliberate', 'currentUserId' => 'mallory'] + $chair],
			'template-chair'               => ['args' => ['decisionId' => 'd-template', 'action' => 'deliberate'] + $chair],
			'template-skips-quorum'        => ['args' => ['decisionId' => 'd-template-voting', 'currentUserId' => 'mallory'] + $chair],
			'legislative-decide-unvoted'   => ['args' => ['decisionId' => 'd-leg-delib', 'action' => 'decide'] + $chair],
			'association-decide-unvoted'   => ['args' => ['decisionId' => 'd-assoc-delib', 'action' => 'decide'] + $chair],
			'operations-propose'           => ['args' => ['decisionId' => 'd-ops-draft', 'action' => 'propose', 'currentUserId' => null, 'comment' => '']],
			'wrong-state'                  => ['args' => ['decisionId' => 'd-ops-draft', 'action' => 'enact'] + $chair],
			'unknown-action'               => ['args' => ['action' => 'explode'] + $chair],
			'missing-decision'             => ['args' => ['decisionId' => 'd-none'] + $chair],
			'decision-does-not-exist'      => ['args' => ['decisionId' => 'd-dne'] + $chair],
			'decision-read-fails'          => ['args' => ['decisionId' => 'd-boom'] + $chair],
			'write-refused'                => ['args' => ['decisionId' => 'd-readonly', 'action' => 'propose'] + $chair],
			'audit-append-fails'           => ['args' => ['decisionId' => 'd-ops-draft', 'action' => 'propose', 'comment' => 'audit-fails'] + $chair],
			'enact-adopted'                => ['args' => ['decisionId' => 'd-decided', 'action' => 'enact'] + $chair],
			'enact-rejected'               => ['args' => ['decisionId' => 'd-decided-rejected', 'action' => 'enact'] + $chair],
			'enact-resolution-fails'       => ['args' => ['decisionId' => 'd-decided-nosave', 'action' => 'enact'] + $chair],
			'enact-appointment'            => ['args' => ['decisionId' => 'd-appoint', 'action' => 'enact'] + $chair],
			'enact-appointment-one-post'   => ['args' => ['decisionId' => 'd-appoint-one', 'action' => 'enact'] + $chair],
			'enact-appointment-mismatch'   => ['args' => ['decisionId' => 'd-appoint-bad', 'action' => 'enact'] + $chair],
			'enact-appointment-done'       => ['args' => ['decisionId' => 'd-appoint-done', 'action' => 'enact'] + $chair],
			'enact-appointment-rejected'   => ['args' => ['decisionId' => 'd-appoint-rejected', 'action' => 'enact'] + $chair],
			'archive-enacted'              => ['args' => ['decisionId' => 'd-enacted', 'action' => 'archive'] + $chair],
			'delegated-decided'            => ['args' => ['decisionId' => 'd-delegated', 'action' => 'decide', 'currentUserId' => 'mallory'] + $chair],
			'delegated-no-envelope'        => ['args' => ['decisionId' => 'd-delegated-none', 'action' => 'decide'] + $chair],
			'delegated-envelope-throws'    => ['args' => ['decisionId' => 'd-delegated-boom', 'action' => 'decide'] + $chair],
			'delegated-not-terminal'       => ['args' => ['decisionId' => 'd-delegated-delib', 'action' => 'openVoting', 'currentUserId' => 'x'] + $chair],
		];
	}//end cases()

	/**
	 * The seeded objects, keyed by id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function store(): array {
		$result    = ['outcome' => 'adopted', 'decisionDate' => '2026-06-12'];
		$candidate = [['person' => 'person-1'], ['person' => ['id' => 'person-2']], ['externalName' => 'Extern Lid']];

		return [
			'meet-leg'           => ['id' => 'meet-leg', 'domain' => 'legislative', 'chair' => 'chair-p', 'quorumWith' => true],
			'meet-noquorum'      => ['id' => 'meet-noquorum', 'domain' => 'legislative', 'chair' => ['id' => 'chair-p'], 'quorumWith' => false],
			'meet-nochair'       => ['id' => 'meet-nochair', 'domain' => 'legislative', 'quorumWith' => true],
			'meet-owner'         => ['id' => 'meet-owner', 'domain' => 'legislative', 'chair' => 'owner-p', 'quorumWith' => true],
			'meet-blank'         => ['id' => 'meet-blank', 'domain' => 'legislative', 'chair' => 'blank-p', 'quorumWith' => true],
			'meet-ghost'         => ['id' => 'meet-ghost', 'domain' => 'legislative', 'chair' => 'ghost-p', 'quorumWith' => true],
			'meet-gone'          => ['id' => 'meet-gone', 'domain' => 'legislative', 'chair' => 'gone-p', 'quorumWith' => true],
			'meet-corporate'     => ['id' => 'meet-corporate', 'domain' => 'corporate', 'chair' => 'chair-p', 'quorumWith' => true],
			'meet-template'      => ['id' => 'meet-template', 'chair' => 'chair-p', 'governanceBody' => 'body-t', 'quorumWith' => true],
			'meet-template-noquorum' => ['id' => 'meet-template-noquorum', 'chair' => 'chair-p', 'governanceBody' => 'body-t', 'quorumWith' => false],
			'chair-p'            => ['id' => 'chair-p', 'nextcloudUserId' => 'chair-uid', 'owner' => 'someone-else'],
			'owner-p'            => ['id' => 'owner-p', 'owner' => 'owner-uid'],
			'blank-p'            => ['id' => 'blank-p', 'nextcloudUserId' => ''],
			'd-leg-delib'        => ['id' => 'd-leg-delib', 'domain' => 'legislative', 'lifecycle' => 'deliberating', 'meeting' => 'meet-leg', 'title' => 'Motie'],
			'd-owner-chair'      => ['id' => 'd-owner-chair', 'domain' => 'legislative', 'lifecycle' => 'deliberating', 'meeting' => 'meet-owner'],
			'd-blank-chair'      => ['id' => 'd-blank-chair', 'domain' => 'legislative', 'lifecycle' => 'deliberating', 'meeting' => 'meet-blank'],
			'd-ghost-chair'      => ['id' => 'd-ghost-chair', 'domain' => 'legislative', 'lifecycle' => 'deliberating', 'meeting' => 'meet-ghost'],
			'd-gone-chair'       => ['id' => 'd-gone-chair', 'domain' => 'legislative', 'lifecycle' => 'deliberating', 'meeting' => 'meet-gone'],
			'd-no-chair'         => ['id' => 'd-no-chair', 'domain' => 'legislative', 'lifecycle' => 'deliberating', 'meeting' => 'meet-nochair'],
			'd-leg-nomeeting'    => ['id' => 'd-leg-nomeeting', 'domain' => 'legislative', 'lifecycle' => 'deliberating'],
			'd-noquorum'         => ['id' => 'd-noquorum', 'lifecycle' => 'deliberating', 'relations' => ['Meeting' => ['meet-noquorum']]],
			'd-leg-voting'       => ['id' => 'd-leg-voting', 'domain' => 'legislative', 'lifecycle' => 'voting', 'meeting' => ['id' => 'meet-leg']] + $result,
			'd-leg-voting-bare'  => ['id' => 'd-leg-voting-bare', 'domain' => 'legislative', 'lifecycle' => 'voting', 'meeting' => 'meet-leg', 'outcome' => 'maybe'],
			'd-assoc-voting'     => ['id' => 'd-assoc-voting', 'domain' => 'association', 'lifecycle' => 'voting', 'meeting' => 'meet-leg'] + $result,
			'd-assoc-delib'      => ['id' => 'd-assoc-delib', 'domain' => 'association', 'lifecycle' => 'deliberating', 'meeting' => 'meet-leg'],
			'd-meeting-domain'   => ['id' => 'd-meeting-domain', 'lifecycle' => 'deliberating', 'meeting' => 'meet-corporate'],
			'd-odd-domain'       => ['id' => 'd-odd-domain', 'domain' => 'pirate', 'lifecycle' => 'draft'],
			'd-template'         => ['id' => 'd-template', 'lifecycle' => 'proposed', 'meeting' => 'meet-template'],
			'd-template-voting'  => ['id' => 'd-template-voting', 'domain' => 'legislative', 'lifecycle' => 'deliberating', 'meeting' => 'meet-template-noquorum'],
			'd-ops-draft'        => ['id' => 'd-ops-draft', 'title' => 'Operationeel'],
			'd-readonly'         => ['id' => 'd-readonly', 'lifecycle' => 'draft'],
			'd-decided'          => ['id' => 'd-decided', 'lifecycle' => 'decided', 'title' => 'Besluit', 'text' => 'Tekst', 'legalBasis' => 'Art. 1', 'meeting' => 'meet-leg'] + $result,
			'd-decided-rejected' => ['id' => 'd-decided-rejected', 'lifecycle' => 'decided', 'outcome' => 'rejected', 'decisionDate' => '2026-06-12'],
			'd-decided-nosave'   => ['id' => 'd-decided-nosave', 'lifecycle' => 'decided', 'title' => 'nosave'] + $result,
			'd-appoint'          => ['id' => 'd-appoint', 'lifecycle' => 'decided', 'decisionType' => 'appointment', 'candidates' => $candidate, 'targetPosts' => ['post-1', ['id' => 'post-2'], 'post-3'], 'targetRole' => 'member', 'targetBody' => ['id' => 'body-1']] + $result,
			'd-appoint-one'      => ['id' => 'd-appoint-one', 'lifecycle' => 'decided', 'decisionType' => 'appointment', 'candidates' => $candidate, 'targetPosts' => ['post-1'], 'targetRole' => 'chair'] + $result,
			'd-appoint-bad'      => ['id' => 'd-appoint-bad', 'lifecycle' => 'decided', 'decisionType' => 'appointment', 'candidates' => $candidate, 'targetPosts' => ['post-1', 'post-2']] + $result,
			'd-appoint-done'     => ['id' => 'd-appoint-done', 'lifecycle' => 'decided', 'decisionType' => 'appointment', 'candidates' => $candidate, 'appointedMemberships' => ['m-1']] + $result,
			'd-appoint-rejected' => ['id' => 'd-appoint-rejected', 'lifecycle' => 'decided', 'decisionType' => 'appointment', 'candidates' => $candidate, 'outcome' => 'rejected', 'decisionDate' => '2026-06-12'],
			'd-enacted'          => ['id' => 'd-enacted', 'lifecycle' => 'enacted', 'enactedAt' => '2026-06-13T10:00:00+00:00'] + $result,
			'd-delegated'        => ['id' => 'd-delegated', 'lifecycle' => 'voting', 'sourceApp' => 'dossiq', 'externalReference' => 'zaak-7'] + $result,
			'd-delegated-none'   => ['id' => 'd-delegated-none', 'lifecycle' => 'voting', 'sourceApp' => 'dossiq'] + $result,
			'd-delegated-boom'   => ['id' => 'd-delegated-boom', 'lifecycle' => 'voting', 'sourceApp' => 'dossiq'] + $result,
			'd-delegated-delib'  => ['id' => 'd-delegated-delib', 'lifecycle' => 'deliberating', 'sourceApp' => 'dossiq'],
		];
	}//end store()

	/**
	 * The lifecycle service with every collaborator recording.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return DecisionLifecycleService
	 */
	private function service(array $case): DecisionLifecycleService {
		$store   = new ArrayObject($this->store());
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) use ($store): ?ObjectEntity {
				$this->log[] = ['find' => ['id' => $id, 'register' => $register, 'schema' => $schema]];
				if ($id === 'd-dne' || $id === 'gone-p') {
					throw new DoesNotExistException('gone');
				}

				if ($id === 'd-boom') {
					throw new RuntimeException('database down');
				}

				return isset($store[(string)$id]) === true ? $this->entity(object: $store[(string)$id]) : null;
			}
		);
		$objects->method('saveObject')->willReturnCallback(SaveCallShape::legacy(
			function (array|ObjectEntity $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): ObjectEntity {
				$this->log[] = ['save' => ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid]];
				if ($uuid === 'd-readonly') {
					throw new RuntimeException('no write access');
				}

				if (($object['title'] ?? '') === 'nosave' && $uuid === null) {
					throw new RuntimeException('resolution refused');
				}

				$id = ($uuid ?? ('new-' . count($this->log)));
				return $this->entity(object: ['id' => $id] + $object);
			}
		));

		$audit = $this->createMock(AuditLogService::class);
		$audit->method('append')->willReturnCallback(
			function (string $actor, string $action, array $objectUids, array $payload = []): array {
				$this->log[] = ['audit' => ['actor' => $actor, 'action' => $action, 'objectUids' => $objectUids, 'payload' => $payload]];
				if (($payload['comment'] ?? '') === 'audit-fails') {
					return ['success' => false, 'entry' => null, 'message' => 'chain broken'];
				}

				return ['success' => true, 'entry' => ['id' => 'a-1'], 'message' => 'ok'];
			}
		);

		$templates = $this->createMock(ProcessTemplateService::class);
		$templates->method('resolvePolicyForBody')->willReturnCallback(
			function (?string $governanceBodyId): ?array {
				$this->log[] = ['policy' => $governanceBodyId];
				if ($governanceBodyId !== 'body-t') {
					return null;
				}

				return ['quorumEnforced' => false, 'chairOnlyTransitions' => ['proposed:deliberating'], 'allowDecideWithoutVote' => true];
			}
		);

		$integration = $this->createMock(DecisionIntegrationService::class);
		$integration->method('getOutcomeEnvelope')->willReturnCallback(
			function (string $decisionId): ?array {
				$this->log[] = ['envelope' => $decisionId];
				return match ($decisionId) {
					'd-delegated' => ['decisionId' => $decisionId, 'decisionType' => 'motion', 'status' => 'decided', 'signed' => true, 'signingReference' => 'sig-1', 'signers' => ['chair-uid'], 'decidedAt' => '2026-06-12', 'subjectRegister' => 'dossiq', 'subjectSchema' => 'zaak', 'subjectId' => 'z-7', 'externalReference' => 'ext-7'],
					'd-delegated-boom' => throw new RuntimeException('integration down'),
					default => null,
				};
			}
		);

		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$fields = [];
				foreach ((new ReflectionObject($event))->getProperties() as $property) {
					if ($property->getDeclaringClass()->getName() === get_class($event)) {
						$fields[$property->getName()] = $property->getValue($event);
					}
				}

				$this->log[] = ['event' => [get_class($event), $fields]];
			}
		);

		return new DecisionLifecycleService(
			logger: $this->logger(),
			transitionGuard: new DecisionTransitionGuard(),
			auditLogService: $audit,
			templateService: $templates,
			integrationService: $integration,
			eventDispatcher: $events,
			objectService: $objects,
		);
	}//end service()

	/**
	 * A logger that records every line into the case log.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$log = function (array $line): void {
			$this->log[] = ['log' => $line];
		};

		return new class ($log) extends AbstractLogger {

			/**
			 * Constructor.
			 *
			 * @param \Closure $sink Receives every line
			 */
			public function __construct(private readonly \Closure $sink) {
			}//end __construct()

			/**
			 * Record one line.
			 *
			 * @param mixed                $level   The level
			 * @param string|Stringable    $message The message
			 * @param array<string, mixed> $context The context
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				($this->sink)([(string)$level, (string)$message, $context]);
			}//end log()
		};
	}//end logger()

	/**
	 * Mask the enactment time, the only value that differs per run.
	 *
	 * @param array<string, mixed> $value A recorded value
	 *
	 * @return array<string, mixed> The value with every fresh enactedAt masked.
	 */
	private function masked(array $value): array {
		array_walk_recursive(
			$value,
			static function (mixed &$item, int|string $key): void {
				if (in_array($key, ['enactedAt', 'startDate', 'effectiveDate'], true) === true && is_string($item) === true
					&& $item !== '2026-06-13T10:00:00+00:00' && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $item) === 1
				) {
					$item = '<atom>';
				}
			}
		);
		return $value;
	}//end masked()

	/**
	 * Wrap a payload in an ObjectEntity double that serialises to it.
	 *
	 * @param array<string, mixed> $object The payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $object): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize', 'getObject'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($object);
		$entity->method('getObject')->willReturn($object);
		return $entity;
	}//end entity()
}//end class
