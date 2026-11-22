<?php

/**
 * Decidiq AppointmentPositionHolds.
 *
 * Writes the position hold an enacted appointment gives each new member.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One position hold per appointed membership that names a position.
 *
 * A membership used to carry the position as a bare `post` reference, with no
 * duration and no hold type. The position now lives on its own record: the
 * membership says who sits on the body, the hold says which position they
 * fill, how and from when, and which decision appointed them.
 *
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
 */
class AppointmentPositionHolds {

	/**
	 * How an appointed member holds the position unless something says otherwise.
	 *
	 * @var string
	 */
	public const DEFAULT_HOLD_TYPE = 'regular';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object store.
	 * @param LoggerInterface        $logger        Records a hold that could not be written.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The position hold payload for one appointed membership, or null when there is none to write.
	 *
	 * No position means a role-only appointment, and no start date means the
	 * hold would carry an invented one, so both write nothing.
	 *
	 * @param string                                                           $membershipId The membership just created.
	 * @param string|null                                                      $positionId   The position the appointment targets.
	 * @param array{body?: string|null, startDate?: string, decision?: string} $appointment  The body the membership sits on,
	 *                                                                                       when the appointment took effect
	 *                                                                                       (date or date-time) and the
	 *                                                                                       appointing decision.
	 *
	 * @return array<string,string>|null The payload.
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public static function payload(string $membershipId, ?string $positionId, array $appointment): ?array {
		$day = self::day(value: (string)($appointment['startDate'] ?? ''));
		if ($membershipId === '' || $positionId === null || $positionId === '' || $day === '') {
			return null;
		}

		return array_filter(
			[
				'membership' => $membershipId,
				'position' => $positionId,
				'governanceBody' => (string)($appointment['body'] ?? ''),
				'holdType' => self::DEFAULT_HOLD_TYPE,
				'startDate' => $day,
				'appointedBy' => (string)($appointment['decision'] ?? ''),
			],
			static fn (string $value): bool => $value !== ''
		);

	}//end payload()

	/**
	 * Write the hold for one appointed membership; a failure is logged, never thrown.
	 *
	 * The appointment's lifecycle write has already persisted, so a hold that
	 * cannot be written must not undo it.
	 *
	 * @param string                                                           $membershipId The membership just created.
	 * @param string|null                                                      $positionId   The position the appointment targets.
	 * @param array{body?: string|null, startDate?: string, decision?: string} $appointment  See payload().
	 *
	 * @return string|null The new hold's id, or null when none was written.
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function write(string $membershipId, ?string $positionId, array $appointment): ?string {
		$hold = self::payload(membershipId: $membershipId, positionId: $positionId, appointment: $appointment);
		if ($hold === null) {
			return null;
		}

		try {
			$saved = $this->objectService->saveObject(
				object: $hold,
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'decidiq', schema: 'position-hold'))
			);

			return (string)($saved->jsonSerialize()['id'] ?? '');
		} catch (Throwable $e) {
			$this->logger->error(
				'Decidiq: appointment enacted but its position hold could not be written',
				[
					'decision' => ($appointment['decision'] ?? null),
					'membership' => $membershipId,
					'position' => $positionId,
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}

	}//end write()

	/**
	 * The calendar day of a date or date-time, or '' when it has none.
	 *
	 * @param string $value A date (2026-04-10) or date-time (2026-04-10T19:00:00Z).
	 *
	 * @return string The day, as YYYY-MM-DD.
	 */
	private static function day(string $value): string {
		if (preg_match('/^\d{4}-\d{2}-\d{2}/', trim($value), $match) !== 1) {
			return '';
		}

		return $match[0];

	}//end day()
}//end class
