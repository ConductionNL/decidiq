<?php

/**
 * The status the remaining refusal exceptions carry, and the base class each marker refusal extends.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Exception
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

namespace OCA\Decidiq\Tests\Unit\Exception;

use InvalidArgumentException;
use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\ChangeRecommendationRefusedException;
use OCA\Decidiq\Exception\CompetenceRefusedException;
use OCA\Decidiq\Exception\ConfidentialityUnreadableException;
use OCA\Decidiq\Exception\ConsultationResponseRefusedException;
use OCA\Decidiq\Exception\DecisionCompetenceRefusedException;
use OCA\Decidiq\Exception\GoverningDocumentVersionRefusedException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Exception\MissingRelationException;
use OCA\Decidiq\Exception\NotFoundException;
use OCA\Decidiq\Exception\OndermandaatRefusedException;
use OCA\Decidiq\Exception\ParticipationValidationException;
use OCA\Decidiq\Exception\ParticipationWindowClosedException;
use OCA\Decidiq\Exception\PlannedAgendaReportException;
use OCA\Decidiq\Exception\SpeakingRequestRefusedException;
use OCA\Decidiq\Exception\TeamsMeetingRefusedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Each status-carrying refusal answers with its default status unless told otherwise.
 *
 * @covers \OCA\Decidiq\Exception\ChangeRecommendationRefusedException
 * @covers \OCA\Decidiq\Exception\ConsultationResponseRefusedException
 * @covers \OCA\Decidiq\Exception\PlannedAgendaReportException
 * @covers \OCA\Decidiq\Exception\SpeakingRequestRefusedException
 * @covers \OCA\Decidiq\Exception\TeamsMeetingRefusedException
 * @covers \OCA\Decidiq\Exception\AccessDeniedException
 * @covers \OCA\Decidiq\Exception\CompetenceRefusedException
 * @covers \OCA\Decidiq\Exception\ConfidentialityUnreadableException
 * @covers \OCA\Decidiq\Exception\DecisionCompetenceRefusedException
 * @covers \OCA\Decidiq\Exception\GoverningDocumentVersionRefusedException
 * @covers \OCA\Decidiq\Exception\MissingObjectException
 * @covers \OCA\Decidiq\Exception\MissingRelationException
 * @covers \OCA\Decidiq\Exception\NotFoundException
 * @covers \OCA\Decidiq\Exception\OndermandaatRefusedException
 * @covers \OCA\Decidiq\Exception\ParticipationValidationException
 * @covers \OCA\Decidiq\Exception\ParticipationWindowClosedException
 */
class StatusCarryingRefusalsTest extends TestCase {

	/**
	 * The default status of each refusal.
	 *
	 * @return void
	 */
	public function testEachRefusalHasItsDefaultStatus(): void {
		self::assertSame(409, (new ChangeRecommendationRefusedException(message: 'm'))->getStatus());
		self::assertSame(409, (new ConsultationResponseRefusedException(message: 'm'))->getStatus());
		self::assertSame(422, (new PlannedAgendaReportException(message: 'm'))->getStatus());
		self::assertSame(422, (new SpeakingRequestRefusedException(message: 'm'))->getStatus());
		self::assertSame(409, (new TeamsMeetingRefusedException(message: 'm'))->getStatus());
	}//end testEachRefusalHasItsDefaultStatus()

	/**
	 * A given status is answered instead, and the message is kept.
	 *
	 * @return void
	 */
	public function testAGivenStatusIsAnsweredAndTheMessageKept(): void {
		$refusals = [
			new ChangeRecommendationRefusedException(message: 'Change', status: 403),
			new ConsultationResponseRefusedException(message: 'Consultation', status: 404),
			new PlannedAgendaReportException(message: 'Agenda', status: 404),
			new SpeakingRequestRefusedException(message: 'Speaking', status: 403),
			new TeamsMeetingRefusedException(message: 'Teams', status: 502),
		];

		self::assertSame([403, 404, 404, 403, 502], array_map(static fn ($e): int => $e->getStatus(), $refusals));
		self::assertSame(
			['Change', 'Consultation', 'Agenda', 'Speaking', 'Teams'],
			array_map(static fn ($e): string => $e->getMessage(), $refusals)
		);
		foreach ($refusals as $refusal) {
			self::assertInstanceOf(RuntimeException::class, $refusal);
		}
	}//end testAGivenStatusIsAnsweredAndTheMessageKept()

	/**
	 * The planned agenda report carries its status as the code and chains the cause.
	 *
	 * @return void
	 */
	public function testThePlannedAgendaReportCarriesCodeAndCause(): void {
		$cause  = new RuntimeException('upstream down');
		$report = new PlannedAgendaReportException(message: 'Report failed', status: 503, previous: $cause);

		self::assertSame(503, $report->getCode());
		self::assertSame($cause, $report->getPrevious());
		self::assertSame(422, (new PlannedAgendaReportException(message: 'm'))->getCode());
		self::assertNull((new PlannedAgendaReportException(message: 'm'))->getPrevious());
	}//end testThePlannedAgendaReportCarriesCodeAndCause()

	/**
	 * The refusals that keep their status in a property leave the code at zero.
	 *
	 * @return void
	 */
	public function testThePropertyStatusRefusalsKeepCodeZero(): void {
		self::assertSame(0, (new ChangeRecommendationRefusedException(message: 'm', status: 403))->getCode());
		self::assertSame(0, (new ConsultationResponseRefusedException(message: 'm'))->getCode());
		self::assertSame(0, (new SpeakingRequestRefusedException(message: 'm', status: 403))->getCode());
		self::assertSame(0, (new TeamsMeetingRefusedException(message: 'm'))->getCode());
	}//end testThePropertyStatusRefusalsKeepCodeZero()

	/**
	 * Validation refusals are invalid-argument errors, the others runtime errors, so callers catch them apart.
	 *
	 * @return void
	 */
	public function testEachMarkerRefusalExtendsItsBaseClass(): void {
		$invalid = [
			new CompetenceRefusedException(message: 'm'),
			new MissingObjectException(message: 'm'),
			new ParticipationValidationException(message: 'm'),
		];
		$runtime = [
			new AccessDeniedException(message: 'm'),
			new ConfidentialityUnreadableException(message: 'm'),
			new DecisionCompetenceRefusedException(message: 'm'),
			new GoverningDocumentVersionRefusedException(message: 'm'),
			new MissingRelationException(message: 'm'),
			new NotFoundException(message: 'm'),
			new OndermandaatRefusedException(message: 'm'),
			new ParticipationWindowClosedException(message: 'm'),
		];

		foreach ($invalid as $refusal) {
			self::assertInstanceOf(InvalidArgumentException::class, $refusal);
		}

		foreach ($runtime as $refusal) {
			self::assertInstanceOf(RuntimeException::class, $refusal);
			self::assertNotInstanceOf(InvalidArgumentException::class, $refusal);
		}
	}//end testEachMarkerRefusalExtendsItsBaseClass()
}//end class
