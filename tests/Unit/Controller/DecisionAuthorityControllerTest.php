<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Controller
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\DecisionAuthorityController;
use OCA\Decidiq\Service\DecisionAuthority;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * GET /api/decisions/{decisionId}/authority.
 *
 * @spec openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-says-whether-the-drafter-may-take-the-decision
 */
class DecisionAuthorityControllerTest extends TestCase {

	/**
	 * @param bool       $signedIn Whether a user is signed in.
	 * @param array|null $answer   What the service answers.
	 *
	 * @return DecisionAuthorityController
	 */
	private function controller(bool $signedIn, ?array $answer): DecisionAuthorityController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);
		$authority = $this->createMock(DecisionAuthority::class);
		$authority->method('forDecision')->willReturn($answer);

		return new DecisionAuthorityController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			authority: $authority
		);
	}//end controller()

	/**
	 * @return void
	 */
	public function testASignedOutCallerIsRefused(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(false, null)->show(decisionId: 'd-1')->getStatus());
	}//end testASignedOutCallerIsRefused()

	/**
	 * A decision the caller may not read is not found.
	 *
	 * @return void
	 */
	public function testAnUnreadableDecisionIsNotFound(): void {
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(true, null)->show(decisionId: 'd-x')->getStatus());
	}//end testAnUnreadableDecisionIsNotFound()

	/**
	 * @return void
	 */
	public function testTheAnswerIsReturned(): void {
		$answer   = ['authorised' => true, 'reason' => 'authorised', 'actor' => 'pieter', 'act' => 'woo-decision', 'subject' => 'Woo decisions', 'restrictions' => null];
		$response = $this->controller(true, $answer)->show(decisionId: 'd-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($answer, $response->getData());
	}//end testTheAnswerIsReturned()
}//end class
