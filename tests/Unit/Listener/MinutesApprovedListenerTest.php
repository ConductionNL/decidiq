<?php

/**
 * Decidiq MinutesApprovedListenerTest
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
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

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\AppInfo\Registrar\ObjectListenerRegistrar;
use OCA\Decidiq\Listener\MinutesApprovedListener;
use OCA\Decidiq\Service\CaseSystemExchangeService;
use OCA\Decidiq\Service\DecisionListService;
use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Minutes reaching approved or signed render the decision list; approved
 * sends the meeting file when an administrator turned that on.
 *
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-004-approving-the-minutes-produces-a-decision-list-document
 *
 * @uses \OCA\Decidiq\Service\ListenerSchemaResolver
 */
class MinutesApprovedListenerTest extends TestCase {

	private DecisionListService&MockObject $decisionList;

	private CaseSystemExchangeService&MockObject $exchange;

	/**
	 * The listener with the send-on-approval switch set.
	 *
	 * @param string $switch The switch value.
	 *
	 * @return MinutesApprovedListener
	 */
	private function listener(string $switch): MinutesApprovedListener {
		$this->decisionList = $this->createMock(DecisionListService::class);
		$this->exchange     = $this->createMock(CaseSystemExchangeService::class);
		$mapper             = new class {
			/**
			 * Resolve a schema by id.
			 *
			 * @param string|int $id Schema id.
			 *
			 * @return Schema
			 */
			public function find(string|int $id): Schema {
				$schema = new Schema();
				$schema->setSlug(['12' => 'minutes', '41' => 'agenda-item'][(string)$id]);
				return $schema;
			}//end find()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($switch);

		return new MinutesApprovedListener(
			decisionList: $this->decisionList,
			exchange: $this->exchange,
			schemaResolver: new ListenerSchemaResolver(container: $container, logger: new NullLogger()),
			appConfig: $config,
			logger: new NullLogger()
		);
	}//end listener()

	/**
	 * Minutes of the council meeting of 12 March in one lifecycle state.
	 *
	 * @param string $lifecycle The state.
	 * @param string $schemaId  The schema id.
	 *
	 * @return ObjectEntity
	 */
	private function minutes(string $lifecycle, string $schemaId='12'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('minutes-12-maart');
		$entity->setRegister('7');
		$entity->setSchema($schemaId);
		$entity->setObject(['title' => 'Notulen 12 maart', 'lifecycle' => $lifecycle, 'meeting' => 'meeting-12-maart', 'signedBy' => ['Voorzitter Jansen']]);
		return $entity;
	}//end minutes()

	/**
	 * Approval renders the decision list and, with the switch on, queues the send.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @return void
	 */
	public function testApprovalRendersTheListAndSendsWhenSwitchedOn(): void {
		$listener = $this->listener(switch: 'true');
		$this->decisionList->expects($this->once())->method('render')->with('meeting-12-maart', $this->callback(static fn (array $minutes): bool => $minutes['lifecycle'] === 'approved'));
		$this->exchange->expects($this->once())->method('requestSend')->with('meeting-12-maart', 'system');

		$listener->handle(new ObjectUpdatedEvent($this->minutes(lifecycle: 'approved'), $this->minutes(lifecycle: 'review')));
	}//end testApprovalRendersTheListAndSendsWhenSwitchedOn()

	/**
	 * Signing renders the list again with the signers, and sends nothing.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-004-approving-the-minutes-produces-a-decision-list-document
	 *
	 * @return void
	 */
	public function testSigningRendersTheListAgainAndSendsNothing(): void {
		$listener = $this->listener(switch: 'true');
		$this->decisionList->expects($this->once())->method('render');
		$this->exchange->expects($this->never())->method('requestSend');

		$listener->handle(new ObjectUpdatedEvent($this->minutes(lifecycle: 'signed'), $this->minutes(lifecycle: 'approved')));
	}//end testSigningRendersTheListAgainAndSendsNothing()

	/**
	 * With the switch off, or no lifecycle change, or another schema, nothing is sent.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @return void
	 */
	public function testNothingIsSentWithoutTheSwitchOrAChange(): void {
		$listener = $this->listener(switch: '');
		$this->decisionList->expects($this->once())->method('render');
		$this->exchange->expects($this->never())->method('requestSend');

		$listener->handle(new ObjectUpdatedEvent($this->minutes(lifecycle: 'approved'), $this->minutes(lifecycle: 'review')));
		$listener->handle(new ObjectUpdatedEvent($this->minutes(lifecycle: 'approved'), $this->minutes(lifecycle: 'approved')));
		$listener->handle(new ObjectUpdatedEvent($this->minutes(lifecycle: 'approved', schemaId: '41'), $this->minutes(lifecycle: 'review', schemaId: '41')));
	}//end testNothingIsSentWithoutTheSwitchOrAChange()

	/**
	 * The registrar subscribes the listener to minutes updates.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-004-approving-the-minutes-produces-a-decision-list-document
	 *
	 * @return void
	 */
	public function testTheRegistrarSubscribesTheListener(): void {
		$seen       = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('addServiceListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$seen): void {
				$seen[] = $event . ' ' . $listener;
			}
		);

		(new ObjectListenerRegistrar(logger: new NullLogger()))->register(dispatcher: $dispatcher);

		$this->assertContains(ObjectUpdatedEvent::class . ' ' . MinutesApprovedListener::class, $seen);
	}//end testTheRegistrarSubscribesTheListener()
}//end class
