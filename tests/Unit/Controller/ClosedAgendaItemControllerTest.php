<?php

/**
 * Unit tests for the closed agenda items endpoint (agenda-item-confidential-papers).
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

use OCA\Decidiq\Controller\ClosedAgendaItemController;
use OCA\Decidiq\Service\ClosedAgendaItemNumbers;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The endpoint's answers: 200 with the numbers, 401, 404 and 500.
 *
 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-005-a-non-public-item-stays-out-of-the-pack-and-the-public-site
 */
class ClosedAgendaItemControllerTest extends TestCase {

	/**
	 * Build the controller over a real service and a fake object service.
	 *
	 * @param bool                 $signedIn Whether a user is signed in.
	 * @param bool                 $meeting  Whether the meeting is readable.
	 * @param \Throwable|null      $failure  What findAll throws, if anything.
	 * @param LoggerInterface|null $logger   The logger.
	 *
	 * @return ClosedAgendaItemController
	 */
	private function controller(
		bool $signedIn=true,
		bool $meeting=true,
		?\Throwable $failure=null,
		?LoggerInterface $logger=null
	): ClosedAgendaItemController {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$found   = null;
		if ($meeting === true) {
			$found = $this->createMock(originalClassName: ObjectEntityInterface::class);
		}

		$objects->method('find')->willReturn($found);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config, bool $_rbac=true) use ($failure): array {
				if ($failure !== null) {
					throw $failure;
				}

				if ($_rbac === true) {
					return [];
				}

				return [['id' => 'b', 'meeting' => 'm-1', 'orderNumber' => 7, 'public' => false]];
			}
		);

		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(originalClassName: IUser::class);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ClosedAgendaItemController(
			request: $this->createMock(originalClassName: IRequest::class),
			userSession: $session,
			closedItems: new ClosedAgendaItemNumbers(objectService: $objects),
			logger: ($logger ?? $this->createMock(originalClassName: LoggerInterface::class))
		);
	}//end controller()

	/**
	 * A signed-in reader gets the numbers of the closed items hidden from them.
	 *
	 * @return void
	 */
	public function testItAnswersTheHiddenNumbers(): void {
		$response = $this->controller()->index(meetingId: 'm-1');

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: ['closedItems' => [['orderNumber' => 7, 'parentItem' => null]]], actual: $response->getData());
	}//end testItAnswersTheHiddenNumbers()

	/**
	 * Without a user it answers 401.
	 *
	 * @return void
	 */
	public function testItRefusesAnonymousCallers(): void {
		$this->assertSame(expected: 401, actual: $this->controller(signedIn: false)->index(meetingId: 'm-1')->getStatus());
	}//end testItRefusesAnonymousCallers()

	/**
	 * A meeting the caller cannot read answers 404.
	 *
	 * @return void
	 */
	public function testAnUnreadableMeetingAnswers404(): void {
		$response = $this->controller(meeting: false)->index(meetingId: 'm-1');

		$this->assertSame(expected: 404, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Meeting not found.'], actual: $response->getData());
	}//end testAnUnreadableMeetingAnswers404()

	/**
	 * A failed read answers 500 and is logged, without the error text.
	 *
	 * @return void
	 */
	public function testAFailedReadAnswers500AndIsLogged(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Decidiq: closed agenda items could not be read');

		$response = $this->controller(failure: new RuntimeException(message: 'db down'), logger: $logger)->index(meetingId: 'm-1');

		$this->assertSame(expected: 500, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'The closed agenda items could not be read.'], actual: $response->getData());
	}//end testAFailedReadAnswers500AndIsLogged()
}//end class
