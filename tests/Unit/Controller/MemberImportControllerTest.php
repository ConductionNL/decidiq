<?php

/**
 * Unit tests for MemberImportController.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @spec openspec/specs/admin-settings/spec.md
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Decidiq\Controller\MemberImportController;
use OCA\Decidiq\Service\MemberImportService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the member import admin endpoints.
 *
 * @covers \OCA\Decidiq\Controller\MemberImportController
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class MemberImportControllerTest extends TestCase {
	private IRequest&MockObject $request;

	private MemberImportService&MockObject $service;

	private MemberImportController $controller;

	protected function setUp(): void {
		$this->request    = $this->createMock(IRequest::class);
		$this->service    = $this->createMock(MemberImportService::class);
		$this->controller = new MemberImportController($this->request, $this->service);
	}

	public function testGroupsListsTheGroups(): void {
		$this->service->method('listGroups')->willReturn([['id' => 'raad']]);

		$this->assertSame(['groups' => [['id' => 'raad']]], $this->controller->groups()->getData());
	}

	public function testGroupMembersListsTheMembers(): void {
		$this->service->expects($this->once())->method('getGroupMembers')->with('raad')->willReturn([['uid' => 'a']]);

		$response = $this->controller->groupMembers('raad');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['members' => [['uid' => 'a']]], $response->getData());
	}

	public function testGroupMembersOfAnEmptyGroupIsAnEmptyList(): void {
		$this->service->method('getGroupMembers')->willReturn([]);

		$this->assertSame(['members' => []], $this->controller->groupMembers('raad')->getData());
	}

	public function testGroupMembersAnswersNotFound(): void {
		$this->service->method('getGroupMembers')->willReturn(null);

		$response = $this->controller->groupMembers('x');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['message' => 'Group not found'], $response->getData());
	}

	public function testMatchRequiresAnArray(): void {
		$this->request->method('getParam')->with('emails')->willReturn('a@example.org');
		$this->service->expects($this->never())->method('matchEmails');

		$response = $this->controller->match();

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['message' => 'emails must be an array'], $response->getData());
	}

	public function testMatchReturnsTheMatches(): void {
		$this->request->method('getParam')->with('emails')->willReturn(['a@example.org']);
		$this->service->expects($this->once())->method('matchEmails')->with(['a@example.org'])
			->willReturn([['email' => 'a@example.org', 'uid' => 'a']]);

		$this->assertSame(['matches' => [['email' => 'a@example.org', 'uid' => 'a']]], $this->controller->match()->getData());
	}

	public function testMatchMapsATooLargeBatch(): void {
		$this->request->method('getParam')->willReturn([]);
		$this->service->method('matchEmails')->willThrowException(new InvalidArgumentException('At most 500 emails'));

		$response = $this->controller->match();

		$this->assertSame(413, $response->getStatus());
		$this->assertSame(['message' => 'At most 500 emails'], $response->getData());
	}
}
