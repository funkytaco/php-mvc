<?php

declare(strict_types=1);

namespace App\Services;

use App\Helix\HelixClientInterface;
use App\Helix\MockHelixClient;
use App\Persistence\AuditLog;
use App\Persistence\CatalogRepository;
use App\Persistence\OrderRepository;

/**
 * Order intake — the ONE place in this application that writes to Helix.
 *
 * ============================================================================
 * GOLDEN RULE 2 (AGENTS.md, DESIGN-DD §2.3, §13.3)
 *
 *   `$this->helix->createTicket(...)` in submit() below is the only call to
 *   createTicket() anywhere in this app. An architecture test asserts no other
 *   file references it. If you need Helix to change, you are almost certainly
 *   doing something that belongs in Helix itself — stop and raise it rather
 *   than adding a second write here or a mutator to the port.
 * ============================================================================
 *
 * Everything else in the app reads via getTicket()/listTickets() and projects.
 */
final class OrderIntakeService
{
    public function __construct(
        private HelixClientInterface $helix,
        private OrderRepository $orders,
        private CatalogRepository $catalog,
        private AuditLog $audit,
    ) {
    }

    /**
     * Resolve intake without writing anything — powers the live
     * "what intake resolves" preview (FR-ORD-04).
     *
     * Pure with respect to Helix: no ticket is created here.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function resolve(array $input): array
    {
        $sensitivity = (string) ($input['sensitivity'] ?? 'Moderate');
        $environment = (string) ($input['environment'] ?? 'Production');
        $catalogItem = (string) ($input['catalog_item'] ?? '');

        $profile = $this->catalog->profileForSensitivity($sensitivity);
        $profileLabel = $profile
            ? $profile['name'] . ' ' . $profile['version']
            : 'unresolved';

        $frameworks = $profile
            ? (json_decode((string) $profile['framework_refs'], true) ?: [])
            : [];

        $template = $catalogItem !== '' ? $this->catalog->certifiedTemplateFor($catalogItem) : null;

        // FR-ORD-08: policy-based approvals. Production plus non-low
        // sensitivity pulls in security and the ISSO.
        $approvals = ['app-owner'];
        if (strtolower($environment) === 'production') {
            $approvals[] = 'security';
            if (strtolower($sensitivity) !== 'low') {
                $approvals[] = 'ISSO';
            }
        }

        return [
            // FR-ORD-06: the gap path — no certified template for this profile.
            'template_matched' => $template !== null,
            'template' => $template ? $template['name'] . ' ' . $template['version'] : 'No certified template — Template Request required',
            'profile' => $profileLabel,
            'frameworks' => $frameworks,
            'frameworks_label' => $frameworks === [] ? 'baseline hardening' : implode(' · ', $frameworks),
            'approvals' => $approvals,
            'approvals_label' => implode(' · ', $approvals),
        ];
    }

    /**
     * Submit an order (FR-ORD-09, FR-ORD-10; sequence in DESIGN-DD §8.1).
     *
     * Order of operations matters: the order reference is allocated FIRST,
     * because the TicketBlob must carry `orderRef` (DESIGN-DD §5.1). Creating
     * the ticket first and back-filling would leave a Helix ticket with a null
     * order reference — and the mock's NOT NULL constraint rejects it outright.
     *
     * @param array<string,mixed> $input
     * @return array{order_ref:string,helix_ticket_ref:string,reused:bool}
     */
    public function submit(array $input, ?string $idempotencyKey = null): array
    {
        // Idempotency (DESIGN-DD §8.1): a retry returns the existing order
        // instead of creating a second ticket.
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = $this->orders->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return [
                    'order_ref' => (string) $existing['order_ref'],
                    'helix_ticket_ref' => (string) $existing['helix_ticket_ref'],
                    'reused' => true,
                ];
            }
        }

        $resolved = $this->resolve($input);
        $orderRef = $this->orders->nextOrderRef();

        $appName = trim((string) ($input['app_name'] ?? ''));
        $environment = (string) ($input['environment'] ?? 'Production');
        $sensitivity = (string) ($input['sensitivity'] ?? 'Moderate');
        $catalogItem = (string) ($input['catalog_item'] ?? '');

        // FR-ORD-05: the requester's stated needs are preserved VERBATIM, for
        // later drift re-validation against original intent. Do not normalise,
        // summarise, or template this text.
        $requirementsRecord = (string) ($input['context'] ?? '');

        $blob = [
            'source' => 'host-build-gateway',
            'orderRef' => $orderRef,
            'summary' => $appName . ' — ' . $catalogItem,
            'catalogItem' => $catalogItem,
            'app' => $appName,
            'environment' => $environment,
            'sensitivity' => $sensitivity,
            'expectedUsers' => (int) ($input['expected_users'] ?? 1),
            'needBy' => $input['need_by'] ?? null,
            'resolvedProfile' => $resolved['profile'],
            'requirementsRecord' => $requirementsRecord,
            'queues' => MockHelixClient::DEFAULT_QUEUES,
        ];

        // ▼▼▼ THE ONE HELIX WRITE ▼▼▼
        $ticketRef = $this->helix->createTicket($blob);
        // ▲▲▲ THE ONE HELIX WRITE ▲▲▲

        $this->orders->insert([
            'order_ref' => $orderRef,
            'app_name' => $appName,
            'catalog_item' => $catalogItem,
            'environment' => $environment,
            'sensitivity' => $sensitivity,
            'expected_users' => (int) ($input['expected_users'] ?? 1),
            'need_by' => $input['need_by'] ?? null,
            'requirements_record' => $requirementsRecord,
            'resolved_profile' => $resolved['profile'],
            'frameworks' => $resolved['frameworks'],
            'approvals' => $resolved['approvals'],
            'helix_ticket_ref' => $ticketRef,
            'state' => 'submitted',
            'idempotency_key' => $idempotencyKey,
        ]);

        $this->audit->record('order.submitted', $orderRef, 'Helix ticket ' . $ticketRef . ' created for ' . $appName);

        return ['order_ref' => $orderRef, 'helix_ticket_ref' => $ticketRef, 'reused' => false];
    }

    /**
     * Intake validation (FR-ORD-07).
     *
     * @param array<string,mixed> $input
     * @return array<int,string> Human-readable errors; empty means valid.
     */
    public function validateIntake(array $input): array
    {
        $errors = [];

        if (trim((string) ($input['app_name'] ?? '')) === '') {
            $errors[] = 'Application or project name is required.';
        }
        if (trim((string) ($input['catalog_item'] ?? '')) === '') {
            $errors[] = 'Choose what you need from the solution catalog.';
        }
        if (trim((string) ($input['environment'] ?? '')) === '') {
            $errors[] = 'Environment is required.';
        }
        if (trim((string) ($input['sensitivity'] ?? '')) === '') {
            $errors[] = 'Data sensitivity is required.';
        }

        $users = (int) ($input['expected_users'] ?? 0);
        if ($users < 1 || $users > 100000) {
            $errors[] = 'Expected users must be between 1 and 100,000.';
        }

        $needBy = trim((string) ($input['need_by'] ?? ''));
        if ($needBy !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $needBy)) {
            $errors[] = 'Need-by date must look like 2026-08-15.';
        }

        return $errors;
    }
}
