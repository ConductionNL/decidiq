<?php

/**
 * Failure-mode tests for the ADR-005 Decision-supertype fold.
 *
 * ADR-005 retired the `motion`, `amendment` and `resolution` schemas into the
 * one `decision` schema, discriminated by `decisionType`. The schemas really are
 * gone — tests/Unit/RegisterJsonTest.php asserts their absence — so any PHP that
 * still names one of them asks OpenRegister for a schema that does not exist.
 *
 * That is not a silent miss. `ObjectService::setSchema()` rethrows
 * `DoesNotExistException`, which is neither `InvalidArgumentException` nor
 * `RuntimeException`, so it escapes the controllers' catch clauses and the
 * endpoint answers 500 where it owes 404 or 400.
 *
 * These tests pin the two contracts that regression measurably broke:
 *
 *   - an unknown motion on POST /api/motions/{id}/amendment-order is 404,
 *   - an out-of-vocabulary subjectType on POST /api/voting-rounds is 400.
 *
 * The ObjectService double below is deliberately STRICT: it models the register
 * as it actually is after the fold and raises `DoesNotExistException` for any
 * retired slug, exactly as OpenRegister does. Without that strictness a lenient
 * double would answer "not found" for a dead schema and both tests would pass
 * against the very code that produces the 500 in production.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Controller\MotionController;
use OCA\Decidesk\Service\MotionService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCA\Decidesk\Service\ProcessTemplateService;
use OCA\Decidesk\Service\VotingErrorResponder;
use OCA\Decidesk\Service\VotingRoundPreflight;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The two HTTP failure modes the half-done ADR-005 migration turned into 500s.
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */
class DecisionSupertypeFailureModeTest extends TestCase {

	/**
	 * Schema slugs the register actually carries after the ADR-005 fold.
	 *
	 * Read off `lib/Settings/decidesk_register.json`. `motion`, `amendment` and
	 * `resolution` are absent by design and must stay absent.
	 *
	 * @var string[]
	 */
	private const LIVE_SCHEMAS = [
		'decision',
		'meeting',
		'participant',
		'voting-round',
		'vote',
		'agenda-item',
		'minutes',
		'governance-body',
		'action-item',
	];

	/**
	 * The schema slugs ADR-005 retired into `decision`.
	 *
	 * Kept as the exact complement of the register: `tests/Unit/RegisterJsonTest.php`
	 * asserts `Motion`, `Amendment` and `Resolution` are absent from
	 * `lib/Settings/decidesk_register.json`, and no production path may address
	 * them.
	 *
	 * @var string[]
	 */
	private const RETIRED_SCHEMAS = ['motion', 'amendment', 'resolution'];

	/**
	 * Every schema slug the code under test asked the register for.
	 *
	 * @var \ArrayObject<int, string>
	 */
	private \ArrayObject $schemaCalls;

	/**
	 * An OpenRegister ObjectService double that owns a real schema vocabulary.
	 *
	 * Asking for a slug the register does not carry raises
	 * `DoesNotExistException`, which is what OpenRegister's `setSchema()` does
	 * (it logs and rethrows so NC's dispatcher can render a 404 — but only if
	 * nothing swallows it first, which is the whole defect).
	 *
	 * @param array<string, array<string, mixed>> $store Seed objects keyed by id
	 *
	 * @return object The ObjectService double
	 */
	private function objectServiceDouble(array $store = []): object {
		$schemaCalls = $this->schemaCalls;
		$liveSchemas = self::LIVE_SCHEMAS;
		$storeRef = new \ArrayObject($store);

		return new class($storeRef, $schemaCalls, $liveSchemas) {
			/**
			 * Currently selected schema slug.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param \ArrayObject $store Seed objects keyed by id
			 * @param \ArrayObject $schemaCalls Recorder for requested schema slugs
			 * @param string[] $live Schema slugs the register carries
			 */
			public function __construct(
				private \ArrayObject $store,
				private \ArrayObject $schemaCalls,
				private array $live,
			) {
			}

			/**
			 * Reject a slug the register does not carry, as OpenRegister does.
			 *
			 * @param string|null $schema The requested schema slug
			 *
			 * @throws DoesNotExistException When the slug is not in the register
			 *
			 * @return void
			 */
			private function assertSchemaExists(?string $schema): void {
				if ($schema === null || $schema === '') {
					return;
				}

				$this->schemaCalls->append($schema);
				if (in_array($schema, $this->live, true) === false) {
					throw new DoesNotExistException(
						"Schema '{$schema}' not found in register 'decidesk'"
					);
				}
			}

			/**
			 * Select the register (fluent no-op).
			 *
			 * @param string $register Register slug
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}

			/**
			 * Select the schema, refusing retired slugs.
			 *
			 * @param string $schema Schema slug
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->assertSchemaExists($schema);
				$this->schema = $schema;
				return $this;
			}

			/**
			 * Wrap a payload in an ObjectEntity-like object.
			 *
			 * @param array<string, mixed> $object The payload
			 *
			 * @return object
			 */
			private function wrap(array $object): object {
				return new class($object) {
					/**
					 * Constructor.
					 *
					 * @param array<string, mixed> $object The payload
					 */
					public function __construct(
						private array $object,
					) {
					}

					/**
					 * Serialize like an ObjectEntity.
					 *
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return $this->object;
					}

					/**
					 * Raw payload like an ObjectEntity.
					 *
					 * @return array<string, mixed>
					 */
					public function getObject(): array {
						return $this->object;
					}
				};
			}

			/**
			 * Find one object by id.
			 *
			 * @param int|string $id Object id
			 * @param string|int|null $register Register slug
			 * @param string|int|null $schema Schema slug
			 *
			 * @return object|null
			 */
			public function find(int|string $id, string|int|null $register = null, string|int|null $schema = null): ?object {
				if ($schema !== null) {
					$this->assertSchemaExists((string)$schema);
				}

				$row = ($this->store[(string)$id] ?? null);
				if ($row === null) {
					// OpenRegister THROWS for an unknown id; it does not return
					// null (MagicMapper::findInRegisterSchemaTable → "Object not
					// found in magic table"). A double that returned null here
					// would hide every caller that treats not-found as a return
					// value, which is how an unknown id became a 500.
					throw new DoesNotExistException(
						"Object not found in magic table: {$id}"
					);
				}

				return $this->wrap($row);
			}

			/**
			 * Find every object matching the filters.
			 *
			 * @param array<string, mixed> $config Query config
			 *
			 * @return array<int, object>
			 */
			public function findAll(array $config = []): array {
				$filters = ($config['filters'] ?? []);
				$this->assertSchemaExists(($filters['schema'] ?? null));

				$out = [];
				foreach ($this->store as $row) {
					$matches = true;
					foreach ($filters as $key => $value) {
						if (in_array($key, ['register', 'schema'], true) === true) {
							continue;
						}

						if (str_starts_with((string)$key, '_relations.') === true) {
							$field = substr((string)$key, strlen('_relations.'));
							if (($row[$field] ?? null) !== $value) {
								$matches = false;
								break;
							}

							continue;
						}

						if (($row[$key] ?? null) !== $value) {
							$matches = false;
							break;
						}
					}

					if ($matches === true) {
						$out[] = $this->wrap($row);
					}
				}

				return $out;
			}

			/**
			 * Persist an object.
			 *
			 * @param array<string, mixed> $object Payload
			 * @param string $register Register slug
			 * @param string $schema Schema slug
			 * @param string|null $uuid Target uuid
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object = [], string $register = '', string $schema = '', ?string $uuid = null): array {
				$this->assertSchemaExists($schema);
				return $object;
			}
		};
	}//end objectServiceDouble()

	/**
	 * A DI container that dispatches on the requested id.
	 *
	 * Deliberately NOT a blanket "return one mock for everything" double: such a
	 * container silently satisfies dependencies the code did not have when the
	 * test was written, so the test stops describing the wiring it claims to.
	 * Anything not explicitly registered is a hard failure.
	 *
	 * @param array<string, mixed> $services Services keyed by container id
	 *
	 * @return ContainerInterface
	 */
	private function containerFor(array $services): ContainerInterface {
		return new class($services) implements ContainerInterface {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $services Services keyed by container id
			 */
			public function __construct(
				private array $services,
			) {
			}

			/**
			 * Resolve a service by id.
			 *
			 * @param string $id Container id
			 *
			 * @return mixed
			 */
			public function get(string $id): mixed {
				if (array_key_exists($id, $this->services) === false) {
					throw new \RuntimeException(
						"Test container was asked for an unregistered id '{$id}'. "
						. 'Register it explicitly rather than widening the double.'
					);
				}

				return $this->services[$id];
			}

			/**
			 * Whether a service is registered.
			 *
			 * @param string $id Container id
			 *
			 * @return bool
			 */
			public function has(string $id): bool {
				return array_key_exists($id, $this->services);
			}
		};
	}//end containerFor()

	/**
	 * The container the decidesk motion stack actually resolves against.
	 *
	 * Each id is registered explicitly and the resolver dispatches on the id, so
	 * a dependency the code gains later surfaces as a loud failure here instead
	 * of being silently answered by a catch-all mock.
	 *
	 * @param object $objectService The OpenRegister ObjectService double
	 *
	 * @return ContainerInterface
	 */
	private function decideskContainer(object $objectService): ContainerInterface {
		$services = [
			'OCA\OpenRegister\Service\ObjectService' => $objectService,
			\OCA\Decidesk\Service\MotionNotifier::class => $this->createMock(
				\OCA\Decidesk\Service\MotionNotifier::class
			),
			\OCP\IAppConfig::class => $this->createMock(IAppConfig::class),
		];

		// MotionLinkResolver and MotionLifecycleTransitioner both take the
		// container itself, so they are built last and handed the very container
		// that will serve them.
		//
		// The transitioner is the REAL class, not a double: the assertions in
		// this file are about the failure modes of the state machine — an
		// unknown decisionType refused before the register is touched, a
		// decision of another type answering Not Found — and all of that logic
		// lives inside it. A double would make these tests assert against a
		// stand-in for the code under test.
		$self = $this->containerFor($services);
		$services[\OCA\Decidesk\Service\MotionLinkResolver::class] = new \OCA\Decidesk\Service\MotionLinkResolver(
			container: $self
		);
		$services[\OCA\Decidesk\Lifecycle\MotionLifecycleTransitioner::class] = new \OCA\Decidesk\Lifecycle\MotionLifecycleTransitioner(
			container: $self,
			logger: new NullLogger(),
			guard: new \OCA\Decidesk\Lifecycle\DecisionTransitionGuard(),
		);

		return $this->containerFor($services);
	}//end decideskContainer()

	/**
	 * Reset the schema-call recorder.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->schemaCalls = new \ArrayObject();

	}//end setUp()

	/**
	 * An unknown motion on the amendment-order endpoint answers 404, not 500.
	 *
	 * The caller here is a system admin, so the chair guard passes and execution
	 * genuinely reaches the amendment resolution — a non-admin would be refused
	 * at the guard and never exercise the defect.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function testUnknownMotionOnAmendmentOrderIs404NotServerError(): void {
		$response = $this->callAmendmentOrder(motionId: '00000000-0000-0000-0000-000000000000');

		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$response->getStatus(),
			'An unknown motion must be Not Found; a schema lookup escaping the '
			. 'controller renders as 500 instead.'
		);

	}//end testUnknownMotionOnAmendmentOrderIs404NotServerError()

	/**
	 * The amendment-order endpoint never asks the register for a retired slug.
	 *
	 * The status assertion above can be satisfied by accident — a lenient
	 * ObjectService could answer "nothing found" for a dead schema. This pins the
	 * cause: every schema the request touches must be one the register carries.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function testAmendmentOrderNeverAddressesARetiredSchema(): void {
		$this->callAmendmentOrder(motionId: '00000000-0000-0000-0000-000000000000');

		$requested = array_values(array_unique((array)$this->schemaCalls));

		self::assertNotSame([], $requested, 'The request must actually reach the register.');
		self::assertSame(
			[],
			array_values(array_intersect($requested, self::RETIRED_SCHEMAS)),
			'ADR-005 retired motion/amendment/resolution; requested: ' . implode(', ', $requested)
		);

	}//end testAmendmentOrderNeverAddressesARetiredSchema()

	/**
	 * Drive MotionController::amendmentOrder() over the real service stack.
	 *
	 * @param string $motionId The motion UUID to order amendments on
	 *
	 * @return JSONResponse The controller's response
	 */
	private function callAmendmentOrder(string $motionId): JSONResponse {
		$container = $this->decideskContainer($this->objectServiceDouble());

		$motionService = new MotionService(
			container: $container,
			logger: new NullLogger(),
			userManager: $this->createMock(IUserManager::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['orderedAmendmentIds' => ['amendment-a']]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		// No chair_group configured, so the guard falls back to system admin.
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);

		$controller = new MotionController(
			request: $request,
			motionService: $motionService,
			userSession: $session,
			groupManager: $groupManager,
			appConfig: $appConfig,
			participantResolver: $this->createMock(ParticipantResolver::class),
		);

		return $controller->amendmentOrder(id: $motionId);
	}//end callAmendmentOrder()

	/**
	 * An out-of-vocabulary subjectType is a 400, not a 500.
	 *
	 * `resolution` is a real `decisionType` but not a votable round subject, so
	 * it is precisely the value that must be refused as a client error. The
	 * refusal is composed exactly as the endpoint composes it: the preflight
	 * raises, and VotingErrorResponder — the mapper VotingController::open()
	 * wraps the open handler in — turns that into the response.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testInvalidSubjectTypeIs400NotServerError(): void {
		$preflight = $this->buildPreflight();
		$responder = new VotingErrorResponder(logger: new NullLogger());

		$response = $responder->badRequest(
			static function () use ($preflight): JSONResponse {
				$preflight->resolveRules(
					governanceBodyId: null,
					voteThreshold: null,
					abstentionHandling: null,
					tieBreakRule: null,
					subjectType: 'resolution',
				);

				return new JSONResponse([], Http::STATUS_CREATED);
			}
		);

		self::assertSame(
			Http::STATUS_BAD_REQUEST,
			$response->getStatus(),
			'An unknown subjectType is a client error; anything that escapes the '
			. 'responder renders as 500 instead.'
		);

	}//end testInvalidSubjectTypeIs400NotServerError()

	/**
	 * The subjectType enum is refused before the register is touched at all.
	 *
	 * Fail closed means fail early: a value outside the vocabulary must never
	 * reach a schema lookup, because a lookup on a retired slug raises an
	 * exception the 400 mapper does not catch.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testInvalidSubjectTypeIsRefusedBeforeAnySchemaLookup(): void {
		$preflight = $this->buildPreflight();

		try {
			$preflight->resolveRules(
				governanceBodyId: null,
				voteThreshold: null,
				abstentionHandling: null,
				tieBreakRule: null,
				subjectType: 'resolution',
			);
			self::fail('resolveRules() must reject an unknown subjectType.');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('subjectType', $e->getMessage());
		}

		self::assertSame(
			[],
			array_values(array_intersect((array)$this->schemaCalls, self::RETIRED_SCHEMAS)),
			'A rejected subjectType must not have addressed a retired schema.'
		);

	}//end testInvalidSubjectTypeIsRefusedBeforeAnySchemaLookup()

	/**
	 * A subjectType outside the discriminator vocabulary is a client error.
	 *
	 * MotionService::transitionLifecycle() is reached from the open-round path
	 * via VotingRoundPreflight::transitionSubjectToVoting(). Before ADR-005 the
	 * objectType argument was a schema slug and an unknown value became a schema
	 * lookup — an uncatchable 500. It is now a discriminator value, and an
	 * unknown one must be refused as InvalidArgumentException (400).
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function testTransitionLifecycleRejectsAnUnknownObjectType(): void {
		$container = $this->decideskContainer($this->objectServiceDouble());

		$motionService = new MotionService(
			container: $container,
			logger: new NullLogger(),
			userManager: $this->createMock(IUserManager::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/Unknown objectType/');

		$motionService->transitionLifecycle(
			objectId: 'decision-1',
			objectType: 'resolution',
			newState: 'voting',
			actorId: 'admin',
		);

	}//end testTransitionLifecycleRejectsAnUnknownObjectType()

	/**
	 * A motion decision transitions; a decision of another type is Not Found.
	 *
	 * The fold means an id alone no longer proves the type, so the discriminator
	 * has to carry that weight — otherwise /api/motions/{id}/transition would
	 * happily drive the lifecycle of a contract or an appointment.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function testTransitionLifecycleRefusesADecisionOfAnotherType(): void {
		$container = $this->decideskContainer(
			$this->objectServiceDouble(
				[
					'decision-1' => [
						'id' => 'decision-1',
						'decisionType' => 'contract',
						'lifecycle' => 'proposed',
					],
				]
			)
		);

		$motionService = new MotionService(
			container: $container,
			logger: new NullLogger(),
			userManager: $this->createMock(IUserManager::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/not found/');

		$motionService->transitionLifecycle(
			objectId: 'decision-1',
			objectType: 'motion',
			newState: 'deliberating',
			actorId: 'admin',
		);

	}//end testTransitionLifecycleRefusesADecisionOfAnotherType()

	/**
	 * An unknown meetingId is refused by the chair guard, not raised as a 500.
	 *
	 * VotingRoundGuard's contract is "fail closed: any failure yields a 401/403",
	 * and it reaches ParticipantResolver::resolveGovernanceBodyId(), which
	 * documents "returns null when the meeting cannot be found". OpenRegister's
	 * `find()` THROWS for an unknown id rather than returning null, so that
	 * documented answer was never delivered and the exception escaped the guard.
	 * Measured live: POST /api/voting-rounds with `"meetingId": "meeting-x"`
	 * answered 500 before the subjectType was ever validated.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testUnknownMeetingResolvesToNoGovernanceBodyNotAnEscapingError(): void {
		$resolver = new \OCA\Decidesk\Service\ParticipantResolver(
			container: $this->decideskContainer($this->objectServiceDouble()),
			logger: new NullLogger(),
		);

		self::assertNull(
			$resolver->resolveGovernanceBodyId(meetingId: 'meeting-x'),
			'An unknown meeting must resolve to no governance body; an escaping '
			. 'DoesNotExistException renders as 500 from inside an auth guard.'
		);

	}//end testUnknownMeetingResolvesToNoGovernanceBodyNotAnEscapingError()

	/**
	 * Build a VotingRoundPreflight over the strict ObjectService double.
	 *
	 * @return VotingRoundPreflight
	 */
	private function buildPreflight(): VotingRoundPreflight {
		$container = $this->decideskContainer($this->objectServiceDouble());

		$templateService = $this->createMock(ProcessTemplateService::class);
		$templateService->method('resolveVotingRuleForBody')->willReturn([]);

		return new VotingRoundPreflight(
			logger: new NullLogger(),
			motionService: new MotionService(
				logger: new NullLogger(),
			objectService: $this->createMock(ObjectServiceInterface::class),
		),
			participantResolver: $this->createMock(ParticipantResolver::class),
			templateService: $templateService,
		);

	}//end buildPreflight()
}//end class
