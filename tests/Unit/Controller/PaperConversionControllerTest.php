<?php

/**
 * Unit tests for PaperConversionController: the clerk's Try again on a paper
 * whose conversion to PDF failed.
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
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-002-a-failed-or-impossible-conversion-is-visible-and-the-original-stays
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\BackgroundJob\ConvertPaperToPdfJob;
use OCA\Decidiq\Controller\PaperConversionController;
use OCA\Decidiq\Service\AgendaAuthorizationGuard;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Only the chair, the secretary or an administrator may ask again, and only
 * for a paper the page itself records.
 */
class PaperConversionControllerTest extends TestCase {

	private const ITEM = '11111111-2222-4333-8444-555555555555';

	private const MEETING = '99999999-8888-4777-8666-555555555555';

	/**
	 * Build the controller over an agenda item that records paper 42.
	 *
	 * @param IJobList          $jobList The job list.
	 * @param JSONResponse|null $denied  What the chair check answers.
	 *
	 * @return PaperConversionController
	 */
	private function controller(IJobList $jobList, ?JSONResponse $denied=null): PaperConversionController {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn([
			'meeting'         => self::MEETING,
			'paperRenditions' => [['sourceFileId' => 42, 'sourceName' => 'Begroting.docx', 'failedAt' => '2026-09-30T08:00:00+00:00']],
		]);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn($entity);

		$guard = $this->createMock(AgendaAuthorizationGuard::class);
		$guard->method('requireUser')->willReturn(null);
		$guard->method('requireChairOrAdmin')->with(self::MEETING)->willReturn($denied);

		return new PaperConversionController($this->createMock(IRequest::class), $objects, $guard, $jobList);
	}//end controller()

	/**
	 * The chair asks again: one conversion is queued for that paper.
	 *
	 * @return void
	 */
	public function testTheChairQueuesTheConversionAgain(): void {
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('add')
			->with(ConvertPaperToPdfJob::class, ['fileId' => 42, 'objectId' => self::ITEM, 'schema' => 'agenda-item']);

		$response = $this->controller(jobList: $jobs)->convert(schema: 'agenda-item', objectId: self::ITEM, fileId: 42);

		self::assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
	}//end testTheChairQueuesTheConversionAgain()

	/**
	 * A member who is not chair or secretary is refused and nothing is queued.
	 *
	 * @return void
	 */
	public function testAMemberIsRefused(): void {
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->never())->method('add');

		$refused  = new JSONResponse(['message' => 'Chair or secretary role required for this meeting'], Http::STATUS_FORBIDDEN);
		$response = $this->controller(jobList: $jobs, denied: $refused)->convert(schema: 'agenda-item', objectId: self::ITEM, fileId: 42);

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAMemberIsRefused()

	/**
	 * A file the page does not record cannot be converted through it.
	 *
	 * @return void
	 */
	public function testAFileThePageDoesNotRecordIsNotConverted(): void {
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->never())->method('add');

		$response = $this->controller(jobList: $jobs)->convert(schema: 'agenda-item', objectId: self::ITEM, fileId: 7);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAFileThePageDoesNotRecordIsNotConverted()

	/**
	 * Only meetings and agenda items carry papers.
	 *
	 * @return void
	 */
	public function testOtherRecordTypesAreRefused(): void {
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->never())->method('add');

		$response = $this->controller(jobList: $jobs)->convert(schema: 'motion', objectId: self::ITEM, fileId: 42);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testOtherRecordTypesAreRefused()

	/**
	 * The Try again button reaches the controller through a route.
	 *
	 * @return void
	 */
	public function testTheRouteIsRegistered(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$found  = array_filter(
			$routes['routes'],
			static fn (array $r): bool => $r['name'] === 'paperConversion#convert'
				&& $r['url'] === '/api/papers/{schema}/{objectId}/{fileId}/convert'
				&& $r['verb'] === 'POST'
		);

		self::assertCount(1, $found);
	}//end testTheRouteIsRegistered()
}//end class
