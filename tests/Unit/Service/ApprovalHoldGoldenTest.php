<?php

/**
 * Golden test for holding an approval route from named people.
 *
 * Holds routes through the two doors that reach ApprovalRouteService::holdFor():
 * ApprovalRouteController::instantiate() with an `adhoc` route (signed in as a
 * member, as an admin, or not at all; a forged `userId`/`actor`/`principal`
 * parameter; unordered, blank and repeated actors; a deadline that is valid,
 * empty or unreadable; a substitute fraction in and out of range; a subject
 * that cannot be reached; a subject that already has stages) and
 * ApprovalRouteRequestedListener::handle() with an ad-hoc event (with and
 * without a name, for one acting user and for another). It records every
 * object-service call by parameter name, every admin lookup with the user it
 * ran for, and the response or the event's outcome (due dates masked, since
 * they count working days from today). The
 * expected values live in tests/fixtures/approval-hold-golden.json; run with
 * RECORD_APPROVAL_HOLD_GOLDEN=1 to write them, then run again without it.
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
 * @spec openspec/specs/approval-routes/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Tests\Unit\Support\FindCallShape;
use OCA\Decidiq\Controller\ApprovalRouteController;
use OCA\Decidiq\Event\ApprovalRouteRequestedEvent;
use OCA\Decidiq\Listener\ApprovalRouteRequestedListener;
use OCA\Decidiq\Service\ApprovalRouteCommandService;
use OCA\Decidiq\Service\ApprovalRouteConclusionAnnouncer;
use OCA\Decidiq\Service\ApprovalRouteService;
use OCA\Decidiq\Service\ApprovalRouteStepMapper;
use OCA\Decidiq\Service\ApprovalStageGuard;
use OCA\Decidiq\Service\MandateDirectory;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCA\Decidiq\Service\SubjectClearanceService;
use OCA\Decidiq\Service\WorkingDayDeadlineSplitter;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Golden test for holding an approval route from named people.
 *
 * @covers \OCA\Decidiq\Service\ApprovalRouteService
 * @covers \OCA\Decidiq\Controller\ApprovalRouteController
 * @covers \OCA\Decidiq\Listener\ApprovalRouteRequestedListener
 * @uses   \OCA\Decidiq\Service\ApprovalRouteAdvancer
 * @uses   \OCA\Decidiq\Service\ApprovalRouteStepMapper
 * @uses   \OCA\Decidiq\Service\ApprovalStageGuard
 * @uses   \OCA\Decidiq\Service\MandateDirectory
 * @uses   \OCA\Decidiq\Service\RegisterObjectStore
 * @uses   \OCA\Decidiq\Service\StageLapsePolicy
 * @uses   \OCA\Decidiq\Service\ApprovalPrincipal
 * @uses   \OCA\Decidiq\Service\WorkingDayDeadlineSplitter
 * @uses   \OCA\Decidiq\Service\HeldRouteRequest
 * @uses   \OCA\Decidiq\Event\ApprovalRouteRequestedEvent
 */
class ApprovalHoldGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/approval-hold-golden.json';

	/**
	 * Every recorded call of the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * Stored rows of the current case, keyed by schema then uuid.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Every hold matches the recording.
	 *
	 * @return void
	 */
	public function testEveryHoldMatchesTheRecording(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->log  = [];
			$this->rows = [
				'decision'       => ['doc-1' => ['id' => 'doc-1', 'title' => 'Nota'], 'doc-2' => ['id' => 'doc-2']],
				'document'       => ['file-1' => ['id' => 'file-1', 'title' => 'Bijlage']],
				'decision-stage' => ['stage-old' => ['id' => 'stage-old', 'subject' => 'doc-2', 'sequence' => 1, 'stageType' => 'endorsement', 'status' => 'active', 'decisionMakerType' => 'person', 'label' => 'Bestaand']],
			];
			$service = $this->service();
			if (isset($case['event']) === true) {
				$event = new ApprovalRouteRequestedEvent(...$case['event']);
				(new ApprovalRouteRequestedListener(
					commandService: $this->createMock(ApprovalRouteCommandService::class),
					logger: new NullLogger(),
					engine: $service
				))->handle($event);
				$returns = ['created' => $event->isCreated(), 'handled' => $event->isHandled(), 'stageCount' => $event->getStageCount(), 'routeId' => $event->getRouteId()];
			} else {
				$response = $this->controller(service: $service, case: $case)->instantiate();
				$returns  = ['status' => $response->getStatus(), 'data' => $response->getData()];
			}

			$actual[$name] = $this->masked(value: ['returns' => $returns, 'calls' => $this->log]);
		}

		$golden = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('RECORD_APPROVAL_HOLD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		self::assertSame(array_keys($golden), array_keys($actual));
		foreach ($actual as $name => $value) {
			self::assertSame(json_encode($golden[$name]), json_encode($value), $name);
		}
	}//end testEveryHoldMatchesTheRecording()

	/**
	 * The cases: the request parameters and session user, or an event.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$steps = [
			['order' => 2, 'actor' => ' d.devries '],
			['order' => 1, 'actor' => 'j.jansen'],
			['order' => 3, 'actor' => 'j.jansen'],
			['order' => 4, 'actor' => '  '],
			'junk',
			['order' => 5, 'actor' => 'b.bakker'],
		];
		$route  = ['origin' => 'adhoc', 'name' => 'Nota review', 'steps' => $steps];
		$params = ['route' => $route, 'subject' => 'doc-1', 'subjectSchema' => 'decision', 'deadline' => '2027-07-01T17:00:00+00:00'];
		$event  = ['sourceApp' => 'filinq', 'externalReference' => 'ext-1', 'name' => 'Ronde', 'steps' => [], 'subject' => 'doc-1', 'subjectSchema' => 'decision', 'actorId' => 'alice', 'actors' => ['p.pieters', 'q.quist', 'p.pieters'], 'deadline' => '2027-07-01T17:00:00+00:00'];

		return [
			'member-holds'              => ['user' => 'alice', 'params' => $params],
			'admin-holds'               => ['user' => 'admin', 'params' => $params],
			'not-signed-in'             => ['user' => null, 'params' => $params],
			'forged-user-params'        => ['user' => 'alice', 'params' => $params + ['userId' => 'admin', 'actor' => 'admin', 'principal' => 'Administrator']],
			'no-deadline'               => ['user' => 'alice', 'params' => ['deadline' => ''] + $params],
			'deadline-omitted'          => ['user' => 'alice', 'params' => array_diff_key($params, ['deadline' => true])],
			'unreadable-deadline'       => ['user' => 'alice', 'params' => ['deadline' => 'next tuesday-ish'] + $params],
			'substitute-half'           => ['user' => 'alice', 'params' => $params + ['askSubstituteAfter' => '0.5']],
			'substitute-one'            => ['user' => 'alice', 'params' => $params + ['askSubstituteAfter' => 1]],
			'substitute-zero'           => ['user' => 'alice', 'params' => $params + ['askSubstituteAfter' => '0']],
			'substitute-too-big'        => ['user' => 'alice', 'params' => $params + ['askSubstituteAfter' => '1.5']],
			'substitute-not-a-number'   => ['user' => 'alice', 'params' => $params + ['askSubstituteAfter' => 'soon']],
			'unnamed-route'             => ['user' => 'alice', 'params' => ['route' => ['origin' => 'adhoc', 'steps' => $steps]] + $params],
			'nobody-named'              => ['user' => 'alice', 'params' => ['route' => ['origin' => 'adhoc', 'steps' => [['actor' => ' '], 'junk']]] + $params],
			'steps-not-a-list'          => ['user' => 'alice', 'params' => ['route' => ['origin' => 'adhoc', 'steps' => 'j.jansen']] + $params],
			'subject-out-of-reach'      => ['user' => 'alice', 'params' => ['subject' => 'doc-secret'] + $params],
			'subject-already-has-stages' => ['user' => 'alice', 'params' => ['subject' => 'doc-2'] + $params],
			'other-schema'              => ['user' => 'alice', 'params' => ['subject' => 'file-1', 'subjectSchema' => 'document'] + $params],
			'event-other-schema'        => ['event' => ['subject' => 'file-1', 'subjectSchema' => 'document'] + $event],
			'route-not-an-array'        => ['user' => 'alice', 'params' => ['route' => 'adhoc'] + $params],
			'event-adhoc'               => ['event' => $event],
			'event-other-acting-user'   => ['event' => ['actorId' => 'mallory'] + $event],
			'event-unnamed'             => ['event' => ['name' => ''] + $event],
			'event-no-deadline'         => ['event' => ['deadline' => ''] + $event],
			'event-bad-deadline'        => ['event' => ['deadline' => 'whenever'] + $event],
			'event-blank-actors'        => ['event' => ['actors' => ['', ' ']] + $event],
		];
	}//end cases()

	/**
	 * The controller over the service, with the case's request and session.
	 *
	 * @param ApprovalRouteService $service The real service
	 * @param array<string, mixed> $case    The case
	 *
	 * @return ApprovalRouteController
	 */
	private function controller(ApprovalRouteService $service, array $case): ApprovalRouteController {
		$params  = $case['params'];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => array_key_exists($key, $params) === true ? $params[$key] : $default
		);

		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($case['user'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($case['user']);
		}

		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(
			function (string $uid): bool {
				$this->log[] = ['isAdmin' => $uid];
				return $uid === 'admin';
			}
		);

		return new ApprovalRouteController(
			request: $request,
			service: $service,
			announcer: $this->createMock(ApprovalRouteConclusionAnnouncer::class),
			clearanceService: new SubjectClearanceService(),
			userSession: $session,
			groupManager: $groups,
			logger: new NullLogger(),
		);
	}//end controller()

	/**
	 * The real service over a recording object-service double.
	 *
	 * @return ApprovalRouteService
	 */
	private function service(): ApprovalRouteService {
		$facade = $this->createMock(ObjectServiceInterface::class);
		$facade->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): ObjectEntityInterface {
				$this->log[] = ['save' => ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid]];
				$uuid = ($uuid ?? ($schema . '-' . count($this->log)));
				$this->rows[(string)$schema][$uuid] = ($object + ['id' => $uuid]);
				return $this->entity(row: $this->rows[(string)$schema][$uuid]);
			}
		);
		$facade->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data, string|int|null $register = null, string|int|null $schema = null): ObjectEntityInterface {
				$this->log[] = ['patch' => ['objectId' => $objectId, 'data' => $data, 'register' => $register, 'schema' => $schema]];
				$this->rows[(string)$schema][$objectId] = (array_merge(($this->rows[(string)$schema][$objectId] ?? []), $data) + ['id' => $objectId]);
				return $this->entity(row: $this->rows[(string)$schema][$objectId]);
			}
		);
		$facade->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$this->log[] = ['findAll' => $config];
				$filters = ($config['filters'] ?? []);
				$schema  = (string)($filters['schema'] ?? '');
				unset($filters['register'], $filters['schema']);
				$out = [];
				foreach (($this->rows[$schema] ?? []) as $row) {
					$matches = true;
					foreach ($filters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							$matches = false;
						}
					}

					if ($matches === true) {
						$out[] = $row;
					}
				}

				return $out;
			}
		);
		$facade->method('find')->willReturnCallback(FindCallShape::legacy(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): ?ObjectEntityInterface {
				$this->log[] = ['find' => ['id' => $id, 'register' => $register, 'schema' => $schema]];
				$row = ($this->rows[(string)$schema][(string)$id] ?? null);
				return $row === null ? null : $this->entity(row: $row);
			}
		));

		$store = new RegisterObjectStore($facade);
		return new ApprovalRouteService(
			store: $store,
			guard: new ApprovalStageGuard(new MandateDirectory($store)),
			mapper: new ApprovalRouteStepMapper(),
			projector: null,
			activator: null,
			splitter: new WorkingDayDeadlineSplitter(),
		);
	}//end service()

	/**
	 * Mask the due dates, which the splitter counts from the current day.
	 *
	 * @param array<string, mixed> $value A recorded value
	 *
	 * @return array<string, mixed> The value with every dueAt masked.
	 */
	private function masked(array $value): array {
		array_walk_recursive(
			$value,
			static function (mixed &$item, int|string $key): void {
				if ($key === 'dueAt' && is_string($item) === true && strtotime($item) !== false) {
					$item = ($item === '2027-07-01T17:00:00+00:00') ? 'the deadline' : '<working day before the deadline>';
				}
			}
		);
		return $value;
	}//end masked()

	/**
	 * An entity double that serialises to the row.
	 *
	 * @param array<string, mixed> $row The row
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $row): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($row);
		return $entity;
	}//end entity()
}//end class
