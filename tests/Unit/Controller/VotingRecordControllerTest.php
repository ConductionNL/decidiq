<?php

/**
 * Unit tests for VotingRecordController: GET /api/people/{personId}/voting-record.
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
 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\VotingRecordController;
use OCA\Decidiq\Service\VotingRecordService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for VotingRecordController.
 *
 * @covers \OCA\Decidiq\Controller\VotingRecordController
 *
 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
 */
class VotingRecordControllerTest extends TestCase {

	/**
	 * Build the controller.
	 *
	 * @param bool       $loggedIn Whether someone is logged in
	 * @param array|null $person   The person the caller can read, or null
	 * @param array      $record   The record the service returns
	 *
	 * @return VotingRecordController
	 */
	private function makeController(bool $loggedIn, ?array $person, array $record = []): VotingRecordController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($loggedIn ? $this->createMock(IUser::class) : null);
		$service = $this->createMock(VotingRecordService::class);
		$service->method('mayReadPerson')->willReturn($person !== null);
		$service->method('forPerson')->willReturn($record);

		return new VotingRecordController($this->createMock(IRequest::class), $service, $session);
	}//end makeController()

	/**
	 * A signed-in user reads a person's record.
	 *
	 * @return void
	 */
	public function testASignedInUserReadsTheRecord(): void {
		$record = [['vote' => 'v-1', 'date' => '2025-04-10T21:01:00Z', 'decision' => null, 'choice' => 'for', 'result' => 'adopted', 'party' => 'D66', 'body' => null]];
		$response = $this->makeController(loggedIn: true, person: ['name' => 'Marie Janssen'], record: $record)->forPerson(personId: 'marie');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['personId' => 'marie', 'votes' => $record], $response->getData());
	}//end testASignedInUserReadsTheRecord()

	/**
	 * A person the caller cannot read answers 404.
	 *
	 * @return void
	 */
	public function testAnUnknownPersonIsNotFound(): void {
		$response = $this->makeController(loggedIn: true, person: null)->forPerson(personId: 'ghost');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnUnknownPersonIsNotFound()

	/**
	 * Without a session the record is refused.
	 *
	 * @return void
	 */
	public function testAnonymousIsRefused(): void {
		$response = $this->makeController(loggedIn: false, person: ['name' => 'Marie Janssen'])->forPerson(personId: 'marie');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnonymousIsRefused()

	/**
	 * The route reaches the controller.
	 *
	 * @return void
	 */
	public function testTheRouteReachesTheController(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$byName = array_column($routes['routes'], 'url', 'name');

		self::assertSame('/api/people/{personId}/voting-record', ($byName['votingRecord#forPerson'] ?? null));
		self::assertTrue(method_exists(VotingRecordController::class, 'forPerson'));
	}//end testTheRouteReachesTheController()
}//end class
