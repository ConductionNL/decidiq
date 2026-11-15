<?php

/**
 * Unit tests for MinutesController actions over its service contracts.
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

use OCA\Decidiq\Controller\MinutesController;
use OCA\Decidiq\Controller\MinutesResponder;
use OCA\Decidiq\Kernel\Contracts\ALVMinutesServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MinutesAccessGuardInterface;
use OCA\Decidiq\Kernel\Contracts\MinutesDocumentServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MinutesGenerationServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MinutesWorkflowServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MinutesController actions.
 *
 * @covers \OCA\Decidiq\Controller\MinutesController
 * @uses \OCA\Decidiq\Controller\MinutesResponder
 */
class MinutesControllerActionsTest extends TestCase {

	/**
	 * The generation service double.
	 *
	 * @var MinutesGenerationServiceInterface&MockObject
	 */
	private MinutesGenerationServiceInterface&MockObject $generation;

	/**
	 * The ALV minutes service double.
	 *
	 * @var ALVMinutesServiceInterface&MockObject
	 */
	private ALVMinutesServiceInterface&MockObject $alv;

	/**
	 * The workflow service double.
	 *
	 * @var MinutesWorkflowServiceInterface&MockObject
	 */
	private MinutesWorkflowServiceInterface&MockObject $workflow;

	/**
	 * The document service double.
	 *
	 * @var MinutesDocumentServiceInterface&MockObject
	 */
	private MinutesDocumentServiceInterface&MockObject $documents;

	/**
	 * A controller over these params, for a signed-in user (or nobody), allowed or denied.
	 *
	 * @param array<string, mixed> $params   The request parameters.
	 * @param bool                 $signedIn Whether a user is signed in.
	 * @param JSONResponse|null    $denial   The guard's denial, or null to allow.
	 *
	 * @return MinutesController The controller under test.
	 */
	private function controller(array $params = [], bool $signedIn = true, ?JSONResponse $denial = null): MinutesController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('griffier');
			$user->method('getDisplayName')->willReturn('De Griffier');
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$guard = $this->createMock(MinutesAccessGuardInterface::class);
		$guard->method('requireChairOrAdmin')->willReturn($denial);
		$this->generation = $this->createMock(MinutesGenerationServiceInterface::class);
		$this->alv = $this->createMock(ALVMinutesServiceInterface::class);
		$this->workflow = $this->createMock(MinutesWorkflowServiceInterface::class);
		$this->documents = $this->createMock(MinutesDocumentServiceInterface::class);

		return new MinutesController(
			request: $request,
			generationService: $this->generation,
			alvMinutesService: $this->alv,
			workflowService: $this->workflow,
			userSession: $session,
			accessGuard: $guard,
			documentService: $this->documents,
			responder: new MinutesResponder(),
		);
	}//end controller()

	/**
	 * A missing or non-string lifecycle is a bad request, and nothing transitions.
	 *
	 * @return void
	 */
	public function testTransitionWithoutALifecycleIsABadRequest(): void {
		foreach ([[], ['lifecycle' => ''], ['lifecycle' => ['approved']]] as $params) {
			$controller = $this->controller(params: $params);
			$this->generation->expects(self::never())->method('transition');

			$response = $controller->transition(minutesId: 'm-1');
			self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
			self::assertSame(['message' => 'Missing or invalid lifecycle parameter.'], $response->getData());
		}
	}//end testTransitionWithoutALifecycleIsABadRequest()

	/**
	 * Without a signed-in user the transition is attributed to an empty display name.
	 *
	 * @return void
	 */
	public function testTransitionWithoutAUserUsesAnEmptyName(): void {
		$controller = $this->controller(params: ['lifecycle' => 'review'], signedIn: false);
		$this->generation->expects(self::once())->method('transition')->with('m-1', 'review', '')->willReturn(['lifecycle' => 'review']);

		self::assertSame(['lifecycle' => 'review'], $controller->transition(minutesId: 'm-1')->getData());
	}//end testTransitionWithoutAUserUsesAnEmptyName()

	/**
	 * Distributing ALV minutes answers how many members were notified.
	 *
	 * @return void
	 */
	public function testDistributeALVMinutesReportsTheNotifiedCount(): void {
		$controller = $this->controller();
		$this->alv->expects(self::once())->method('distribute')->with('m-1')->willReturn(42);

		$response = $controller->distributeALVMinutes(minutesId: 'm-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['notified' => 42], $response->getData());
	}//end testDistributeALVMinutesReportsTheNotifiedCount()

	/**
	 * The confirmed action items are saved and counted; none confirmed saves an empty list.
	 *
	 * @return void
	 */
	public function testSaveExtractedActionItemsSavesTheConfirmedOnes(): void {
		$confirmed = [['title' => 'Bel de wethouder']];
		$controller = $this->controller(params: ['confirmed' => $confirmed]);
		$this->workflow->expects(self::once())->method('saveExtractedActionItems')->with('m-1', $confirmed)->willReturn(1);
		self::assertSame(['saved' => 1], $controller->saveExtractedActionItems(minutesId: 'm-1')->getData());

		$none = $this->controller();
		$this->workflow->expects(self::once())->method('saveExtractedActionItems')->with('m-1', [])->willReturn(0);
		self::assertSame(['saved' => 0], $none->saveExtractedActionItems(minutesId: 'm-1')->getData());
	}//end testSaveExtractedActionItemsSavesTheConfirmedOnes()

	/**
	 * A non-string rejection comment is passed on as empty, attributed to the caller.
	 *
	 * @return void
	 */
	public function testRejectWithANonStringCommentRejectsWithoutOne(): void {
		$controller = $this->controller(params: ['comment' => ['not', 'text']]);
		$this->generation->expects(self::once())->method('reject')->with('m-1', '', 'griffier')->willReturn(['lifecycle' => 'draft']);

		self::assertSame(['lifecycle' => 'draft'], $controller->reject(minutesId: 'm-1')->getData());
	}//end testRejectWithANonStringCommentRejectsWithoutOne()

	/**
	 * A missing or non-string format generates markdown, under the caller's display name.
	 *
	 * @return void
	 */
	public function testGenerateDocumentDefaultsToMarkdown(): void {
		foreach ([[], ['format' => ''], ['format' => 7]] as $params) {
			$controller = $this->controller(params: $params);
			$this->documents->expects(self::once())->method('generate')->with('m-1', 'markdown', 'De Griffier')->willReturn(['path' => '/m-1.md']);

			self::assertSame(['path' => '/m-1.md'], $controller->generateDocument(minutesId: 'm-1')->getData());
		}

		$pdf = $this->controller(params: ['format' => 'pdf']);
		$this->documents->expects(self::once())->method('generate')->with('m-1', 'pdf', 'De Griffier')->willReturn(['path' => '/m-1.pdf']);
		self::assertSame(['path' => '/m-1.pdf'], $pdf->generateDocument(minutesId: 'm-1')->getData());
	}//end testGenerateDocumentDefaultsToMarkdown()

	/**
	 * The guard's denial is returned as is and no service is asked.
	 *
	 * @return void
	 */
	public function testTheGuardsDenialIsReturned(): void {
		$denial = new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		$controller = $this->controller(params: ['lifecycle' => 'approved', 'format' => 'pdf'], denial: $denial);
		$this->generation->expects(self::never())->method(self::anything());
		$this->documents->expects(self::never())->method('generate');
		$this->workflow->expects(self::never())->method('saveExtractedActionItems');

		self::assertSame($denial, $controller->transition(minutesId: 'm-1'));
		self::assertSame($denial, $controller->reject(minutesId: 'm-1'));
		self::assertSame($denial, $controller->generateDocument(minutesId: 'm-1'));
		self::assertSame($denial, $controller->saveExtractedActionItems(minutesId: 'm-1'));
	}//end testTheGuardsDenialIsReturned()
}//end class
