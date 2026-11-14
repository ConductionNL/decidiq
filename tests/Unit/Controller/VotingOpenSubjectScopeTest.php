<?php

/**
 * Opening a voting round is bound to the meeting the caller's role was checked on.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\VotingController;
use OCA\Decidiq\Service\OriPublicationService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\ProxyDelegationService;
use OCA\Decidiq\Service\VotingErrorResponder;
use OCA\Decidiq\Service\VotingOpenRequestHandler;
use OCA\Decidiq\Service\VotingRoundGuard;
use OCA\Decidiq\Service\VotingRoundRequest;
use OCA\Decidiq\Service\VotingService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * POST /api/voting-rounds with forged bodies: a chair of meeting A must not
 * open a round on, or revote a round of, a subject outside meeting A.
 *
 * @covers \OCA\Decidiq\Controller\VotingController
 * @covers \OCA\Decidiq\Service\VotingRoundGuard
 * @uses   \OCA\Decidiq\Service\VotingOpenRequestHandler
 * @uses   \OCA\Decidiq\Service\VotingOpenRequestParser
 * @uses   \OCA\Decidiq\Service\VotingRoundRules
 * @uses   \OCA\Decidiq\Service\VotingRoundRequest
 * @uses   \OCA\Decidiq\Service\VotingErrorResponder
 * @uses   \OCA\Decidiq\Service\ProxyDelegationService
 */
class VotingOpenSubjectScopeTest extends TestCase {

	/**
	 * Stored objects by id, as their jsonSerialize() data.
	 */
	private const OBJECTS = [
		'motion-a' => ['id' => 'motion-a', 'decisionType' => 'motion', 'meeting' => 'meeting-a'],
		'motion-b' => ['id' => 'motion-b', 'decisionType' => 'motion', 'meeting' => 'meeting-b'],
		'motion-rel-a' => [
			'id' => 'motion-rel-a',
			'decisionType' => 'motion',
			'relations' => [['schema' => 'meeting', 'id' => 'meeting-a']],
		],
		'motion-expanded-a' => ['id' => 'motion-expanded-a', 'decisionType' => 'motion', 'meeting' => ['id' => 'meeting-a']],
		'motion-no-meeting' => ['id' => 'motion-no-meeting', 'decisionType' => 'motion'],
		'resolution-a' => ['id' => 'resolution-a', 'decisionType' => 'resolution', 'meeting' => 'meeting-a'],
		'amend-a' => ['id' => 'amend-a', 'decisionType' => 'amendment', 'amends' => 'motion-a'],
		'amend-b' => ['id' => 'amend-b', 'decisionType' => 'amendment', 'amends' => 'motion-b'],
		'round-tied-a' => [
			'id' => 'round-tied-a',
			'result' => 'tied',
			'tieBreakRule' => 'revote',
			'relations' => [['schema' => 'motion', 'id' => 'motion-a']],
		],
		'round-tied-b' => [
			'id' => 'round-tied-b',
			'result' => 'tied',
			'tieBreakRule' => 'revote',
			'relations' => [['schema' => 'motion', 'id' => 'motion-b']],
		],
		'round-tied-amend-a' => [
			'id' => 'round-tied-amend-a',
			'result' => 'tied',
			'tieBreakRule' => 'revote',
			'relations' => [['schema' => 'amendment', 'id' => 'amend-a']],
		],
		'round-tied-no-subject' => ['id' => 'round-tied-no-subject', 'result' => 'tied', 'tieBreakRule' => 'revote'],
		'round-tied-participant-first-a' => [
			'id' => 'round-tied-participant-first-a',
			'result' => 'tied',
			'tieBreakRule' => 'revote',
			'relations' => [['schema' => 'participant', 'id' => 'p-1'], ['schema' => 'motion', 'id' => 'motion-a']],
		],
	];

	/**
	 * Request bodies, the acting user, the expected status and the subject passed on.
	 *
	 * @return array<string, array{0: string, 1: array<string,mixed>, 2: int, 3: string|null}>
	 */
	public static function bodies(): array {
		return [
			'own motion' => ['chair-a', ['meetingId' => 'meeting-a', 'motionId' => 'motion-a'], 201, 'motion-a'],
			'own motion, meeting as relation' => ['chair-a', ['meetingId' => 'meeting-a', 'motionId' => 'motion-rel-a'], 201, 'motion-rel-a'],
			'own motion, expanded meeting' => ['chair-a', ['meetingId' => 'meeting-a', 'motionId' => 'motion-expanded-a'], 201, 'motion-expanded-a'],
			'other chair on own meeting' => ['chair-b', ['meetingId' => 'meeting-b', 'motionId' => 'motion-b'], 201, 'motion-b'],
			'motion of another meeting' => ['chair-a', ['meetingId' => 'meeting-a', 'motionId' => 'motion-b'], 403, null],
			'motion of another meeting, forged userId' => [
				'chair-a',
				['meetingId' => 'meeting-a', 'motionId' => 'motion-b', 'userId' => 'chair-b', 'uid' => 'chair-b'],
				403,
				null,
			],
			'other meeting named in body' => ['chair-a', ['meetingId' => 'meeting-b', 'motionId' => 'motion-b'], 403, null],
			'other meeting named, forged userId' => [
				'chair-a',
				['meetingId' => 'meeting-b', 'motionId' => 'motion-b', 'userId' => 'chair-b'],
				403,
				null,
			],
			'motion without a meeting' => ['chair-a', ['meetingId' => 'meeting-a', 'motionId' => 'motion-no-meeting'], 403, null],
			'unknown motion' => ['chair-a', ['meetingId' => 'meeting-a', 'motionId' => 'motion-missing'], 403, null],
			'decision in the meeting that is no motion' => ['chair-a', ['meetingId' => 'meeting-a', 'motionId' => 'resolution-a'], 403, null],
			'lookup fails' => ['chair-a', ['meetingId' => 'meeting-a', 'motionId' => 'motion-boom'], 403, null],
			'own amendment' => [
				'chair-a',
				['meetingId' => 'meeting-a', 'motionId' => 'amend-a', 'subjectType' => 'amendment'],
				201,
				'amend-a',
			],
			'amendment of another meeting' => [
				'chair-a',
				['meetingId' => 'meeting-a', 'motionId' => 'amend-b', 'subjectType' => 'amendment'],
				403,
				null,
			],
			'motion id sent as amendment' => [
				'chair-a',
				['meetingId' => 'meeting-a', 'motionId' => 'motion-a', 'subjectType' => 'amendment'],
				403,
				null,
			],
			'revote of own round' => [
				'chair-a',
				['meetingId' => 'meeting-a', 'motionId' => 'motion-a', 'revoteOfRound' => 'round-tied-a'],
				201,
				'motion-a',
			],
			'revote of own amendment round' => [
				'chair-a',
				['meetingId' => 'meeting-a', 'motionId' => 'amend-a', 'subjectType' => 'amendment', 'revoteOfRound' => 'round-tied-amend-a'],
				201,
				'amend-a',
			],
			'revote of own round, participants listed first' => [
				'chair-a',
				['meetingId' => 'meeting-a', 'motionId' => 'motion-a', 'revoteOfRound' => 'round-tied-participant-first-a'],
				201,
				'motion-a',
			],
			'revote of a round of another meeting' => [
				'chair-a',
				['meetingId' => 'meeting-a', 'motionId' => 'motion-a', 'revoteOfRound' => 'round-tied-b'],
				403,
				null,
			],
			'revote of a round on another subject' => [
				'chair-a',
				['meetingId' => 'meeting-a', 'motionId' => 'motion-a', 'revoteOfRound' => 'round-tied-amend-a'],
				403,
				null,
			],
			'revote of a round without a subject' => [
				'chair-a',
				['meetingId' => 'meeting-a', 'motionId' => 'motion-a', 'revoteOfRound' => 'round-tied-no-subject'],
				403,
				null,
			],
			'revote lookup fails' => [
				'chair-a',
				['meetingId' => 'meeting-a', 'motionId' => 'motion-a', 'revoteOfRound' => 'round-boom'],
				403,
				null,
			],
			'revote of an unknown round is left to the service' => [
				'chair-a',
				['meetingId' => 'meeting-a', 'motionId' => 'motion-a', 'revoteOfRound' => 'round-missing'],
				201,
				'motion-a',
			],
			'revote of a round OpenRegister reports missing is left to the service' => [
				'chair-a',
				['meetingId' => 'meeting-a', 'motionId' => 'motion-a', 'revoteOfRound' => 'round-gone'],
				201,
				'motion-a',
			],
			'motion OpenRegister reports missing' => ['chair-a', ['meetingId' => 'meeting-a', 'motionId' => 'motion-gone'], 403, null],
			'revote id that is not a string' => [
				'chair-a',
				['meetingId' => 'meeting-a', 'motionId' => 'motion-a', 'revoteOfRound' => ['round-tied-b']],
				201,
				'motion-a',
			],
			'missing motionId stays a 400' => ['chair-a', ['meetingId' => 'meeting-a'], 400, null],
			'unknown subjectType stays a 400' => [
				'chair-a',
				['meetingId' => 'meeting-a', 'motionId' => 'motion-b', 'subjectType' => 'resolution'],
				400,
				null,
			],
		];

	}//end bodies()

	/**
	 * Each body gets the expected status, and only an allowed one reaches the service.
	 *
	 * @param string $actingUid The signed-in Nextcloud user
	 * @param array<string,mixed> $body The request body
	 * @param int $status The expected HTTP status
	 * @param string|null $subject The motionId the service receives, or null when it is not called
	 *
	 * @dataProvider bodies
	 *
	 * @return void
	 */
	public function testOpenIsBoundToTheCheckedMeeting(string $actingUid, array $body, int $status, ?string $subject): void {
		$votingService = $this->createMock(VotingService::class);
		$opened = [];
		$votingService->method('openVotingRound')->willReturnCallback(
			function (mixed ...$args) use (&$opened): array {
				if ($args[0] instanceof VotingRoundRequest) {
					$opened[] = [$args[0]->motionId, $args[0]->meetingId];
				} else {
					$opened[] = [$args[0], $args[1]];
				}

				return ['id' => 'new-round'];
			}
		);

		$response = $this->controller(actingUid: $actingUid, body: $body, votingService: $votingService)->open();

		self::assertSame($status, $response->getStatus(), json_encode($response->getData()));
		if ($subject === null) {
			self::assertSame([], $opened);
			return;
		}

		self::assertCount(1, $opened);
		self::assertSame([$subject, $body['meetingId']], $opened[0]);

	}//end testOpenIsBoundToTheCheckedMeeting()

	/**
	 * Build the controller with the real guard, a role table that answers for
	 * the acting user only, and an object store over OBJECTS.
	 *
	 * @param string $actingUid The signed-in Nextcloud user
	 * @param array<string,mixed> $body The request body
	 * @param VotingService $votingService The voting service double
	 *
	 * @return VotingController
	 */
	private function controller(string $actingUid, array $body, VotingService $votingService): VotingController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($actingUid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($body);

		$roles = ['meeting-a' => 'chair-a', 'meeting-b' => 'chair-b'];
		$resolver = $this->createMock(ParticipantResolver::class);
		$resolver->method('hasRole')->willReturnCallback(
			static fn (string $meetingId, string $nextcloudUid, array $roles_ = []): bool => ($roles[$meetingId] ?? null) === $nextcloudUid
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				'OCA\OpenRegister\Service\ObjectService' => $this->objectStore(),
				default => throw new RuntimeException('unexpected service ' . $id),
			}
		);

		$logger = $this->createMock(LoggerInterface::class);

		return new VotingController(
			request: $request,
			votingService: $votingService,
			oriService: $this->createMock(OriPublicationService::class),
			userSession: $session,
			guard: new VotingRoundGuard(
				userSession: $session,
				groupManager: $this->createMock(IGroupManager::class),
				appConfig: $this->createMock(IAppConfig::class),
				participantResolver: $resolver,
				container: $container,
			),
			openHandler: new VotingOpenRequestHandler(votingService: $votingService),
			proxyService: new ProxyDelegationService(
				container: $container,
				logger: $logger,
				objectService: $this->createMock(ObjectServiceInterface::class),
				appConfig: $this->createMock(originalClassName: IAppConfig::class),
			),
			errors: new VotingErrorResponder(logger: $logger),
			written: $this->createMock(\OCA\Decidiq\Kernel\Contracts\WrittenRoundServiceInterface::class),
		);

	}//end controller()

	/**
	 * An object store that finds OBJECTS by id and throws for ids ending in "-boom".
	 *
	 * Like OpenRegister's ObjectService::find(int|string $id), it refuses any other id type.
	 *
	 * @return object
	 */
	private function objectStore(): object {
		return new class(self::OBJECTS) {
			/**
			 * @param array<string, array<string,mixed>> $objects Stored objects by id
			 */
			public function __construct(private readonly array $objects) {
			}

			/**
			 * Find one object by id.
			 *
			 * @param int|string $id The object id
			 * @param mixed $register The register (unused)
			 * @param mixed $schema The schema (unused)
			 *
			 * @return object|null
			 */
			public function find(int|string $id, mixed $register = null, mixed $schema = null): ?object {
				if (str_ends_with((string)$id, '-boom') === true) {
					throw new RuntimeException('lookup failed');
				}

				if (str_ends_with((string)$id, '-gone') === true) {
					throw new \OCP\AppFramework\Db\DoesNotExistException('Object not found');
				}

				if (isset($this->objects[$id]) === false) {
					return null;
				}

				$data = $this->objects[$id];
				return new class($data) {
					/**
					 * @param array<string,mixed> $data The serialised object
					 */
					public function __construct(private readonly array $data) {
					}

					/**
					 * @return array<string,mixed>
					 */
					public function jsonSerialize(): array {
						return $this->data;
					}
				};
			}
		};

	}//end objectStore()
}//end class
