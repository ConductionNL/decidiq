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
	 * @param bool            $signedIn Whether a user is signed in.
	 * @param bool            $meeting  Whether the meeting is readable.
	 * @param \Throwable|null $failure  What findAll throws, if anything.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return ClosedAgendaItemController
	 */
	private function controller(bool $signedIn=true, bool $meeting=true, ?\Throwable $failure=null, ?LoggerInterface $logger=null): ClosedAgendaItemController {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn($meeting === true ? $this->createMock(ObjectEntityInterface::class) : null);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config, bool $_rbac=true) use ($failure): array {
				if ($failure !== null) {
					throw $failure;
				}

				$closed = ['id' => 'b', 'meeting' => 'm-1', 'orderNumber' => 7, 'public' => false];
				return $_rbac === true ? [] : [$closed];
			}
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		return new ClosedAgendaItemController(
			request: $this->createMock(IRequest::class),
			closedItems: new ClosedAgendaItemNumbers(objectService: $objects),
			userSession: $session,
			logger: ($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end controller()

	/**
	 * A signed-in reader gets the numbers of the closed items hidden from them.
	 *
	 * @return void
	 */
	public function testItAnswersTheHiddenNumbers(): void {
		$response = $this->controller()->index('m-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['closedItems' => [['orderNumber' => 7, 'parentItem' => null]]], $response->getData());
	}//end testItAnswersTheHiddenNumbers()

	/**
	 * Without a user it answers 401.
	 *
	 * @return void
	 */
	public function testItRefusesAnonymousCallers(): void {
		$this->assertSame(401, $this->controller(signedIn: false)->index('m-1')->getStatus());
	}//end testItRefusesAnonymousCallers()

	/**
	 * A meeting the caller cannot read answers 404.
	 *
	 * @return void
	 */
	public function testAnUnreadableMeetingAnswers404(): void {
		$response = $this->controller(meeting: false)->index('m-1');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['message' => 'Meeting not found.'], $response->getData());
	}//end testAnUnreadableMeetingAnswers404()

	/**
	 * A failed read answers 500 and is logged, without the error text.
	 *
	 * @return void
	 */
	public function testAFailedReadAnswers500AndIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Decidiq: closed agenda items could not be read');

		$response = $this->controller(failure: new RuntimeException('db down'), logger: $logger)->index('m-1');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['message' => 'The closed agenda items could not be read.'], $response->getData());
	}//end testAFailedReadAnswers500AndIsLogged()
}//end class
