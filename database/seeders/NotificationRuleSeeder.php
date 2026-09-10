<?php

namespace Database\Seeders;

use App\Models\NotificationRule;
use Illuminate\Database\Seeder;

/**
 * Seeds the DBMTS real-time alert rules (scope §14.3). Idempotent.
 */
class NotificationRuleSeeder extends Seeder
{
    /**
     * [rule_key => [name, event_type, severity, trigger_condition, cooldown_minutes]].
     */
    private const RULES = [
        'ccp_failure' => ['CCP Failure', 'event', 'critical', null, 0],
        'packing_weight_breach' => ['Packing Weight Breach', 'event', 'warning', null, 0],
        'lab_hold' => ['Lab Failure Hold', 'event', 'critical', null, 0],
        'missed_metal_detector_check' => ['Missed Metal Detector Check', 'detector', 'warning', '2', 120],
        'batch_open_too_long' => ['Batch Open Too Long', 'detector', 'warning', '24', 720],
        'qa_approval_overdue' => ['QA Approval Overdue', 'detector', 'warning', '4', 240],
        'missing_signoff' => ['Missing Sign-off', 'detector', 'warning', null, 240],
        'traceability_gap' => ['Traceability Gap', 'detector', 'warning', null, 240],
    ];

    public function run(): void
    {
        foreach (self::RULES as $key => [$name, $type, $severity, $condition, $cooldown]) {
            NotificationRule::updateOrCreate(
                ['rule_key' => $key],
                [
                    'rule_name' => $name,
                    'event_type' => $type,
                    'severity' => $severity,
                    'trigger_condition' => $condition,
                    'cooldown_minutes' => $cooldown,
                    'enabled' => true,
                ],
            );
        }
    }
}
