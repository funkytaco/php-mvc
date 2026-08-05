<?php

declare(strict_types=1);

namespace App\Helix;

/**
 * Helix queue → fulfillment stage / lane mapping (spec §10.5, DESIGN-DD §6).
 *
 * This is the `helix.queue_map` profile made concrete. It is pure data plus
 * lookups — no side effects — so OrderProjection stays a pure function.
 */
final class QueueMap
{
    /**
     * The eight tracker stages (FR-TRK-02), in order, with their owning team.
     *
     * @var array<int,array{key:string,label:string,team:string}>
     */
    public const STAGES = [
        ['key' => 'received',   'label' => 'Received',       'team' => 'Intake'],
        ['key' => 'provision',  'label' => 'Provisioning',   'team' => 'Virtualization'],
        ['key' => 'os',         'label' => 'OS & Hardening', 'team' => 'Linux Engineering'],
        ['key' => 'gates',      'label' => 'Security Gates', 'team' => 'Governance'],
        ['key' => 'buildout',   'label' => 'Build-out',      'team' => 'Multiple teams'],
        ['key' => 'compliance', 'label' => 'Evidence',       'team' => 'ISSO / Compliance'],
        ['key' => 'handover',   'label' => 'Handover',       'team' => 'Service Desk'],
        ['key' => 'delivered',  'label' => 'Delivered',      'team' => '—'],
    ];

    /** Helix queue name → stage key. */
    private const QUEUE_TO_STAGE = [
        'Virtualization' => 'provision',
        'Linux Engineering' => 'os',
        'Security Engineering' => 'buildout',
        'Directory Services' => 'buildout',
        'PKI / Certificate' => 'buildout',
        'Network Security' => 'buildout',
        'Service Desk' => 'handover',
    ];

    /**
     * Build-out runs several queues in parallel; these are the lanes that must
     * all join before the evidence gate (FR-TRK-03).
     *
     * LABELS ARE DELIBERATELY SKU-FREE. The Tracker is a customer surface, and
     * Golden Rule 7 says customers never receive SKU data. The reference
     * prototype labelled these lanes with product names ("Security agent ·
     * Tripwire", "App stack · PHP · Apache · MariaDB"), which would disclose
     * the security, identity and app SKUs to a customer. FR-TRK-03 names the
     * lanes generically — app stack, security agent, identity, certificate,
     * host firewall, network firewall — and the spec wins on scope, so these
     * use the generic names. An invariant test asserts no product name creeps
     * back in.
     *
     * @var array<int,array{label:string,team:string,abbr:string}>
     */
    public const LANES = [
        ['label' => 'App stack',              'team' => 'Linux Engineering',    'abbr' => 'LX'],
        ['label' => 'Security agent',         'team' => 'Security Engineering', 'abbr' => 'SE'],
        ['label' => 'Identity enrolment',     'team' => 'Directory Services',   'abbr' => 'DS'],
        ['label' => 'Certificate',            'team' => 'PKI / Certificate',    'abbr' => 'PK'],
        // FR-TASK-06: host and network firewall are ALWAYS distinct lanes with
        // distinct owners. Never collapse these two into one.
        ['label' => 'Host firewall change',   'team' => 'Linux Engineering',    'abbr' => 'LX'],
        ['label' => 'Network firewall change', 'team' => 'Network Security',    'abbr' => 'NS'],
    ];

    public static function stageForQueue(?string $queue): string
    {
        if ($queue === null) {
            return 'received';
        }

        return self::QUEUE_TO_STAGE[$queue] ?? 'received';
    }

    /** Zero-based index of a stage key within STAGES. */
    public static function stageIndex(string $stageKey): int
    {
        foreach (self::STAGES as $i => $stage) {
            if ($stage['key'] === $stageKey) {
                return $i;
            }
        }

        return 0;
    }

    /** The Helix queue that owns a given build-out lane. */
    public static function queueForLane(int $laneIndex): ?string
    {
        return self::LANES[$laneIndex]['team'] ?? null;
    }
}
