<?php

/**
 * Golden test for declaring a conflict of interest.
 *
 * Declares through ConflictOfInterestController::declare() as the declaring
 * member, another member, the chair, an admin and nobody; with a forged
 * `callerUid`, `userId` or `actor` parameter; with the member's Membership
 * omitted (declared from a page) for a user who is and who is not a member;
 * with every severity, an unknown type and severity, the recusal flag in its
 * spellings, missing parameters, and a store that refuses the write. It
 * records every authorization check with the user it ran for, every
 * object-service call by parameter name, every audit entry with its actor,
 * every log line and the response (timestamps masked). The expected values
 * live in tests/fixtures/conflict-declare-golden.json; run with
 * RECORD_CONFLICT_DECLARE_GOLDEN=1 to write them, then run again without it.
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
 * @spec openspec/specs/conflict-of-interest/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Controller\ConflictOfInterestController;
use OCA\Decidiq\Service\AuditLogService;
use OCA\Decidiq\Service\ConflictOfInterestAuthorizationGuard;
use OCA\Decidiq\Service\ConflictOfInterestService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Golden test for declaring a conflict of interest.
 *
 * @covers \OCA\Decidiq\Service\ConflictOfInterestService
 * @covers \OCA\Decidiq\Controller\ConflictOfInterestController
 * @uses   \OCA\Decidiq\Service\ConflictDeclaration
 */
class ConflictDeclareGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/conflict-declare-golden.json';

	/**
	 * Every recorded call of the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * Every declaration matches the recording.
	 *
	 * @return void
	 */
	public function testEveryDeclarationMatchesTheRecording(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->log = [];
			$response  = $this->controller(case: $case)->declare();
			$actual[$name] = $this->masked(value: ['returns' => ['status' => $response->getStatus(), 'data' => $response->getData()], 'calls' => $this->log]);
		}

		$golden = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('RECORD_CONFLICT_DECLARE_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		self::assertSame(array_keys($golden), array_keys($actual));
		foreach ($actual as $name => $value) {
			self::assertSame(json_encode($golden[$name]), json_encode($value), $name);
		}
	}//end testEveryDeclarationMatchesTheRecording()

	/**
	 * The cases: the session user and the request parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$own = ['membershipId' => 'M-anna', 'agendaItemId' => 'ai-1', 'declarationType' => 'financial-interest', 'description' => 'Aandelen', 'severity' => 'material'];

		return [
			'member-declares-own'       => ['user' => 'anna', 'params' => $own],
			'member-for-another'        => ['user' => 'anna', 'params' => ['membershipId' => 'M-bert'] + $own],
			'other-member-for-anna'     => ['user' => 'bert', 'params' => $own],
			'chair-for-anna'            => ['user' => 'chair', 'params' => $own],
			'admin-for-anna'            => ['user' => 'admin', 'params' => $own],
			'signed-out'                => ['user' => null, 'params' => $own],
			'forged-caller-params'      => ['user' => 'bert', 'params' => $own + ['callerUid' => 'anna', 'userId' => 'anna', 'actor' => 'chair']],
			'non-material'              => ['user' => 'anna', 'params' => ['severity' => 'non-material'] + $own],
			'unknown-severity'          => ['user' => 'anna', 'params' => ['severity' => 'huge'] + $own],
			'unknown-type'              => ['user' => 'anna', 'params' => ['declarationType' => 'grudge'] + $own],
			'type-omitted'              => ['user' => 'anna', 'params' => array_diff_key($own, ['declarationType' => true])],
			'severity-omitted'          => ['user' => 'anna', 'params' => array_diff_key($own, ['severity' => true])],
			'unknown-type-forbidden'    => ['user' => 'bert', 'params' => ['declarationType' => 'grudge'] + $own],
			'recuse-true'               => ['user' => 'anna', 'params' => $own + ['recuseFromVote' => 'true']],
			'recuse-one'                => ['user' => 'anna', 'params' => $own + ['recuseFromVote' => 1]],
			'recuse-no'                 => ['user' => 'anna', 'params' => $own + ['recuseFromVote' => 'no']],
			'recuse-garbage'            => ['user' => 'anna', 'params' => $own + ['recuseFromVote' => 'please']],
			'recuse-by-chair'           => ['user' => 'chair', 'params' => $own + ['recuseFromVote' => true]],
			'from-page-member'          => ['user' => 'anna', 'params' => ['membershipId' => ''] + $own],
			'from-page-not-a-member'    => ['user' => 'zoe', 'params' => ['membershipId' => ''] + $own],
			'from-page-no-agenda-item'  => ['user' => 'anna', 'params' => ['membershipId' => '', 'agendaItemId' => ''] + $own],
			'no-agenda-item'            => ['user' => 'anna', 'params' => ['agendaItemId' => ''] + $own],
			'empty-description'         => ['user' => 'anna', 'params' => ['description' => ''] + $own],
			'write-refused'             => ['user' => 'anna', 'params' => ['description' => 'refuse'] + $own],
			'write-refused-non-material' => ['user' => 'anna', 'params' => ['description' => 'refuse', 'severity' => 'non-material'] + $own],
		];
	}//end cases()

	/**
	 * The controller over the real service, with recording doubles.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return ConflictOfInterestController
	 */
	private function controller(array $case): ConflictOfInterestController {
		$params  = $case['params'];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => array_key_exists($key, $params) === true ? $params[$key] : $default
		);

		$user = null;
		if ($case['user'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($case['user']);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(
			function (string $uid): bool {
				$this->log[] = ['isAdmin' => $uid];
				return $uid === 'admin';
			}
		);

		$guard = $this->createMock(ConflictOfInterestAuthorizationGuard::class);
		$guard->method('isAuthorizedForMember')->willReturnCallback(
			function (string $membershipId, string $agendaItemId, string $callerUid): bool {
				$this->log[] = ['authorize' => [$membershipId, $agendaItemId, $callerUid]];
				return ($callerUid === 'anna' && $membershipId === 'M-anna') || ($callerUid === 'chair' && $agendaItemId === 'ai-1');
			}
		);
		$guard->method('membershipForUser')->willReturnCallback(
			function (string $uid): ?string {
				$this->log[] = ['membershipFor' => $uid];
				return ($uid === 'anna') ? 'M-anna' : null;
			}
		);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntityInterface $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null, bool $_rbac = true): ObjectEntityInterface {
				$this->log[] = ['save' => ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, '_rbac' => $_rbac]];
				if (($object['description'] ?? '') === 'refuse') {
					throw new RuntimeException('store refused');
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn(['id' => 'coi-1'] + $object);
				return $entity;
			}
		);

		$audit = $this->createMock(AuditLogService::class);
		$audit->method('append')->willReturnCallback(
			function (string $actor, string $action, array $objectUids, array $payload = []): array {
				$this->log[] = ['audit' => ['actor' => $actor, 'action' => $action, 'objectUids' => $objectUids, 'payload' => $payload]];
				return ['success' => true, 'entry' => null, 'message' => 'ok'];
			}
		);

		$service = new ConflictOfInterestService(
			logger: $this->logger(),
			auditLogService: $audit,
			objectService: $objects,
			authorizationGuard: $guard,
		);

		return new ConflictOfInterestController(
			request: $request,
			conflictService: $service,
			userSession: $session,
			groupManager: $groups,
		);
	}//end controller()

	/**
	 * A logger that records every line into the case log.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$sink = function (array $line): void {
			$this->log[] = ['log' => $line];
		};

		return new class ($sink) extends AbstractLogger {

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
	 * Mask the declaration time, the only value that differs per run.
	 *
	 * @param array<string, mixed> $value A recorded value
	 *
	 * @return array<string, mixed> The value with every declarationTimestamp masked.
	 */
	private function masked(array $value): array {
		array_walk_recursive(
			$value,
			static function (mixed &$item, int|string $key): void {
				if ($key === 'declarationTimestamp' && is_string($item) === true && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $item) === 1) {
					$item = '<utc>';
				}
			}
		);
		return $value;
	}//end masked()
}//end class
