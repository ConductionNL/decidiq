<?php

/**
 * An incoming document goes public only once the council confirmed its routing at a public meeting,
 * and a resident who wrote it is never named in what is published.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/ingekomen-stukken-register/spec.md#requirement-req-005-public-publication-with-woo-aware-anonymisation
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\PublicationEligibilityService;
use OCA\Decidiq\Service\PublicationPayloadService;
use OCA\Decidiq\Service\PublicationStaffGuard;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Eligibility, payload and staff check of the `incoming-document` source type.
 */
class IncomingDocumentPublicationTest extends TestCase {

	private const STUK = 'stuk-1';

	private const LIS = 'lis-item-1';

	private const MEETING = 'raad-2026-11-12';

	/**
	 * An object store answering by "schema/id".
	 *
	 * @param array<string, array<string, mixed>> $objects Object data by "schema/id".
	 *
	 * @return ObjectServiceInterface
	 */
	private function store(array $objects): ObjectServiceInterface {
		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('find')->willReturnCallback(
			function (string|int $id, ?array $extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null) use ($objects): ?ObjectEntity {
				if (isset($objects[$schema . '/' . $id]) === false) {
					return null;
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($objects[$schema . '/' . $id]);
				return $entity;
			}
		);

		return $store;
	}//end store()

	/**
	 * A stuk, its list agenda item and that item's meeting.
	 *
	 * @param array<string, mixed> $stuk     Overrides on the stuk.
	 * @param bool                 $isPublic Whether the meeting is public.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function objects(array $stuk=[], bool $isPublic=true): array {
		return [
			'ingekomen-stuk/' . self::STUK => array_merge(
				[
					'title' => 'Brief over de herinrichting van het Marktplein',
					'sender' => 'J. Jansen',
					'senderType' => 'natural-person',
					'receivedAt' => '2026-10-20',
					'category' => 'letter-resident',
					'summary' => 'J. Jansen, Dorpsstraat 1, vraagt om meer bankjes; bereikbaar via 06-12345678.',
					'routingAdvice' => 'voor-kennisgeving-aannemen',
					'listAgendaItem' => self::LIS,
					'lifecycle' => 'routing-determined',
				],
				$stuk
			),
			'agenda-item/' . self::LIS => ['title' => 'Lijst ingekomen stukken', 'meeting' => self::MEETING],
			'meeting/' . self::MEETING => ['title' => 'Raadsvergadering', 'isPublic' => $isPublic],
		];
	}//end objects()

	/**
	 * The eligibility service over the given objects.
	 *
	 * @param array<string, array<string, mixed>> $objects Object data by "schema/id".
	 *
	 * @return PublicationEligibilityService
	 */
	private function eligibility(array $objects): PublicationEligibilityService {
		return new PublicationEligibilityService($this->createMock(LoggerInterface::class), objectService: $this->store($objects));
	}//end eligibility()

	/**
	 * A confirmed or disposed stuk of a public meeting is eligible.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function eligibleStates(): array {
		return ['routing confirmed' => ['routing-determined'], 'disposed' => ['disposed']];
	}//end eligibleStates()

	/**
	 * @param string $lifecycle The stuk's state.
	 *
	 * @return void
	 */
	#[DataProvider('eligibleStates')]
	public function testAConfirmedStukOfAPublicMeetingIsEligible(string $lifecycle): void {
		$data = $this->eligibility($this->objects(['lifecycle' => $lifecycle]))->assertEligible('incoming-document', self::STUK);

		self::assertSame($lifecycle, $data['lifecycle']);
	}//end testAConfirmedStukOfAPublicMeetingIsEligible()

	/**
	 * A stuk the council has not confirmed is refused.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function unconfirmedStates(): array {
		return ['registered' => ['registered'], 'agendised' => ['agendised'], 'deferred' => ['deferred']];
	}//end unconfirmedStates()

	/**
	 * @param string $lifecycle The stuk's state.
	 *
	 * @return void
	 */
	#[DataProvider('unconfirmedStates')]
	public function testAnUnconfirmedStukIsRefused(string $lifecycle): void {
		$this->expectExceptionMessage('whose routing the council confirmed');

		$this->eligibility($this->objects(['lifecycle' => $lifecycle]))->assertEligible('incoming-document', self::STUK);
	}//end testAnUnconfirmedStukIsRefused()

	/**
	 * A stuk confirmed at a closed meeting, or placed on no list, is refused.
	 *
	 * @return void
	 */
	public function testAStukOfAClosedOrNoMeetingIsRefused(): void {
		try {
			$this->eligibility($this->objects([], false))->assertEligible('incoming-document', self::STUK);
			self::fail('a stuk of a closed meeting was eligible');
		} catch (\Exception $closed) {
			self::assertStringContainsString('public meeting', $closed->getMessage());
		}

		$this->expectExceptionMessage('public meeting');
		$this->eligibility($this->objects(['listAgendaItem' => '']))->assertEligible('incoming-document', self::STUK);
	}//end testAStukOfAClosedOrNoMeetingIsRefused()

	/**
	 * A resident is "Inwoner" in the payload and their name, address and number appear nowhere in it.
	 *
	 * @return void
	 */
	public function testAResidentSenderIsAnonymised(): void {
		$payload = PublicationPayloadService::buildIncomingDocumentPayload(source: $this->objects()['ingekomen-stuk/' . self::STUK], version: 1);
		$encoded = (string)json_encode($payload);

		self::assertSame('Inwoner', $payload['sender']);
		self::assertStringNotContainsString('Jansen', $encoded);
		self::assertStringNotContainsString('Dorpsstraat', $encoded);
		self::assertStringNotContainsString('06-12345678', $encoded);
		self::assertSame(
			[
				'oriType' => 'IngekomenStuk',
				'schemaOrgType' => 'DigitalDocument',
				'payloadVersion' => 1,
				'title' => 'Brief over de herinrichting van het Marktplein',
				'category' => 'letter-resident',
				'receivedAt' => '2026-10-20',
				'routing' => 'voor-kennisgeving-aannemen',
				'sender' => 'Inwoner',
			],
			$payload
		);
	}//end testAResidentSenderIsAnonymised()

	/**
	 * An organisation or governing body is named as it is; the same stuk flips with its sender type.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function senders(): array {
		return [
			'organisation' => ['organisation', 'Stichting Dorpsbelang'],
			'governing body' => ['governingBody', 'Stichting Dorpsbelang'],
			'resident' => ['natural-person', 'Inwoner'],
			'unknown kind' => ['someone', 'Inwoner'],
			'no kind' => ['', 'Inwoner'],
		];
	}//end senders()

	/**
	 * @param string $senderType The sender type.
	 * @param string $shown      The sender the payload carries.
	 *
	 * @return void
	 */
	#[DataProvider('senders')]
	public function testTheSenderFollowsItsType(string $senderType, string $shown): void {
		$source = $this->objects(['sender' => 'Stichting Dorpsbelang', 'senderType' => $senderType])['ingekomen-stuk/' . self::STUK];

		self::assertSame($shown, PublicationPayloadService::buildIncomingDocumentPayload(source: $source, version: 2)['sender']);
	}//end testTheSenderFollowsItsType()

	/**
	 * The staff check of a stuk asks for the chair or secretary of the meeting its list belongs to.
	 *
	 * @return void
	 */
	public function testTheStaffCheckUsesTheListsMeeting(): void {
		$asked = [];
		$resolver = $this->createMock(ParticipantResolver::class);
		$resolver->method('hasRole')->willReturnCallback(
			static function (string $meetingId, string $uid, array $roles) use (&$asked): bool {
				$asked[] = $meetingId;
				return $meetingId === self::MEETING;
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('griffier');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$guard = new PublicationStaffGuard($this->store($this->objects()), $resolver, $session, $this->createMock(IGroupManager::class));

		self::assertSame(PublicationStaffGuard::ALLOWED, $guard->checkSource('incoming-document', self::STUK));
		self::assertSame([self::MEETING], $asked);

		$unplaced = new PublicationStaffGuard(
			$this->store($this->objects(['listAgendaItem' => ''])),
			$resolver,
			$session,
			$this->createMock(IGroupManager::class)
		);
		self::assertSame(PublicationStaffGuard::FORBIDDEN, $unplaced->checkSource('incoming-document', self::STUK));
	}//end testTheStaffCheckUsesTheListsMeeting()
}//end class
