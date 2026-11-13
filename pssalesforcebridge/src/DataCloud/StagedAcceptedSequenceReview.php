<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;

use RuntimeException;

/**
 * G3 draft of a replay-order review gate.
 *
 * accepted_sequence is local journal INSERT order, not source chronology.
 * The gate may identify an obviously older accepted row but cannot prove
 * a later row is the latest business state. No live dispatch authorization.
 */
final class StagedAcceptedSequenceReview
{
    public static function inspect($eventSequence, $latestEntitySequence)
    {
        if (!is_int($eventSequence) || !is_int($latestEntitySequence)
            || $eventSequence < 1 || $latestEntitySequence < 1
            || $eventSequence > $latestEntitySequence) {
            throw new RuntimeException('g3_sequence_evidence_invalid');
        }
        return [
            'status' => $eventSequence < $latestEntitySequence
                ? 'older_local_acceptance_quarantine'
                : 'latest_local_acceptance_unverified',
            'source_order_verified' => false,
            'source_revision_verified' => false,
            'allow_outbound_apply' => false,
            'record_modified_authorized' => false,
            'data_360_ingestion_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }
}
