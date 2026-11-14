<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Listener
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\MinutesApprovedListener;
use OCA\Decidiq\Service\CaseSystemExchangeService;
use OCA\Decidiq\Service\DecisionListService;
use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A failing render or send is logged and does not stop the other step.
 *
 * @covers \OCA\Decidiq\Listener\MinutesApprovedListener
 * @uses   \OCA\Decidiq\Service\ListenerSchemaResolver
 */
class MinutesApprovedListenerFailureTest extends TestCase {

	/**
	 * The listener over the given collaborators, the send switch on.
	 *
	 * @param DecisionListService       $decisionList The renderer.
	 * @param CaseSystemExchangeService $exchange     The sender.
	 * @param LoggerInterface           $logger       The logger.
	 *
	 * @return MinutesApprovedListener
	 */
	private function listener(DecisionListService $decisionList, CaseSystemExchangeService $exchange, LoggerInterface $logger): MinutesApprovedListener {
		$mapper = new class {
			/**
			 * The schema of an id.
			 *
			 * @param string|int $id The schema id.
			 *
			 * @return Schema
			 */
			public function find(string|int $id): Schema {
				$schema = new Schema();
				$schema->setSlug('minutes');
				return $schema;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('true');

		return new MinutesApprovedListener(
			decisionList: $decisionList,
			exchange: $exchange,
			schemaResolver: new ListenerSchemaResolver(container: $container, logger: new NullLogger()),
			appConfig: $config,
			logger: $logger
		);
	}//end listener()

	/**
	 * Minutes in a lifecycle.
	 *
	 * @param string $lifecycle The lifecycle.
	 * @param string $meeting   The meeting.
	 *
	 * @return ObjectEntity
	 */
	private function minutes(string $lifecycle, string $meeting = 'meeting-12-maart'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('minutes-12-maart');
		$entity->setRegister('7');
		$entity->setSchema('12');
		$entity->setObject(['lifecycle' => $lifecycle, 'meeting' => $meeting]);
		return $entity;
	}//end minutes()

	/**
	 * A failing render is logged and the file is still sent.
	 *
	 * @return void
	 */
	public function testAFailingRenderIsLoggedAndTheFileIsStillSent(): void {
		$decisionList = $this->createMock(DecisionListService::class);
		$decisionList->method('render')->willThrowException(new RuntimeException('template missing'));
		$exchange = $this->createMock(CaseSystemExchangeService::class);
		$exchange->expects($this->once())->method('requestSend')->with('meeting-12-maart', 'system');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Decidiq: the decision list could not be rendered',
			['meeting' => 'meeting-12-maart', 'exception' => 'template missing']
		);

		$this->listener($decisionList, $exchange, $logger)->handle(new ObjectUpdatedEvent($this->minutes('approved'), $this->minutes('review')));
	}//end testAFailingRenderIsLoggedAndTheFileIsStillSent()

	/**
	 * A failing send is logged after the list was rendered.
	 *
	 * @return void
	 */
	public function testAFailingSendIsLogged(): void {
		$decisionList = $this->createMock(DecisionListService::class);
		$decisionList->expects($this->once())->method('render');
		$exchange = $this->createMock(CaseSystemExchangeService::class);
		$exchange->method('requestSend')->willThrowException(new RuntimeException('case system offline'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Decidiq: the meeting file was not sent on approval',
			['meeting' => 'meeting-12-maart', 'exception' => 'case system offline']
		);

		$this->listener($decisionList, $exchange, $logger)->handle(new ObjectUpdatedEvent($this->minutes('approved'), $this->minutes('review')));
	}//end testAFailingSendIsLogged()

	/**
	 * Another event, a lifecycle outside approved/signed, or minutes without a
	 * meeting do nothing.
	 *
	 * @return void
	 */
	public function testNothingHappensOutsideAnApprovalWithAMeeting(): void {
		$decisionList = $this->createMock(DecisionListService::class);
		$decisionList->expects($this->never())->method('render');
		$exchange = $this->createMock(CaseSystemExchangeService::class);
		$exchange->expects($this->never())->method('requestSend');
		$listener = $this->listener($decisionList, $exchange, $this->createMock(LoggerInterface::class));

		$listener->handle(new Event());
		$listener->handle(new ObjectUpdatedEvent($this->minutes('review'), $this->minutes('draft')));
		$listener->handle(new ObjectUpdatedEvent($this->minutes('approved', ''), $this->minutes('review', '')));
	}//end testNothingHappensOutsideAnApprovalWithAMeeting()
}//end class
