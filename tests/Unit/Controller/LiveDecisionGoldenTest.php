<?php

/**
 * Golden tests for the live-decision recording endpoint's full responses.
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

use OCA\Decidiq\Controller\LiveMeetingController;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\LiveDecisionService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Pins status, body and request read order of every recordLiveDecision outcome.
 */
class LiveDecisionGoldenTest extends TestCase {

	/**
	 * Every outcome: [params, allowed, service failure, status, data, reads].
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function outcomes(): array {
		$full = [
			'title' => 'T',
			'text' => 'X',
			'outcome' => 'adopted',
			'legalBasis' => 'L',
			'decisionType' => 'resolution',
			'agendaItem' => 'item-5',
		];
		$allReads = array_keys($full);
		$missing = ['error' => 'Missing required fields: title, text, outcome'];
		$required = ['title', 'text', 'outcome'];
		return [
			'refused' => [$full, false, null, 403, null, []],
			'no title' => [['text' => 'X', 'outcome' => 'a'], true, null, 400, $missing, $required],
			'empty outcome' => [['title' => 'T', 'text' => 'X', 'outcome' => '0'], true, null, 400, $missing, $required],
			'recorded' => [$full, true, null, 200, ['slug' => 'slug-1', 'message' => 'Decision recorded successfully'], $allReads],
			'missing' => [$full, true, new MissingObjectException('Meeting not found'), 404, ['error' => 'Meeting not found'], $allReads],
			'closed' => [$full, true, new \RuntimeException('Meeting is not opened', 409), 409, ['error' => 'Meeting is not opened'], $allReads],
			'failure' => [$full, true, new \RuntimeException('boom', 3), 500, ['error' => 'Internal server error.'], $allReads],
		];

	}//end outcomes()

	/**
	 * The endpoint answers each outcome exactly as before.
	 *
	 * @param array<string,string> $params  The request parameters.
	 * @param bool                 $allowed Whether the caller chairs the meeting.
	 * @param \Exception|null      $failure What the service throws.
	 * @param int                  $status  The expected status.
	 * @param array|null           $data    The expected body (null: not pinned).
	 * @param array<int,string>    $reads   The expected parameter read order.
	 *
	 * @dataProvider outcomes
	 *
	 * @return void
	 */
	public function testOutcome(array $params, bool $allowed, ?\Exception $failure, int $status, ?array $data, array $reads): void {
		$seen = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key) use ($params, &$seen) {
				$seen[] = $key;
				return $params[$key] ?? null;
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('voorzitter');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$resolver = $this->createMock(ParticipantResolver::class);
		$resolver->method('hasRole')->willReturn($allowed);
		$service = $this->createMock(LiveDecisionService::class);
		$recorded = [];
		$service->method('recordDecision')->willReturnCallback(
			function (string $meetingId, array $decision) use ($failure, &$recorded): string {
				$recorded[] = [$meetingId, $decision];
				if ($failure !== null) {
					throw $failure;
				}

				return 'slug-1';
			}
		);
		$controller = new LiveMeetingController($request, $service, $session, $this->createMock(IGroupManager::class), $resolver);

		$response = $controller->recordLiveDecision(meetingId: 'meeting-1');

		self::assertSame($status, $response->getStatus());
		if ($data !== null) {
			self::assertSame($data, $response->getData());
		}

		self::assertSame($reads, $seen);
		if ($reads === []) {
			self::assertSame([], $recorded);
		}

		if (count($reads) === 6) {
			self::assertSame([['meeting-1', $params]], $recorded);
		}

	}//end testOutcome()
}//end class
