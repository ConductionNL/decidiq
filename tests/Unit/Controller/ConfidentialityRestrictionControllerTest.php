<?php

/**
 * The impose endpoint of a confidentiality restriction.
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

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\ConfidentialityRestrictionController;
use OCA\Decidiq\Exception\ConfidentialityImposeRefusedException;
use OCA\Decidiq\Service\ConfidentialityImposer;
use OCA\Decidiq\Service\ConfidentialityLifter;
use OCA\Decidiq\Service\ConfidentialityRatifier;
use OCA\Decidiq\Service\RestrictedPaperViews;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for ConfidentialityRestrictionController.
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
 */
class ConfidentialityRestrictionControllerTest extends TestCase {

	/**
	 * The request body.
	 *
	 * @var array<string, mixed>
	 */
	private const BODY = [
		'scope' => 'item', 'target' => 'item-1', 'ground' => 'ground-gw', 'imposedBy' => 'body', 'imposedByBody' => 'body-raad',
		'dissolutionDecision' => 'dec-lift', 'liftingConditions' => 'na ondertekening', 'extra' => 'ignored',
		'ratifyingBody' => 'body-ab', 'ratificationDeadline' => '2027-01-31', 'ratificationDecision' => 'dec-ratify',
	];

	/**
	 * The controller with a caller and an imposer.
	 *
	 * @param string|null                $uid     The caller, or null when signed out.
	 * @param ConfidentialityImposer|null $imposer The imposer.
	 * @param ConfidentialityLifter|null  $lifter  The lifter.
	 * @param ConfidentialityRatifier|null $ratifier The ratifier.
	 * @param RestrictedPaperViews|null    $views    The view log.
	 *
	 * @return ConfidentialityRestrictionController
	 */
	private function controller(
		?string $uid,
		?ConfidentialityImposer $imposer=null,
		?ConfidentialityLifter $lifter=null,
		?ConfidentialityRatifier $ratifier=null,
		?RestrictedPaperViews $views=null
	): ConfidentialityRestrictionController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default=null): mixed => (self::BODY[$key] ?? $default));
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new ConfidentialityRestrictionController(
			request: $request,
			userSession: $session,
			imposer: ($imposer ?? $this->createMock(originalClassName: ConfidentialityImposer::class)),
			lifter: ($lifter ?? $this->createMock(originalClassName: ConfidentialityLifter::class)),
			ratifier: ($ratifier ?? $this->createMock(originalClassName: ConfidentialityRatifier::class)),
			views: ($views ?? $this->createMock(originalClassName: RestrictedPaperViews::class))
		);
	}//end controller()

	/**
	 * Without a user: 401, and the imposer is not called.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function testSignedOutIsUnauthorised(): void {
		$imposer = $this->createMock(originalClassName: ConfidentialityImposer::class);
		$imposer->expects($this->never())->method('impose');

		$this->assertSame(401, $this->controller(uid: null, imposer: $imposer)->impose()->getStatus());
	}//end testSignedOutIsUnauthorised()

	/**
	 * The five fields reach the imposer for the caller, and the answer is 201.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function testImposedIsCreated(): void {
		$imposer = $this->createMock(originalClassName: ConfidentialityImposer::class);
		$imposer->expects($this->once())->method('impose')->with(
			'griffier',
			['scope' => 'item', 'target' => 'item-1', 'ground' => 'ground-gw', 'imposedBy' => 'body', 'imposedByBody' => 'body-raad']
		)->willReturn(['restriction' => ['id' => 'r-1', 'lifecycle' => 'imposed'], 'classifier' => 'closed']);

		$ratifier = $this->createMock(originalClassName: ConfidentialityRatifier::class);
		$ratifier->expects($this->once())->method('place')->with(
			['id' => 'r-1', 'lifecycle' => 'imposed'],
			['ratifyingBody' => 'body-ab', 'ratificationDeadline' => '2027-01-31']
		)->willReturn(['status' => 'placed', 'restriction' => ['id' => 'r-1', 'lifecycle' => 'imposed', 'ratificationDeadline' => '2027-01-31']]);

		$response = $this->controller(uid: 'griffier', imposer: $imposer, ratifier: $ratifier)->impose();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame('closed', $response->getData()['classifier']);
		$this->assertSame('placed', $response->getData()['ratification']);
		$this->assertSame('2027-01-31', $response->getData()['restriction']['ratificationDeadline']);
	}//end testImposedIsCreated()

	/**
	 * A refusal answers with its own status and message.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function testARefusalKeepsItsStatus(): void {
		$imposer = $this->createMock(originalClassName: ConfidentialityImposer::class);
		$imposer->method('impose')->willThrowException(new ConfidentialityImposeRefusedException(message: 'Only the chair...', status: 403));

		$response = $this->controller(uid: 'member', imposer: $imposer)->impose();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('Only the chair...', $response->getData()['message']);
	}//end testARefusalKeepsItsStatus()

	/**
	 * Chairs and secretaries are not administrators: the endpoint is NoAdminRequired.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function testTheEndpointIsOpenToNonAdministrators(): void {
		foreach (['impose', 'ratify', 'lift', 'views'] as $name) {
			$method = new ReflectionMethod(ConfidentialityRestrictionController::class, $name);
			$this->assertCount(1, $method->getAttributes(NoAdminRequired::class));
		}
	}//end testTheEndpointIsOpenToNonAdministrators()

	/**
	 * Lifting: 401 signed out; the decision and conditions reach the lifter; 200; a refusal keeps its status.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-004-opheffing-workflow-routing-into-the-normal-publication-machinery
	 */
	public function testLift(): void {
		$never = $this->createMock(originalClassName: ConfidentialityLifter::class);
		$never->expects($this->never())->method('lift');
		$this->assertSame(401, $this->controller(uid: null, lifter: $never)->lift(id: 'r-1')->getStatus());

		$lifter = $this->createMock(originalClassName: ConfidentialityLifter::class);
		$lifter->expects($this->once())->method('lift')->with(
			'griffier',
			'r-1',
			['dissolutionDecision' => 'dec-lift', 'liftingConditions' => 'na ondertekening']
		)->willReturn(['restriction' => ['id' => 'r-1', 'lifecycle' => 'dissolved'], 'classifier' => 'reopened']);
		$response = $this->controller(uid: 'griffier', lifter: $lifter)->lift(id: 'r-1');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('reopened', $response->getData()['classifier']);

		$refusing = $this->createMock(originalClassName: ConfidentialityLifter::class);
		$refusing->method('lift')->willThrowException(new ConfidentialityImposeRefusedException(message: 'Needs ratification', status: 409));
		$this->assertSame(409, $this->controller(uid: 'griffier', lifter: $refusing)->lift(id: 'r-1')->getStatus());
	}//end testLift()

	/**
	 * Ratifying: 401 signed out; the decision reaches the ratifier; 200; a refusal keeps its status.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-003-bekrachtiging-workflow-with-fail-visible-overdue-flag
	 */
	public function testRatify(): void {
		$never = $this->createMock(originalClassName: ConfidentialityRatifier::class);
		$never->expects($this->never())->method('ratify');
		$this->assertSame(401, $this->controller(uid: null, ratifier: $never)->ratify(id: 'r-1')->getStatus());

		$ratifier = $this->createMock(originalClassName: ConfidentialityRatifier::class);
		$ratifier->expects($this->once())->method('ratify')->with('griffier', 'r-1', ['ratificationDecision' => 'dec-ratify'])
			->willReturn(['id' => 'r-1', 'lifecycle' => 'ratified']);
		$response = $this->controller(uid: 'griffier', ratifier: $ratifier)->ratify(id: 'r-1');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('ratified', $response->getData()['restriction']['lifecycle']);

		$refusing = $this->createMock(originalClassName: ConfidentialityRatifier::class);
		$refusing->method('ratify')->willThrowException(new ConfidentialityImposeRefusedException(message: 'No ratification needed', status: 409));
		$this->assertSame(409, $this->controller(uid: 'griffier', ratifier: $refusing)->ratify(id: 'r-1')->getStatus());
	}//end testRatify()

	/**
	 * The views list: 401 signed out; 200 with the views; a refusal keeps its status.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-007-view-audit-trail-for-stukken-under-geheimhouding
	 */
	public function testViews(): void {
		$never = $this->createMock(originalClassName: RestrictedPaperViews::class);
		$never->expects($this->never())->method('viewsOf');
		$this->assertSame(401, $this->controller(uid: null, views: $never)->views(id: 'r-1')->getStatus());

		$views = $this->createMock(originalClassName: RestrictedPaperViews::class);
		$views->expects($this->once())->method('viewsOf')->with('griffier', 'r-1')
			->willReturn([['actor' => 'j.jansen', 'at' => '2026-11-21T10:00:00+01:00', 'document' => 'doc-1', 'agendaItem' => '', 'fileId' => 42]]);
		$response = $this->controller(uid: 'griffier', views: $views)->views(id: 'r-1');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('j.jansen', $response->getData()['views'][0]['actor']);

		$refusing = $this->createMock(originalClassName: RestrictedPaperViews::class);
		$refusing->method('viewsOf')->willThrowException(new ConfidentialityImposeRefusedException(message: 'Only staff', status: 403));
		$this->assertSame(403, $this->controller(uid: 'member', views: $refusing)->views(id: 'r-1')->getStatus());
	}//end testViews()
}//end class
