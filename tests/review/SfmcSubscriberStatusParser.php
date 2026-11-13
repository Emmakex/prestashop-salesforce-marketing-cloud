<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Review;

use DOMDocument;
use DOMXPath;

/**
 * Offline parser for a single Marketing Cloud SOAP Subscriber Retrieve result.
 * No calls, auth, persistence, PII export, send authorization or worker wiring.
 * Final Journey eligibility also requires a BU-bound token, publication-list,
 * suppression, campaign-purpose and current cart checks.
 */
final class SfmcSubscriberStatusParser
{
    public static function inspect($xml, $expectedSubscriberKey)
    {
        $deny = ['verified' => false, 'status' => 'Unknown', 'reason' => 'unverified_response',
            'send_authorized' => false];

        if (!is_string($xml) || !is_string($expectedSubscriberKey)
            || trim($expectedSubscriberKey) === '' || strlen($xml) > 131072
            || trim($xml) === '') {
            return $deny;
        }
        // Strictly reject XML DTDs and external entities before parsing.
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            $deny['reason'] = 'unsupported_xml';
            return $deny;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            $deny['reason'] = 'malformed_xml';
            return $deny;
        }

        $xp = new DOMXPath($document);
        if ($xp->query('//*[local-name()="Fault"]')->length > 0) {
            $deny['reason'] = 'soap_fault';
            return $deny;
        }
        $msgs = $xp->query('//*[local-name()="RetrieveResponseMsg"]');
        if ($msgs->length !== 1) {
            $deny['reason'] = 'missing_or_ambiguous_response';
            return $deny;
        }

        $statuses = $xp->query('./*[local-name()="OverallStatus"]', $msgs->item(0));
        $results = $xp->query('./*[local-name()="Results"]', $msgs->item(0));
        if ($statuses->length !== 1 || trim($statuses->item(0)->textContent) !== 'OK'
            || $results->length !== 1) {
            $deny['reason'] = 'incomplete_or_failed_retrieve';
            return $deny;
        }

        $keys = $xp->query('./*[local-name()="SubscriberKey"]', $results->item(0));
        $state = $xp->query('./*[local-name()="Status"]', $results->item(0));
        if ($keys->length !== 1 || $state->length !== 1
            || !hash_equals($expectedSubscriberKey, trim($keys->item(0)->textContent))) {
            $deny['reason'] = 'subscriber_key_mismatch_or_missing';
            return $deny;
        }

        $status = trim($state->item(0)->textContent);
        if (!in_array($status, ['Active', 'Unsubscribed', 'Held', 'Bounced', 'Deleted'], true)) {
            $deny['reason'] = 'unknown_subscriber_status';
            return $deny;
        }

        return [
            'verified' => true,
            'status' => $status,
            'reason' => $status === 'Active' ? 'status_read_only' : 'subscriber_not_active',
            // Even Active does NOT establish permission to send.
            'send_authorized' => false,
        ];
    }
}
