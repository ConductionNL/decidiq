<?php

/**
 * Unit tests for MemberCompetenceController: what each endpoint answers.
 *
 * Each endpoint hands the request body (without routing keys) to the competence
 * service, answers 201 for a create and 200 otherwise, and maps the service's
 * refusals to 403, 404, 422 and a generic 500.
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

use OCA\Decidiq\Controller\MemberCompetenceController;
use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\CompetenceRefusedException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Kernel\Contracts\MemberCompetenceServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The competence endpoints.
 *
 * @covers \OCA\Decidiq\Controller\MemberCompetenceController
 * @uses   \OCA\Decidiq\Exception\AccessDeniedException
 * @uses   \OCA\Decidiq\Exception\MissingObjectException
 * @uses   \OCA\Decidiq\Exception\CompetenceRefusedException
 */
class MemberCompetenceControllerResponsesTest extends TestCase {

	/**
	 * The competence service double.
	 *
	 * @var MemberCompetenceServiceInterface&MockObject
	 */
	private MemberCompetenceServiceInterface $competences;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->competences = $this->createMock(MemberCompetenceServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The controller with a request body and, optionally, nobody signed in.
	 *
	 * @param array<string, mixed> $params   The request parameters.
	 * @param bool                 $signedIn Whether a user is signed in.
	 *
	 * @return MemberCompetenceController
	 */
	private function controller(array $params = [], bool $signedIn = true): MemberCompetenceController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		return new MemberCompetenceController($request, $this->competences, $session, $this->logger);
	}//end controller()

	/**
	 * Creating a competence passes the body without routing keys and answers 201.
	 *
	 * @return void
	 */
	public function testCreateCompetencePassesTheBodyAndAnswers201(): void {
		$this->competences->expects($this->once())->method('saveCompetence')
			->with(['name' => 'Budgetrecht'], null)
			->willReturn(['id' => 'comp-1', 'name' => 'Budgetrecht']);

		$response = $this->controller(['name' => 'Budgetrecht', 'id' => 'ignored', '_route' => 'x'])->createCompetence();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['competence' => ['id' => 'comp-1', 'name' => 'Budgetrecht']], $response->getData());
	}//end testCreateCompetencePassesTheBodyAndAnswers201()

	/**
	 * Updating a competence passes the route id and answers 200.
	 *
	 * @return void
	 */
	public function testUpdateCompetencePassesTheIdAndAnswers200(): void {
		$this->competences->expects($this->once())->method('saveCompetence')
			->with(['name' => 'Toezicht'], 'comp-1')
			->willReturn(['id' => 'comp-1']);

		$response = $this->controller(['name' => 'Toezicht', 'id' => 'comp-1'])->updateCompetence('comp-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['competence' => ['id' => 'comp-1']], $response->getData());
	}//end testUpdateCompetencePassesTheIdAndAnswers200()

	/**
	 * Recording, updating and confirming a member competence use the memberCompetence key.
	 *
	 * @return void
	 */
	public function testMemberCompetenceRecordUpdateAndConfirm(): void {
		$this->competences->expects($this->exactly(2))->method('record')
			->willReturnCallback(static fn (array $data, ?string $id): array => ['data' => $data, 'id' => $id]);
		$this->competences->expects($this->once())->method('confirm')->with('mc-7')->willReturn(['id' => 'mc-7', 'confirmed' => true]);
		$controller = $this->controller(['member' => 'p-1']);

		$recorded = $controller->record();
		$updated = $controller->update('mc-7');
		$confirmed = $controller->confirm('mc-7');

		$this->assertSame([Http::STATUS_CREATED, ['memberCompetence' => ['data' => ['member' => 'p-1'], 'id' => null]]], [$recorded->getStatus(), $recorded->getData()]);
		$this->assertSame([Http::STATUS_OK, ['memberCompetence' => ['data' => ['member' => 'p-1'], 'id' => 'mc-7']]], [$updated->getStatus(), $updated->getData()]);
		$this->assertSame([Http::STATUS_OK, ['memberCompetence' => ['id' => 'mc-7', 'confirmed' => true]]], [$confirmed->getStatus(), $confirmed->getData()]);
	}//end testMemberCompetenceRecordUpdateAndConfirm()

	/**
	 * Nobody signed in is 401 and the service is not asked.
	 *
	 * @return void
	 */
	public function testNobodySignedInIs401(): void {
		$this->competences->expects($this->never())->method('saveCompetence');
		$this->competences->expects($this->never())->method('confirm');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(signedIn: false)->createCompetence()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(signedIn: false)->confirm('mc-1')->getStatus());
	}//end testNobodySignedInIs401()

	/**
	 * Each refusal answers its status with the service's message.
	 *
	 * @return void
	 */
	public function testRefusalsMapToTheirStatus(): void {
		$this->competences->method('confirm')->willReturnCallback(
			static function (string $id): array {
				throw match ($id) {
					'denied' => new AccessDeniedException('Only the griffie may confirm.'),
					'missing' => new MissingObjectException('No such member competence.'),
					default => new CompetenceRefusedException('The competence has expired.'),
				};
			}
		);
		$controller = $this->controller();

		$denied = $controller->confirm('denied');
		$missing = $controller->confirm('missing');
		$refused = $controller->confirm('refused');

		$this->assertSame([Http::STATUS_FORBIDDEN, ['message' => 'Only the griffie may confirm.']], [$denied->getStatus(), $denied->getData()]);
		$this->assertSame([Http::STATUS_NOT_FOUND, ['message' => 'No such member competence.']], [$missing->getStatus(), $missing->getData()]);
		$this->assertSame([Http::STATUS_UNPROCESSABLE_ENTITY, ['message' => 'The competence has expired.']], [$refused->getStatus(), $refused->getData()]);
	}//end testRefusalsMapToTheirStatus()

	/**
	 * Any other failure is logged and answered 500 without its internals.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureIs500AndLogged(): void {
		$failure = new RuntimeException('SQLSTATE[HY000] secret');
		$this->competences->method('record')->willThrowException($failure);
		$this->logger->expects($this->once())->method('error')->with('Decidiq: competence write failed', ['exception' => $failure]);

		$response = $this->controller()->record();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'The competence could not be saved.'], $response->getData());
	}//end testAnUnexpectedFailureIs500AndLogged()
}//end class
