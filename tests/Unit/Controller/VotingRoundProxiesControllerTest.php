<?php

/**
 * Unit tests for VotingRoundProxiesController.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\VotingRoundProxiesController;
use OCA\Decidiq\Service\ParticipantUuidLookup;
use OCA\Decidiq\Service\ProxyHoldings;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Decidiq\Controller\VotingRoundProxiesController
 * @covers \OCA\Decidiq\Service\ProxyHoldings::forUser
 * @uses   \OCA\Decidiq\Service\ProxyHoldings
 *
 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
 */
class VotingRoundProxiesControllerTest extends TestCase {

	/**
	 * The controller for a signed-in user (or nobody), a participant (or none) and a round (or none).
	 *
	 * @param string|null              $uid         The Nextcloud UID, or null for no session
	 * @param string|null              $participant The participant the UID resolves to
	 * @param array<string, mixed>|null $round       The stored round
	 *
	 * @return VotingRoundProxiesController
	 */
	private function controller(?string $uid, ?string $participant, ?array $round): VotingRoundProxiesController {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$lookup = $this->createMock(originalClassName: ParticipantUuidLookup::class);
		$lookup->method('forNextcloudUser')->willReturn($participant);

		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$entity  = null;
		if ($round !== null) {
			$entity = $this->createMock(originalClassName: ObjectEntity::class);
			$entity->method('jsonSerialize')->willReturn($round);
		}

		$objects->method('find')->willReturn($entity);

		return new VotingRoundProxiesController(
			request: $this->createMock(originalClassName: IRequest::class),
			userSession: $session,
			holdings: new ProxyHoldings(objectService: $objects, participants: $lookup, logger: new NullLogger())
		);
	}//end controller()

	/**
	 * A holder sees the grant they hold.
	 *
	 * @return void
	 */
	public function testAHolderSeesTheProxyTheyHold(): void {
		$grant = ['title' => 'Proxy', 'body' => (string)json_encode(['fromParticipantId' => 'p-a', 'toParticipantId' => 'p-me'])];

		$response = $this->controller(uid: 'me', participant: 'p-me', round: ['notes' => [$grant]])->show(id: 'round-1');

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: 'p-me', actual: $response->getData()['participantId']);
		self::assertSame(expected: 'p-a', actual: $response->getData()['held'][0]['participantId']);
	}//end testAHolderSeesTheProxyTheyHold()

	/**
	 * A user who is no participant holds and gives nothing.
	 *
	 * @return void
	 */
	public function testANonParticipantHoldsNothing(): void {
		$response = $this->controller(uid: 'guest', participant: null, round: null)->show(id: 'round-1');

		self::assertSame(expected: ['participantId' => null, 'held' => [], 'granted' => null], actual: $response->getData());
	}//end testANonParticipantHoldsNothing()

	/**
	 * An unknown round is 404, no session is 401.
	 *
	 * @return void
	 */
	public function testAnUnknownRoundIs404AndNoSessionIs401(): void {
		$missing = $this->controller(uid: 'me', participant: 'p-me', round: null)->show(id: 'nope');
		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $missing->getStatus());
		$anonymous = $this->controller(uid: null, participant: null, round: null)->show(id: 'round-1');
		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $anonymous->getStatus());
	}//end testAnUnknownRoundIs404AndNoSessionIs401()

	/**
	 * The action carries #[NoAdminRequired].
	 *
	 * @return void
	 */
	public function testShowHasNoAdminRequired(): void {
		$attributes = (new \ReflectionMethod(VotingRoundProxiesController::class, 'show'))->getAttributes(NoAdminRequired::class);
		self::assertNotEmpty(actual: $attributes);
	}//end testShowHasNoAdminRequired()
}//end class
