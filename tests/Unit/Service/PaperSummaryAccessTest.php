<?php

/**
 * Only the secretariat asks for a paper summary, and a paper under an active
 * confidentiality restriction is not summarised for a clerk outside the
 * restriction's circle (agenda-ai-paper-summaries tasks 2 and 3).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-003-a-confidential-paper-is-not-summarised-outside-its-circle
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\PaperSummaryRefusedException;
use OCA\Decidiq\Service\PaperSummaryAccess;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Service\PaperSummaryAccess
 * @covers \OCA\Decidiq\Exception\PaperSummaryRefusedException
 */
class PaperSummaryAccessTest extends TestCase {

	private const ITEM = 'grondtransactie-noord';

	/**
	 * The access check for a signed-in user over a fixed set of register rows.
	 *
	 * @param string $uid The signed-in user
	 * @param array<int, string> $groups The user's groups
	 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema slug
	 *
	 * @return PaperSummaryAccess The check.
	 */
	private function access(string $uid, array $groups, array $rows): PaperSummaryAccess {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $u, string $group): bool => in_array($group, $groups, true));
		$objects = $this->getMockBuilder(ObjectServiceInterface::class)->getMock();
		$objects->method('findAll')->willReturnCallback(
			static fn (array $config=[]): array => ($rows[$config['filters']['schema'] ?? ''] ?? [])
		);

		return new PaperSummaryAccess(userSession: $session, groupManager: $groupManager, objectService: $objects);
	}//end access()

	/**
	 * Register rows: a restriction on the item imposed by the college, and the
	 * griffier who is a member of the council only.
	 *
	 * @param string $lifecycle The restriction's state
	 *
	 * @return array<string, array<int, array<string, mixed>>> Rows per schema.
	 */
	private function rows(string $lifecycle='imposed'): array {
		return [
			'confidentiality-restriction' => [
				['id' => 'r1', 'scope' => 'item', 'targetAgendaItem' => self::ITEM, 'ground' => 'g1', 'imposedByBody' => 'college', 'lifecycle' => $lifecycle],
				['id' => 'r2', 'scope' => 'item', 'targetAgendaItem' => 'another-item', 'ground' => 'g1', 'imposedByBody' => 'college', 'lifecycle' => 'imposed'],
			],
			'confidentiality-ground' => [['id' => 'g1', 'name' => 'Economische of financiële belangen van de gemeente']],
			'governance-body' => [['id' => 'college', 'name' => 'College van B en W']],
			'person' => [['id' => 'p-griffier', 'nextcloudUserId' => 'griffier'], ['id' => 'p-wethouder', 'nextcloudUserId' => 'wethouder']],
			'membership' => [['id' => 'm1', 'person' => 'p-griffier', 'governanceBody' => 'raad'], ['id' => 'm2', 'person' => 'p-wethouder', 'governanceBody' => 'college']],
			'digital-document' => [],
		];
	}//end rows()

	/**
	 * A member without the secretariat group is refused with 403.
	 *
	 * @return void
	 */
	public function testAMemberIsRefused(): void {
		try {
			$this->access('pieter', ['decidesk-members'], $this->rows())->assertMayRequest(agendaItemId: 'another-free-item', fileId: 900412);
			self::fail('a member may not ask for a summary');
		} catch (PaperSummaryRefusedException $e) {
			self::assertSame(403, $e->getStatus());
		}
	}//end testAMemberIsRefused()

	/**
	 * A clerk outside the restriction's circle is refused, naming the restriction.
	 *
	 * @return void
	 */
	public function testAClerkOutsideTheCircleIsRefused(): void {
		try {
			$this->access('griffier', ['decidiq-secretariat'], $this->rows())->assertMayRequest(agendaItemId: self::ITEM, fileId: 900412);
			self::fail('the restriction keeps the griffier out');
		} catch (PaperSummaryRefusedException $e) {
			self::assertSame(403, $e->getStatus());
			self::assertStringContainsString('Economische of financiële belangen van de gemeente', $e->getMessage());
			self::assertStringContainsString('College van B en W', $e->getMessage());
		}
	}//end testAClerkOutsideTheCircleIsRefused()

	/**
	 * Inside the circle, or once the restriction is lifted, the request may go ahead.
	 *
	 * @return void
	 */
	public function testInsideTheCircleOrAfterTheLiftingTheRequestGoesAhead(): void {
		self::assertSame('wethouder', $this->access('wethouder', ['decidiq-secretariat'], $this->rows())->assertMayRequest(agendaItemId: self::ITEM, fileId: 900412));
		self::assertSame('griffier', $this->access('griffier', ['decidiq-secretariat'], $this->rows(lifecycle: 'dissolved'))->assertMayRequest(agendaItemId: self::ITEM, fileId: 900412));
	}//end testInsideTheCircleOrAfterTheLiftingTheRequestGoesAhead()

	/**
	 * A restriction on the paper itself counts as well as one on the item.
	 *
	 * @return void
	 */
	public function testARestrictionOnThePaperCounts(): void {
		$rows = $this->rows(lifecycle: 'dissolved');
		$rows['digital-document'] = [['id' => 'd1', 'fileId' => 900412, 'agendaItem' => self::ITEM]];
		$rows['confidentiality-restriction'][] = ['id' => 'r3', 'scope' => 'document', 'targetDocument' => 'd1', 'ground' => 'g1', 'imposedByBody' => 'college', 'lifecycle' => 'ratified'];
		$this->expectException(PaperSummaryRefusedException::class);
		$this->access('griffier', ['decidiq-secretariat'], $rows)->assertMayRequest(agendaItemId: self::ITEM, fileId: 900412);
	}//end testARestrictionOnThePaperCounts()
}//end class
