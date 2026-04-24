<?php

/**
 * Decidesk Decision Digest Job
 *
 * Background job for sending weekly digest emails about decisions and action items.
 *
 * @category BackgroundJob
 * @package  OCA\Decidesk\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\Decidesk\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use OCP\Mail\IMailer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * TimedJob for sending weekly decision digest emails.
 *
 * Runs weekly (604800 seconds = 7 days). Sends email to chairs and secretaries
 * of each governance body with upcoming action items, overdue items, and pending decisions.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-6
 */
class DecisionDigestJob extends TimedJob
{
    /**
     * Construct the DecisionDigestJob.
     *
     * @param ITimeFactory       $time      Time factory
     * @param ContainerInterface $container DI container
     * @param LoggerInterface    $logger    Logger
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-6
     */
    public function __construct(
        ITimeFactory $time,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: 604800);
    }//end __construct()

    /**
     * Run the digest job.
     *
     * @param mixed $argument Not used
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-6
     */
    protected function run(mixed $argument): void
    {
        $this->logger->info("DecisionDigestJob: starting");

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $appConfig     = $this->container->get(IAppConfig::class);
            $mailer        = $this->container->get(IMailer::class);

            $objectService->setRegister('decidesk');
            $objectService->setSchema('GovernanceBody');

            $bodies    = $objectService->findAll();
            $sentCount = 0;

            foreach ($bodies as $body) {
                $bodyId = $body['@self']['id'] ?? null;
                if ($bodyId === null || $bodyId === '') {
                    continue;
                }

                $digestionEnabled = $appConfig->getValueBool('decidesk', "digest_enabled_$bodyId", true);
                if ($digestionEnabled === false) {
                    $this->logger->info("DecisionDigestJob: digest disabled for body $bodyId");
                    continue;
                }

                try {
                    $this->sendDigestForBody(bodyId: $bodyId, body: $body, objectService: $objectService, mailer: $mailer);
                    $sentCount++;
                } catch (\Throwable $e) {
                    $this->logger->error(
                        "DecisionDigestJob: failed to send digest for body $bodyId: {$e->getMessage()}"
                    );
                }//end try
            }//end foreach

            $this->logger->info("DecisionDigestJob: completed, sent $sentCount digests");
        } catch (\Throwable $e) {
            $this->logger->error("DecisionDigestJob failed: {$e->getMessage()}");
        }//end try
    }//end run()

    /**
     * Send digest for a single governance body.
     *
     * @param string  $bodyId        Governance body ID
     * @param array   $body          Governance body object
     * @param object  $objectService ObjectService instance
     * @param IMailer $mailer        Mailer instance
     *
     * @return void
     */
    private function sendDigestForBody(
        string $bodyId,
        array $body,
        object $objectService,
        IMailer $mailer
    ): void {
        $objectService->setSchema('ActionItem');
        $upcomingItems = $objectService->findAll(params: ['dueDate' => ['within' => [0, 14]]]);
        $overdueItems  = $objectService->findAll(params: ['taskStatus' => 'overdue']);

        $objectService->setSchema('Decision');
        $pendingDecisions = $objectService->findAll(
            params: ['lifecycle' => ['legal-review', 'committee-review']]
        );

        if (empty($upcomingItems) === true && empty($overdueItems) === true && empty($pendingDecisions) === true) {
            $this->logger->info("DecisionDigestJob: no items for body $bodyId, skipping email");
            return;
        }

        $bodyName = $body['name'] ?? $body['title'] ?? 'Governance Body';
        $subject  = "Decidesk weekoverzicht — $bodyName — ".date('d-m-Y');

        $htmlBody = $this->buildHtmlBody(upcomingItems: $upcomingItems, overdueItems: $overdueItems, pendingDecisions: $pendingDecisions);
        $textBody = $this->buildTextBody(upcomingItems: $upcomingItems, overdueItems: $overdueItems, pendingDecisions: $pendingDecisions);

        $recipients = $this->getRecipients(bodyId: $bodyId, objectService: $objectService);
        foreach ($recipients as $recipientEmail) {
            try {
                $message = $mailer->createMessage();
                $message->setSubject($subject);
                $message->setTo([$recipientEmail]);
                $message->setHtmlBody($htmlBody);
                $message->setPlainTextBody($textBody);

                $mailer->send($message);
                $this->logger->info(
                    "DecisionDigestJob: sent digest to $recipientEmail for body $bodyId"
                );
            } catch (\Throwable $e) {
                $this->logger->error(
                    "DecisionDigestJob: failed to send to $recipientEmail: {$e->getMessage()}"
                );
            }//end try
        }//end foreach
    }//end sendDigestForBody()

    /**
     * Get recipient email addresses for a governance body.
     *
     * @param string $bodyId        Governance body ID
     * @param object $objectService ObjectService instance
     *
     * @return array Email addresses of chairs and secretaries
     */
    private function getRecipients(string $bodyId, object $objectService): array
    {
        $recipients = [];
        try {
            $objectService->setSchema('Person');
            $people = $objectService->findAll(params: ['governanceBodyId' => $bodyId]);

            foreach ($people as $person) {
                $role = $person['role'] ?? '';
                if (in_array($role, ['chair', 'secretary'], strict: true) === true) {
                    $email = $person['email'] ?? null;
                    if (empty($email) === false) {
                        $recipients[] = $email;
                    }
                }//end if
            }//end foreach
        } catch (\Throwable) {
            // OpenRegister unavailable or schema missing — return empty recipients list.
        }

        return $recipients;
    }//end getRecipients()

    /**
     * Build HTML email body.
     *
     * @param array $upcomingItems    Upcoming action items
     * @param array $overdueItems     Overdue action items
     * @param array $pendingDecisions Pending decisions
     *
     * @return string HTML body
     */
    private function buildHtmlBody(array $upcomingItems, array $overdueItems, array $pendingDecisions): string
    {
        $html = '<html><body style="font-family: Arial, sans-serif;"><h2>Decidesk Weekoverzicht</h2>';

        if (empty($upcomingItems) === false) {
            $html .= '<h3>Aankomende actiepunten</h3><ul>';
            foreach ($upcomingItems as $item) {
                $title   = htmlspecialchars($item['title'] ?? 'Untitled', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $dueDate = htmlspecialchars(substr($item['dueDate'] ?? '', 0, 10), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $html   .= "<li>$title (vervaldatum: $dueDate)</li>";
            }//end foreach

            $html .= '</ul>';
        }

        if (empty($overdueItems) === false) {
            $html .= '<h3>Achterstallige actiepunten</h3><ul>';
            foreach ($overdueItems as $item) {
                $title   = htmlspecialchars($item['title'] ?? 'Untitled', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $dueDate = htmlspecialchars(substr($item['dueDate'] ?? '', 0, 10), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $html   .= "<li>$title (vervaldatum was: $dueDate)</li>";
            }//end foreach

            $html .= '</ul>';
        }

        if (empty($pendingDecisions) === false) {
            $html .= '<h3>Besluiten in behandeling</h3><ul>';
            foreach ($pendingDecisions as $decision) {
                $title     = htmlspecialchars($decision['title'] ?? 'Untitled', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $lifecycle = htmlspecialchars($decision['lifecycle'] ?? 'unknown', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $html     .= "<li>$title (status: $lifecycle)</li>";
            }//end foreach

            $html .= '</ul>';
        }

        $html .= '<p><small>Inloggen in Nextcloud is vereist om de links te openen.</small></p></body></html>';
        return $html;
    }//end buildHtmlBody()

    /**
     * Build plain text email body.
     *
     * @param array $upcomingItems    Upcoming action items
     * @param array $overdueItems     Overdue action items
     * @param array $pendingDecisions Pending decisions
     *
     * @return string Plain text body
     */
    private function buildTextBody(array $upcomingItems, array $overdueItems, array $pendingDecisions): string
    {
        $text = "Decidesk Weekoverzicht\n\n";

        if (empty($upcomingItems) === false) {
            $text .= "Aankomende actiepunten:\n";
            foreach ($upcomingItems as $item) {
                $title   = $item['title'] ?? 'Untitled';
                $dueDate = substr($item['dueDate'] ?? '', 0, 10);
                $text   .= "- $title (vervaldatum: $dueDate)\n";
            }//end foreach

            $text .= "\n";
        }

        if (empty($overdueItems) === false) {
            $text .= "Achterstallige actiepunten:\n";
            foreach ($overdueItems as $item) {
                $title   = $item['title'] ?? 'Untitled';
                $dueDate = substr($item['dueDate'] ?? '', 0, 10);
                $text   .= "- $title (vervaldatum was: $dueDate)\n";
            }//end foreach

            $text .= "\n";
        }

        if (empty($pendingDecisions) === false) {
            $text .= "Besluiten in behandeling:\n";
            foreach ($pendingDecisions as $decision) {
                $title     = $decision['title'] ?? 'Untitled';
                $lifecycle = $decision['lifecycle'] ?? 'unknown';
                $text     .= "- $title (status: $lifecycle)\n";
            }//end foreach

            $text .= "\n";
        }

        $text .= "Inloggen in Nextcloud is vereist om de links te openen.\n";
        return $text;
    }//end buildTextBody()
}//end class
