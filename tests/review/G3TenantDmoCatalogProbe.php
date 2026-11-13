<?php
/**
 * ABH G3-05/06: fixed, read-only Data 360 Connect REST metadata inspection.
 * CI-only, never loaded by PrestaShop. No record endpoints, credentials in
 * output, mapping writes, DMO changes, identity resolution or marketing.
 */
final class G3TenantDmoCatalogProbe
{
    const API_VERSION = 'v68.0';
    const MAX_BODY_BYTES = 1048576;

    private $origin;
    private $token;

    public function __construct($coreInstanceUrl, $coreAccessToken)
    {
        if (!is_string($coreInstanceUrl)) {
            throw new RuntimeException('g3_dmo_invalid_org_origin');
        }
        $url = parse_url($coreInstanceUrl);
        if (!is_array($url) || !isset($url['scheme'], $url['host'])
            || $url['scheme'] !== 'https'
            || isset($url['user']) || isset($url['pass']) || isset($url['port'])
            || isset($url['query']) || isset($url['fragment'])
            || (isset($url['path']) && $url['path'] !== '' && $url['path'] !== '/')
            || !preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.salesforce\.com$/Di', $url['host'])) {
            throw new RuntimeException('g3_dmo_invalid_org_origin');
        }
        if (!is_string($coreAccessToken) || $coreAccessToken === ''
            || strlen($coreAccessToken) > 32768) {
            throw new RuntimeException('g3_dmo_missing_or_invalid_token');
        }
        $this->origin = 'https://' . strtolower($url['host']);
        $this->token = $coreAccessToken;
    }

    public function inspect($transport = null)
    {
        if ($transport !== null && !is_callable($transport)) {
            throw new RuntimeException('g3_dmo_invalid_transport');
        }
        $api = '/services/data/' . self::API_VERSION . '/ssot/data-model-objects/';
        $individual = $this->fetch($api . 'ssot__Individual__dlm', $transport);
        $order = $this->fetch($api . 'ssot__SalesOrder__dlm', $transport);

        $objects = [
            'Individual' => self::summarize(
                $individual, 'ssot__Individual__dlm', 'PROFILE',
                ['ssot__Id__c']
            ),
            'SalesOrder' => self::summarize(
                $order, 'ssot__SalesOrder__dlm', 'OTHER',
                ['ssot__Id__c', 'ssot__SoldToCustomerId__c',
                 'ssot__BillToContactId__c']
            ),
        ];

        // Both directions are inspected. The documented endpoint can list a
        // DMO as either source or target. An unreported standard relationship
        // may simply be inactive until its fields are mapped.
        $orderRelations = $this->fetch(
            $api . 'ssot__SalesOrder__dlm/relationships', $transport, true
        );
        $individualRelations = $this->fetch(
            $api . 'ssot__Individual__dlm/relationships', $transport, true
        );
        $relation = self::summarizeRelations($orderRelations, $individualRelations);

        return [
            'stage' => 'g3_data_360_dmo_catalog_readonly',
            'rest_api_version' => self::API_VERSION,
            'status' => 'metadata_review_only',
            'data_space_checked' => 'default',
            'objects' => $objects,
            'relationship' => $relation,
            'tenant_mapping_approved' => false,
            'identity_resolution_authorized' => false,
            'schema_upload_authorized' => false,
            'data_360_ingestion_authorized' => false,
            'marketing_sends_authorized' => false,
            'salesforce_record_reads' => false,
            'salesforce_data_writes' => false,
        ];
    }

    /**
     * Report only bounded counts and a fixed allowlist of field matches.
     * Visibility is NOT approval or a proof of source-mapped relationship.
     */
    private static function summarizeRelations($orderRelations, $individualRelations)
    {
        $result = [
            'metadata_accessible' => $orderRelations !== null,
            'individual_relationship_metadata_accessible' =>
                $individualRelations !== null,
            'sales_order_relationship_count' => null,
            'individual_relationship_count' => null,
            'any_relation_between_objects_seen' => false,
            'sales_order_to_individual_candidate_present' => false,
            'sold_to_customer_relation_seen' => false,
            'bill_to_contact_relation_seen' => false,
            'active_candidate_relation_seen' => false,
            'standard_relation_unmapped_cannot_be_excluded' => true,
        ];
        foreach (['sales_order' => $orderRelations,
            'individual' => $individualRelations] as $which => $listing) {
            if ($listing === null) {
                continue;
            }
            if (!isset($listing['relationships'])
                || !is_array($listing['relationships'])
                || count($listing['relationships']) > 250) {
                throw new RuntimeException('g3_dmo_invalid_relationship_metadata');
            }
            $result[$which . '_relationship_count'] =
                count($listing['relationships']);
            foreach ($listing['relationships'] as $entry) {
                if (!is_array($entry) || !isset(
                    $entry['sourceEntity']['name'], $entry['targetEntity']['name']
                ) || !is_string($entry['sourceEntity']['name'])
                || !is_string($entry['targetEntity']['name'])) {
                    continue;
                }
                $source = $entry['sourceEntity']['name'];
                $target = $entry['targetEntity']['name'];
                $forward = $source === 'ssot__SalesOrder__dlm'
                    && $target === 'ssot__Individual__dlm';
                $reverse = $source === 'ssot__Individual__dlm'
                    && $target === 'ssot__SalesOrder__dlm';
                if (!$forward && !$reverse) {
                    continue;
                }
                $result['any_relation_between_objects_seen'] = true;
                // Reverse relationships expose SalesOrder fields as the
                // targetField rather than the sourceField.
                $field = $forward ? 'sourceField' : 'targetField';
                if (!isset($entry[$field]['name'])
                    || !is_string($entry[$field]['name'])) {
                    continue;
                }
                $name = $entry[$field]['name'];
                if ($name === 'ssot__SoldToCustomerId__c') {
                    $result['sold_to_customer_relation_seen'] = true;
                } elseif ($name === 'ssot__BillToContactId__c') {
                    $result['bill_to_contact_relation_seen'] = true;
                } else {
                    continue;
                }
                if (isset($entry['status']) && $entry['status'] === 'ACTIVE') {
                    $result['active_candidate_relation_seen'] = true;
                }
            }
        }
        $result['sales_order_to_individual_candidate_present'] =
            $result['sold_to_customer_relation_seen']
            || $result['bill_to_contact_relation_seen'];
        return $result;
    }

    private static function summarize(array $entry, $expectedName,
        $expectedCategory, array $candidateFields)
    {
        if (!isset($entry['name'], $entry['category'], $entry['fields'])
            || !is_string($entry['name'])
            || !is_string($entry['category'])
            || !is_array($entry['fields'])
            || count($entry['fields']) > 1000
            || $entry['name'] !== $expectedName) {
            throw new RuntimeException('g3_dmo_invalid_object_metadata');
        }
        $found = array_fill_keys($candidateFields, false);
        $pk = false;
        foreach ($entry['fields'] as $field) {
            if (!is_array($field) || !isset($field['name'])
                || !is_string($field['name'])) {
                continue;
            }
            if (array_key_exists($field['name'], $found)) {
                $found[$field['name']] = true;
            }
            if ($field['name'] === 'ssot__Id__c'
                && isset($field['isPrimaryKey'])
                && $field['isPrimaryKey'] === true) {
                $pk = true;
            }
        }
        // Do not leak arbitrary tenant metadata but do expose which exact
        // documented category was observed, including standard DMOs
        // with UNASSIGNED category. This fixes the previous opaque
        // true/false mismatch, without treating it as an authorization.
        $category = strtoupper(trim($entry['category']));
        if (!in_array($category, ['PROFILE', 'ENGAGEMENT', 'OTHER', 'UNASSIGNED'], true)) {
            $category = 'UNRECOGNIZED';
        }
        return [
            'metadata_readable' => true,
            'observed_category' => $category,
            'category_matches_proposal' => $category === $expectedCategory,
            'data_space_matches_observed_default' =>
                isset($entry['dataSpaceName'])
                && $entry['dataSpaceName'] === 'default',
            'id_field_is_primary_key' => $pk,
            'candidate_fields_present' => $found,
        ];
    }

    private function fetch($path, $transport, $relationshipsOptional = false)
    {
        // All allowed paths are static and cannot contain query/record IDs.
        $allowed = [
            '/services/data/' . self::API_VERSION
                . '/ssot/data-model-objects/ssot__Individual__dlm',
            '/services/data/' . self::API_VERSION
                . '/ssot/data-model-objects/ssot__SalesOrder__dlm',
            '/services/data/' . self::API_VERSION
                . '/ssot/data-model-objects/ssot__SalesOrder__dlm/relationships',
            '/services/data/' . self::API_VERSION
                . '/ssot/data-model-objects/ssot__Individual__dlm/relationships',
        ];
        if (!in_array($path, $allowed, true)) {
            throw new RuntimeException('g3_dmo_path_not_allowlisted');
        }
        $response = $transport === null
            ? $this->httpGet($this->origin . $path)
            : call_user_func($transport, $this->origin . $path, $this->token);
        if (!is_array($response) || !isset($response['status'], $response['body'])
            || !is_int($response['status']) || !is_string($response['body'])) {
            throw new RuntimeException('g3_dmo_invalid_transport_response');
        }
        if ($relationshipsOptional
            && in_array($response['status'], [403, 404], true)) {
            return null;
        }
        if ($response['status'] !== 200) {
            // Never include URLs, response body, tenant ID or bearer token.
            $status = in_array($response['status'],
                [400, 401, 403, 404, 429, 500, 502, 503], true)
                ? $response['status'] : 0;
            throw new RuntimeException('g3_dmo_http_' . $status);
        }
        if (strlen($response['body']) > self::MAX_BODY_BYTES) {
            throw new RuntimeException('g3_dmo_response_too_large');
        }
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('g3_dmo_invalid_json');
        }
        return $decoded;
    }

    private function httpGet($url)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('g3_dmo_curl_unavailable');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('g3_dmo_transport_failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body)) {
            throw new RuntimeException('g3_dmo_transport_failed');
        }
        return ['status' => $status, 'body' => $body];
    }
}
