<?php
/**
 * Decidesk Regulator Access Service
 *
 * Issues time-bound, scope-limited, signed read-only access grants for external
 * auditors and regulators, validates them, and filters data by scope.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * HMAC-signed bearer grants for read-only regulator/auditor access.
 *
 * The token is a compact, self-describing, HMAC-signed structure (header.payload
 * .signature, base64url) so it can be validated without a DB lookup, while a
 * persisted grant record allows revocation and per-view audit logging.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
 */
class RegulatorAccessService
{

    /**
     * Register slug.
     *
     * @var string
     */
    private const REGISTER = 'decidesk';

    /**
     * App-config key holding the signing secret.
     *
     * @var string
     */
    private const SECRET_KEY = 'regulator_token_secret';

    /**
     * Scopes that are valid for a grant.
     *
     * @var array<string>
     */
    private const VALID_SCOPES = ['audit-committee-only', 'all-resolutions', 'all-records'];

    /**
     * Constructor.
     *
     * @param ContainerInterface   $container The DI container.
     * @param LoggerInterface      $logger    The logger.
     * @param IAppConfig           $appConfig The app config (signing secret).
     * @param BoardAuditLogService $auditLog  The audit log service.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly IAppConfig $appConfig,
        private readonly BoardAuditLogService $auditLog,
    ) {
    }//end __construct()

    /**
     * Return the signing secret, generating it on first use.
     *
     * @return string
     */
    private function secret(): string
    {
        $secret = $this->appConfig->getValueString('decidesk', self::SECRET_KEY, '');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            $this->appConfig->setValueString('decidesk', self::SECRET_KEY, $secret);
        }

        return $secret;

    }//end secret()

    /**
     * Base64url-encode without padding.
     *
     * @param string $data Raw bytes.
     *
     * @return string
     */
    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');

    }//end b64()

    /**
     * Base64url-decode.
     *
     * @param string $data Encoded string.
     *
     * @return string
     */
    private function unb64(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);

    }//end unb64()

    /**
     * Grant time-bound read-only access.
     *
     * @param string $recipientEmail Recipient email.
     * @param string $scope          Scope enum value.
     * @param int    $durationDays   Validity in days.
     * @param string $actorUuid      Granting user UUID (for audit).
     *
     * @return array{token:string,grantId:string,expiresAt:string}
     *
     * @throws \RuntimeException On invalid scope.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
     */
    public function grantAccess(string $recipientEmail, string $scope, int $durationDays, string $actorUuid): array
    {
        if (in_array($scope, self::VALID_SCOPES, true) === false) {
            throw new \RuntimeException('Invalid scope');
        }

        $grantId   = bin2hex(random_bytes(16));
        $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+'.$durationDays.' days');

        $payload = [
            'grantId'   => $grantId,
            'recipient' => $recipientEmail,
            'scope'     => $scope,
            'exp'       => $expiresAt->getTimestamp(),
        ];

        $header = $this->b64(data: (string) json_encode(['alg' => 'HS256', 'typ' => 'DSK-REG']));
        $body   = $this->b64(data: (string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $sig    = $this->b64(data: hash_hmac('sha256', $header.'.'.$body, $this->secret(), true));
        $token  = $header.'.'.$body.'.'.$sig;

        // Persist a revocable grant record.
        $this->objectServiceSave(
            grant: [
                'grantId'   => $grantId,
                'recipient' => $recipientEmail,
                'scope'     => $scope,
                'status'    => 'active',
                'expiresAt' => $expiresAt->format('c'),
            ]
        );

        $this->auditLog->append($actorUuid, 'notice-sent', [$grantId]);

        return ['token' => $token, 'grantId' => $grantId, 'expiresAt' => $expiresAt->format('c')];

    }//end grantAccess()

    /**
     * Persist a grant record (best-effort; missing OR is non-fatal).
     *
     * @param array<string,mixed> $grant Grant data.
     *
     * @return void
     */
    private function objectServiceSave(array $grant): void
    {
        try {
            $objectService      = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $grant['relations'] = [];
            $objectService->saveObject(register: self::REGISTER, schema: 'board-proxy', object: $grant);
        } catch (\Throwable $e) {
            $this->logger->warning('Decidesk: could not persist regulator grant', ['exception' => $e->getMessage()]);
        }

    }//end objectServiceSave()

    /**
     * Validate a token's signature and expiry.
     *
     * @param string $token The bearer token.
     *
     * @return array{valid:bool,scope:?string,recipient:?string,grantId:?string}
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
     */
    public function validateToken(string $token): array
    {
        $invalid = ['valid' => false, 'scope' => null, 'recipient' => null, 'grantId' => null];
        $parts   = explode('.', $token);
        if (count($parts) !== 3) {
            return $invalid;
        }

        [$header, $body, $sig] = $parts;
        $expected = $this->b64(data: hash_hmac('sha256', $header.'.'.$body, $this->secret(), true));
        if (hash_equals($expected, $sig) === false) {
            return $invalid;
        }

        $payload = json_decode($this->unb64(data: $body), true);
        if (is_array($payload) === false) {
            return $invalid;
        }

        if ((int) ($payload['exp'] ?? 0) < time()) {
            return $invalid;
        }

        return [
            'valid'     => true,
            'scope'     => ($payload['scope'] ?? null),
            'recipient' => ($payload['recipient'] ?? null),
            'grantId'   => ($payload['grantId'] ?? null),
        ];

    }//end validateToken()

    /**
     * Filter a list of board data items by a regulator scope.
     *
     * @param string                         $scope Scope enum value.
     * @param array<int,array<string,mixed>> $data  Items each carrying an accessLevel/type.
     *
     * @return array<int,array<string,mixed>>
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
     */
    public function filterByScope(string $scope, array $data): array
    {
        if ($scope === 'all-records') {
            return array_values($data);
        }

        if ($scope === 'all-resolutions') {
            return array_values(
                array_filter(
                    $data,
                    static function (array $item): bool {
                        return ($item['_type'] ?? '') === 'resolution';
                    }
                )
            );
        }

        // Audit-committee-only scope.
        return array_values(
            array_filter(
                $data,
                static function (array $item): bool {
                    return in_array(($item['accessLevel'] ?? ''), ['audit-committee', 'external-auditor'], true);
                }
            )
        );

    }//end filterByScope()
}//end class
