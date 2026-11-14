<?php

/**
 * Decidiq Reaction Intake Service
 *
 * Reaction submission + moderation for citizen-participation consultations.
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
 * @spec openspec/specs/citizen-participation/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Kernel\Contracts\ReactionIntakeServiceInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Stateless service handling ConsultationReaction intake and moderation.
 *
 * Intake matrix (auth posture x policy):
 * - Authenticated + pre-moderation  -> pending
 * - Authenticated + post-moderation -> approved (auto)
 * - Anonymous (only when enabled)   -> ALWAYS pending (pre-moderation), pseudonymous submitterId
 *
 * @spec openspec/specs/citizen-participation/spec.md
 */
class ReactionIntakeService implements ReactionIntakeServiceInterface {

	/**
	 * Maximum reaction body length in bytes (server-side payload cap).
	 */
	public const MAX_BODY_BYTES = 5000;

	/**
	 * Constructor for ReactionIntakeService.
	 *
	 * @param LoggerInterface $logger The logger
	 * @param IAppConfig $appConfig App config (pseudonym secret)
	 * @param ParticipationLifecycleService $lifecycleService Deadline/status guards
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly IAppConfig $appConfig,
		private readonly ParticipationLifecycleService $lifecycleService,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Resolve the OpenRegister ObjectService lazily.
	 *
	 * @return object The ObjectService instance.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()

	/**
	 * Normalise a saved ObjectEntity (or array) to an array.
	 *
	 * @param mixed $saved The saveObject() return value.
	 * @param array<string, mixed> $fallback The original payload.
	 *
	 * @return array<string, mixed> The persisted object as an array.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function normaliseSaved(mixed $saved, array $fallback): array {
		return (new SavedObjectNormaliser())->toArray(saved: $saved, fallback: $fallback);
	}//end normaliseSaved()

	/**
	 * Submit a reaction on a consultation.
	 *
	 * Enforces: consultation open + deadline (server-side, independent of
	 * stored status), payload cap, anonymous gate (per-consultation
	 * anonymousReactionsAllowed), and moderation defaulting. Anonymous
	 * submissions receive a pseudonymous submitterId and are ALWAYS pending;
	 * authenticated submissions auto-approve only under post-moderation.
	 *
	 * @param string $consultationId The consultation UUID.
	 * @param string $body The reaction text.
	 * @param string|null $ncUid The authenticated Nextcloud UID, or null for anonymous.
	 * @param string|null $clientSeed A client fingerprint (e.g. remote addr) for the anon pseudonym; never stored raw.
	 *
	 * @return array<string, mixed> The created ConsultationReaction object.
	 *
	 * @throws RuntimeException When the consultation is not found, or closed or past its
	 *                          deadline (then the lifecycle service's window exception, a
	 *                          RuntimeException the responder answers with 400).
	 * @throws InvalidArgumentException When the body is empty/oversized or anonymous intake is not enabled.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 * @spec openspec/specs/p3-citizen-participation/spec.md
	 */
	public function submitReaction(string $consultationId, string $body, ?string $ncUid, ?string $clientSeed = null): array {
		$body = $this->validBody(body: $body);

		$objectService = $this->objectService();
		$entity = $objectService->find(id: $consultationId, register: 'decidiq', schema: 'public-consultation');
		if ($entity === null) {
			throw new RuntimeException("PublicConsultation {$consultationId} not found");
		}

		$consultation = $entity->jsonSerialize();

		// Server-side window guard (open + future deadline), independent of stored status.
		$this->lifecycleService->assertConsultationAcceptsSubmissions(consultation: $consultation);

		$isAnonymous = $this->anonymousAllowedOrRefused(consultation: $consultation, ncUid: $ncUid);

		$policy = (string)($consultation['moderationPolicy'] ?? 'pre-moderation');

		$submitterId = $this->resolveSubmitterId(
			consultationId: $consultationId,
			ncUid: $ncUid,
			isAnonymous: $isAnonymous,
			clientSeed: $clientSeed
		);
		$moderationStatus = $this->resolveModerationStatus(isAnonymous: $isAnonymous, policy: $policy);

		$reaction = [
			'body' => $body,
			'moderationStatus' => $moderationStatus,
			'submitterId' => $submitterId,
			'submittedAt' => (new DateTimeImmutable())->format(\DateTimeInterface::ATOM),
			'relations' => [
				['register' => 'decidiq', 'schema' => 'public-consultation', 'id' => $consultationId],
			],
		];

		$saved = $objectService->saveObject(register: 'decidiq', schema: 'consultation-reaction', object: $reaction);
		$result = $this->normaliseSaved(saved: $saved, fallback: $reaction);

		return $result;
	}//end submitReaction()

	/**
	 * Import one response of the consultation's intake form as a reaction.
	 *
	 * An imported response always starts as `pending`, whatever the
	 * consultation's moderation policy: it enters the moderation queue like an
	 * anonymous reaction and is never public before a moderator approves it.
	 * The reaction's id is derived from the source key, so importing the same
	 * response again finds it and creates nothing.
	 *
	 * @param string      $consultationId The consultation UUID.
	 * @param string      $body           The response text.
	 * @param string      $sourceKey      A key unique to the response, e.g. `forms:<formId>:<submissionId>`.
	 * @param string|null $submitter      The Nextcloud UID of a named respondent; null, empty or an
	 *                                    `anon-` id for an anonymous one (then stored pseudonymously).
	 * @param int         $submittedAt    When the response was given, epoch seconds (0: now).
	 *
	 * @return array<string, mixed>|null The created reaction, or null when it was imported before.
	 *
	 * @throws RuntimeException When the consultation is not found.
	 * @throws InvalidArgumentException When the body is empty or too long.
	 *
	 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-004-consultation-intake-shall-be-offered-via-the-forms-leaf-into-the-existing-reaction-path
	 */
	public function importReaction(string $consultationId, string $body, string $sourceKey, ?string $submitter, int $submittedAt): ?array {
		$body = $this->validBody(body: $body);

		$objectService = $this->objectService();
		if ($objectService->find(id: $consultationId, register: 'decidiq', schema: 'public-consultation') === null) {
			throw new RuntimeException("PublicConsultation {$consultationId} not found");
		}

		$uuid = self::importedReactionId(consultationId: $consultationId, sourceKey: $sourceKey);
		if ($objectService->find(id: $uuid, register: 'decidiq', schema: 'consultation-reaction') !== null) {
			return null;
		}

		$reaction = [
			'body' => $body,
			'moderationStatus' => 'pending',
			'submitterId' => $this->importedSubmitterId(consultationId: $consultationId, submitter: $submitter, sourceKey: $sourceKey),
			'submittedAt' => self::atomOf(epoch: $submittedAt),
			'consultation' => $consultationId,
			'relations' => [
				['register' => 'decidiq', 'schema' => 'public-consultation', 'id' => $consultationId],
			],
		];

		$saved = $objectService->saveObject(register: 'decidiq', schema: 'consultation-reaction', object: $reaction, uuid: $uuid);

		return $this->normaliseSaved(saved: $saved, fallback: $reaction);
	}//end importReaction()

	/**
	 * The stable reaction id of an imported response: a UUID derived from the consultation and the source key.
	 *
	 * @param string $consultationId The consultation UUID.
	 * @param string $sourceKey      The response's source key.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-004-consultation-intake-shall-be-offered-via-the-forms-leaf-into-the-existing-reaction-path
	 */
	public static function importedReactionId(string $consultationId, string $sourceKey): string {
		$hex = sha1('decidiq:consultation-reaction:' . $consultationId . ':' . $sourceKey);
		$hex = substr($hex, 0, 12) . '5' . substr($hex, 13, 3) . dechex((hexdec($hex[16]) & 0x3) | 0x8) . substr($hex, 17, 15);

		return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
	}//end importedReactionId()

	/**
	 * The submitter id of an imported response: the UID of a named respondent, else a pseudonym.
	 *
	 * @param string      $consultationId The consultation UUID.
	 * @param string|null $submitter      The respondent as the source names them.
	 * @param string      $sourceKey      The response's source key, the pseudonym seed of an unnamed respondent.
	 *
	 * @return string
	 */
	private function importedSubmitterId(string $consultationId, ?string $submitter, string $sourceKey): string {
		$submitter = (string)$submitter;
		if ($submitter !== '' && str_starts_with($submitter, 'anon-') === false) {
			return $submitter;
		}

		$seed = $submitter;
		if ($seed === '') {
			$seed = $sourceKey;
		}

		return $this->pseudonymousId(consultationId: $consultationId, seed: 'import:' . $seed);
	}//end importedSubmitterId()

	/**
	 * An epoch as an ATOM date, now for 0 or less.
	 *
	 * @param int $epoch Epoch seconds.
	 *
	 * @return string
	 */
	private static function atomOf(int $epoch): string {
		if ($epoch <= 0) {
			return (new DateTimeImmutable())->format(\DateTimeInterface::ATOM);
		}

		return (new DateTimeImmutable('@' . $epoch))->format(\DateTimeInterface::ATOM);
	}//end atomOf()

	/**
	 * The trimmed reaction body; refused when empty or longer than MAX_BODY_BYTES.
	 *
	 * @param string $body The submitted body.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When the body is empty or too long.
	 *
	 * @spec openspec/specs/p3-citizen-participation/spec.md
	 */
	private function validBody(string $body): string {
		$body = trim($body);
		if ($body === '') {
			throw new InvalidArgumentException('Reaction body must not be empty');
		}

		if (strlen($body) > self::MAX_BODY_BYTES) {
			throw new InvalidArgumentException(
				sprintf('Reaction body exceeds the maximum of %d bytes', self::MAX_BODY_BYTES)
			);
		}

		return $body;
	}//end validBody()

	/**
	 * Whether the reaction is anonymous. Anonymous intake is allowed only when staff
	 * enabled it on this consultation.
	 *
	 * @param array<string, mixed> $consultation The consultation.
	 * @param string|null          $ncUid        The submitting user, or null/'' when anonymous.
	 *
	 * @return bool
	 *
	 * @throws InvalidArgumentException When an anonymous reaction is not allowed.
	 *
	 * @spec openspec/specs/p3-citizen-participation/spec.md
	 */
	private function anonymousAllowedOrRefused(array $consultation, ?string $ncUid): bool {
		$isAnonymous = ($ncUid === null || $ncUid === '');
		if ($isAnonymous === true && ($consultation['anonymousReactionsAllowed'] ?? false) !== true) {
			throw new InvalidArgumentException('Anonymous reactions are not enabled for this consultation');
		}

		return $isAnonymous;
	}//end anonymousAllowedOrRefused()

	/**
	 * Approve a pending reaction (staff action).
	 *
	 * Sets moderationStatus to 'approved' so the reaction surfaces in the
	 * consultation's reactions relation and becomes eligible for publication.
	 *
	 * @param string $reactionId The reaction UUID.
	 * @param string|null $reason Optional moderation note.
	 *
	 * @return array<string, mixed> The updated reaction object.
	 *
	 * @throws RuntimeException When the reaction is not found.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function approveReaction(string $reactionId, ?string $reason = null): array {
		$objectService = $this->objectService();
		$entity = $objectService->find(id: $reactionId, register: 'decidiq', schema: 'consultation-reaction');
		if ($entity === null) {
			throw new RuntimeException("ConsultationReaction {$reactionId} not found");
		}

		$reaction = $entity->jsonSerialize();

		$reaction['moderationStatus'] = 'approved';
		if ($reason !== null && $reason !== '') {
			$reaction['moderationReason'] = $reason;
		}

		$saved = $objectService->saveObject(register: 'decidiq', schema: 'consultation-reaction', object: $reaction);

		// Increment the parent consultation's submissionCount when a reaction is
		// approved (pre-moderation: reactions start as pending and only count
		// toward the public total once a moderator approves them).
		$this->incrementConsultationSubmissionCount(reaction: $reaction, objectService: $objectService);

		return $this->normaliseSaved(saved: $saved, fallback: $reaction);
	}//end approveReaction()

	/**
	 * Increment the parent consultation's submissionCount by 1.
	 *
	 * Resolves the consultation id from the reaction's relations array,
	 * fetches the consultation, bumps its submissionCount, and saves it.
	 * Failures are swallowed (log-only) so a missing relation never breaks
	 * the moderation flow.
	 *
	 * @param array<string,mixed> $reaction The approved reaction.
	 * @param \OCA\OpenRegister\Contract\ObjectServiceInterface $objectService The OR object service.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function incrementConsultationSubmissionCount(array $reaction, mixed $objectService): void {
		$relations = $reaction['relations'] ?? [];
		$consultationId = null;
		foreach ((array)$relations as $rel) {
			if (is_array($rel) === true && (($rel['schema'] ?? '') === 'public-consultation') && isset($rel['id']) === true) {
				$consultationId = (string)$rel['id'];
				break;
			}
		}

		if ($consultationId === null) {
			return;
		}

		try {
			$consultationEntity = $objectService->find(id: $consultationId, register: 'decidiq', schema: 'public-consultation');
			if ($consultationEntity === null) {
				return;
			}

			$consultation = $consultationEntity->jsonSerialize();
			$consultation['submissionCount'] = ((int)($consultation['submissionCount'] ?? 0)) + 1;
			$objectService->saveObject(register: 'decidiq', schema: 'public-consultation', object: $consultation);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ReactionIntakeService: could not increment consultation submissionCount: ' . $e->getMessage()
			);
		}//end try

	}//end incrementConsultationSubmissionCount()

	/**
	 * Reject a pending reaction (staff action).
	 *
	 * Sets moderationStatus to 'rejected' with a reason. The object is retained
	 * (soft-delete conventions) for audit and never counts toward
	 * submissionCount.
	 *
	 * @param string $reactionId The reaction UUID.
	 * @param string $reason The rejection reason.
	 *
	 * @return array<string, mixed> The updated reaction object.
	 *
	 * @throws RuntimeException When the reaction is not found.
	 * @throws InvalidArgumentException When no reason is given.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function rejectReaction(string $reactionId, string $reason): array {
		$reason = trim($reason);
		if ($reason === '') {
			throw new InvalidArgumentException('A rejection reason is required');
		}

		$objectService = $this->objectService();
		$entity = $objectService->find(id: $reactionId, register: 'decidiq', schema: 'consultation-reaction');
		if ($entity === null) {
			throw new RuntimeException("ConsultationReaction {$reactionId} not found");
		}

		$reaction = $entity->jsonSerialize();
		$reaction['moderationStatus'] = 'rejected';
		$reaction['moderationReason'] = $reason;

		$saved = $objectService->saveObject(register: 'decidiq', schema: 'consultation-reaction', object: $reaction);

		return $this->normaliseSaved(saved: $saved, fallback: $reaction);
	}//end rejectReaction()

	/**
	 * Resolve the submitter id recorded on a reaction.
	 *
	 * A named submitter is stored by Nextcloud UID; an anonymous one is
	 * reduced to a stable pseudonymous token that reveals no PII.
	 *
	 * @param string $consultationId The consultation UUID.
	 * @param string|null $ncUid The Nextcloud UID of a named submitter.
	 * @param bool $isAnonymous Whether the reaction is anonymous.
	 * @param string|null $clientSeed Client fingerprint for the anonymous token.
	 *
	 * @return string|null The submitter id to persist.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function resolveSubmitterId(
		string $consultationId,
		?string $ncUid,
		bool $isAnonymous,
		?string $clientSeed,
	): ?string {
		if ($isAnonymous === false) {
			return $ncUid;
		}

		return $this->pseudonymousId(consultationId: $consultationId, seed: ($clientSeed ?? ''));
	}//end resolveSubmitterId()

	/**
	 * Decide the moderation status a new reaction starts in.
	 *
	 * Anonymous reactions are ALWAYS pre-moderated regardless of the
	 * consultation's policy; only a named submitter under post-moderation is
	 * auto-approved.
	 *
	 * @param bool $isAnonymous Whether the reaction is anonymous.
	 * @param string $policy The consultation's moderation policy.
	 *
	 * @return string Either 'approved' or 'pending'.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function resolveModerationStatus(bool $isAnonymous, string $policy): string {
		if ($isAnonymous === false && $policy === 'post-moderation') {
			return 'approved';
		}

		return 'pending';
	}//end resolveModerationStatus()

	/**
	 * Build a stable, opaque pseudonymous submitter id for an anonymous reaction.
	 *
	 * HMAC(secret, consultationId:clientSeed) so the same anonymous client on
	 * the same consultation maps to one stable token (enabling per-client
	 * dedup/rate context) while the token reveals no PII and cannot be reversed
	 * without the server-side secret.
	 *
	 * @param string $consultationId The consultation UUID.
	 * @param string $seed A client fingerprint (never stored raw).
	 *
	 * @return string A 'anon-' prefixed hex token.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function pseudonymousId(string $consultationId, string $seed): string {
		$secret = $this->appConfig->getValueString(AppIdentity::APP_ID, 'participation_pseudonym_secret', '');
		if ($secret === '') {
			$secret = bin2hex(random_bytes(32));
			$this->appConfig->setValueString(AppIdentity::APP_ID, 'participation_pseudonym_secret', $secret);
		}

		return 'anon-' . hash_hmac('sha256', $consultationId . ':' . $seed, $secret);
	}//end pseudonymousId()
}//end class
