<?php

/**
 * Unit tests for MandateSubstitutionController.
 *
 * The acting user always comes from the session, the request's participant
 * ids and trimmed reason reach the service, and a refusal answers the status
 * the service chose.
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

use OCA\Decidiq\Controller\MandateSubstitutionController;
use OCA\Decidiq\Exception\SubstitutionRefusedException;
use OCA\Decidiq\Kernel\Contracts\MandateSubstitutionServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The substitution endpoints of a meeting.
 *
 * @covers \OCA\Decidiq\Controller\MandateSubstitutionController
 * @uses   \OCA\Decidiq\Exception\SubstitutionRefusedException
 */
class MandateSubstitutionControllerTest extends TestCase {

	/**
	 * The substitution service double.
	 *
	 * @var MandateSubstitutionServiceInterface&MockObject
	 */
	private MandateSubstitutionServiceInterface $substitutions;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->substitutions = $this->createMock(MandateSubstitutionServiceInterface::class);
	}//end setUp()

	/**
	 * The controller with request parameters and, optionally, nobody signed in.
	 *
	 * @param array<string, mixed> $params   The request parameters.
	 * @param bool                 $signedIn Whether a user is signed in.
	 *
	 * @return MandateSubstitutionController
	 */
	private function controller(array $params = [], bool $signedIn = true): MandateSubstitutionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('griffier');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);

		return new MandateSubstitutionController($request, $this->substitutions, $session);
	}//end controller()

	/**
	 * The seats of a meeting are read for the session user.
	 *
	 * @return void
	 */
	public function testSeatsAreReadForTheSessionUser(): void {
		$this->substitutions->expects($this->once())->method('seats')->with('meeting-1', 'griffier')->willReturn(['seats' => [['participant' => 'p-1']]]);

		$response = $this->controller()->seats('meeting-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['seats' => [['participant' => 'p-1']]], $response->getData());
	}//end testSeatsAreReadForTheSessionUser()

	/**
	 * Starting a substitution passes both participants and the trimmed reason, and answers 201.
	 *
	 * @return void
	 */
	public function testSubstituteStartsWithTheRequestAndAnswers201(): void {
		$this->substitutions->expects($this->once())->method('start')
			->with('meeting-1', 'p-out', 'p-in', 'Ziek', 'griffier')
			->willReturn(['id' => 'sub-1']);

		$response = $this->controller(['outgoingParticipantId' => 'p-out', 'incomingParticipantId' => 'p-in', 'reason' => '  Ziek  '])->substitute('meeting-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['id' => 'sub-1'], $response->getData());
	}//end testSubstituteStartsWithTheRequestAndAnswers201()

	/**
	 * Missing participants reach the service as empty strings, and its refusal answers its own status.
	 *
	 * @return void
	 */
	public function testARefusedSubstitutionAnswersTheServiceStatus(): void {
		$this->substitutions->expects($this->once())->method('start')
			->with('meeting-1', '', '', '', 'griffier')
			->willThrowException(new SubstitutionRefusedException('Name the outgoing member.', Http::STATUS_UNPROCESSABLE_ENTITY));

		$response = $this->controller()->substitute('meeting-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['message' => 'Name the outgoing member.'], $response->getData());
	}//end testARefusedSubstitutionAnswersTheServiceStatus()

	/**
	 * Ending a substitution answers the ended record, or the refusal's status.
	 *
	 * @return void
	 */
	public function testEndSubstitution(): void {
		$this->substitutions->method('end')->willReturnCallback(
			static function (string $meetingId, string $substitutionId, string $userId): array {
				if ($substitutionId === 'gone') {
					throw new SubstitutionRefusedException('No such substitution.', Http::STATUS_NOT_FOUND);
				}

				return ['id' => $substitutionId, 'meeting' => $meetingId, 'endedBy' => $userId];
			}
		);
		$controller = $this->controller();

		$ended = $controller->endSubstitution('meeting-1', 'sub-1');
		$refused = $controller->endSubstitution('meeting-1', 'gone');

		$this->assertSame([Http::STATUS_OK, ['id' => 'sub-1', 'meeting' => 'meeting-1', 'endedBy' => 'griffier']], [$ended->getStatus(), $ended->getData()]);
		$this->assertSame([Http::STATUS_NOT_FOUND, ['message' => 'No such substitution.']], [$refused->getStatus(), $refused->getData()]);
	}//end testEndSubstitution()

	/**
	 * Nobody signed in is 401 on every endpoint and the service is not asked.
	 *
	 * @return void
	 */
	public function testNobodySignedInIs401(): void {
		$this->substitutions->expects($this->never())->method($this->anything());
		$controller = $this->controller(signedIn: false);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->seats('meeting-1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->substitute('meeting-1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->endSubstitution('meeting-1', 'sub-1')->getStatus());
	}//end testNobodySignedInIs401()
}//end class
