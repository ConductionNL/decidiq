<?php

/**
 * Decidiq CaseSystemControllerTest
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\CaseSystemController;
use OCA\Decidiq\Service\CaseDocumentService;
use OCA\Decidiq\Service\CaseExchangeRecords;
use OCA\Decidiq\Service\CaseSystemClient;
use OCA\Decidiq\Service\CaseSystemExchangeService;
use OCA\Decidiq\Service\SigningAnswer;
use OCA\Decidiq\Service\TranscriptionStaffGuard;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Without a linked source the case actions answer 409; the chair or
 * secretary guard runs before anything else.
 *
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
 *
 * @uses \OCA\Decidiq\Service\CaseSystemClient
 * @uses \OCA\Decidiq\Service\SigningAnswer
 * @uses \OCA\Decidiq\Exception\CaseSystemException
 */
class CaseSystemControllerTest extends TestCase {

	/**
	 * The controller over an instance where integriq has no source linked to decidiq's case-system connection.
	 *
	 * @param JSONResponse|null         $denied   What the staff guard answers.
	 * @param CaseSystemExchangeService $exchange The exchange.
	 *
	 * @return CaseSystemController
	 */
	private function controller(?JSONResponse $denied, CaseSystemExchangeService $exchange): CaseSystemController {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturn([]);
		$item = $this->createMock(ObjectEntity::class);
		$item->method('getObject')->willReturn(['title' => 'Vaststelling omgevingsvisie', 'meeting' => 'meeting-12-maart']);
		$objects->method('find')->willReturn($item);

		$guard = $this->createMock(TranscriptionStaffGuard::class);
		$guard->method('forMeeting')->willReturn($denied);
		$guard->method('currentUserId')->willReturn('griffier');

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default=null): mixed => ['reference' => 'Z-2026-00412', 'urls' => ['https://documenten.example.org/1']][$key] ?? $default);

		return new CaseSystemController(
			request: $request,
			client: new CaseSystemClient(objectService: $objects, container: $this->createMock(ContainerInterface::class), answers: new SigningAnswer()),
			documents: $this->createMock(CaseDocumentService::class),
			exchange: $exchange,
			records: $this->createMock(CaseExchangeRecords::class),
			guard: $guard,
			objectService: $objects
		);
	}//end controller()

	/**
	 * No case system linked: every agenda item case action answers 409.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
	 *
	 * @return void
	 */
	public function testNoCaseSystemLinkedAnswers409(): void {
		$controller = $this->controller(denied: null, exchange: $this->createMock(CaseSystemExchangeService::class));

		foreach ([$controller->linkCase(id: 'item-1'), $controller->listDocuments(id: 'item-1'), $controller->fetchDocuments(id: 'item-1')] as $response) {
			$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
			$this->assertSame(['message' => 'No case system is connected'], $response->getData());
		}

		$this->assertSame(['connected' => false], $controller->status()->getData());
	}//end testNoCaseSystemLinkedAnswers409()

	/**
	 * A caller who is not the chair or secretary is refused before the case system is asked anything.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @return void
	 */
	public function testSomeoneElseIsRefusedBeforeTheSend(): void {
		$exchange = $this->createMock(CaseSystemExchangeService::class);
		$exchange->expects($this->never())->method('requestSend');
		$controller = $this->controller(denied: new JSONResponse(['message' => 'Forbidden: chair or secretary role required.'], Http::STATUS_FORBIDDEN), exchange: $exchange);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->send(id: 'meeting-12-maart')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->linkCase(id: 'item-1')->getStatus());
	}//end testSomeoneElseIsRefusedBeforeTheSend()
}//end class
