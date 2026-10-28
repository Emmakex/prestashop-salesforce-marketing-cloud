<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Customer;

use Emmakex\PrestaShopSalesforceMarketingCloud\Salesforce\RestClient;
use RuntimeException;

final class CustomerDataExtensionWriter
{
    private $client;
    private $externalKey;

    public function __construct(RestClient $client, $externalKey)
    {
        $this->client = $client;
        $this->externalKey = trim((string) $externalKey);
    }

    public function upsert(array $customer)
    {
        if ($this->externalKey === '') {
            throw new RuntimeException('Customer Data Extension external key is not configured.');
        }

        if (empty($customer['contact_key'])) {
            throw new RuntimeException('Customer payload does not contain contact_key.');
        }

        $row = [
            'keys' => [
                'ContactKey' => (string) $customer['contact_key'],
            ],
            'values' => [
                'CustomerId' => isset($customer['customer_id']) ? (int) $customer['customer_id'] : 0,
                'ShopId' => isset($customer['shop_id']) ? (int) $customer['shop_id'] : 0,
                'Email' => isset($customer['email']) ? (string) $customer['email'] : '',
                'Firstname' => isset($customer['firstname']) ? (string) $customer['firstname'] : '',
                'Lastname' => isset($customer['lastname']) ? (string) $customer['lastname'] : '',
                'Newsletter' => !empty($customer['newsletter']),
                'OptIn' => !empty($customer['optin']),
                'Active' => !empty($customer['active']),
                'LanguageId' => isset($customer['language_id']) ? (int) $customer['language_id'] : 0,
                'DateAdd' => isset($customer['date_add']) && $customer['date_add'] !== '' ? (string) $customer['date_add'] : null,
                'DateUpd' => isset($customer['date_upd']) && $customer['date_upd'] !== '' ? (string) $customer['date_upd'] : null,
            ],
        ];

        return $this->client->postJson(
            '/hub/v1/dataevents/key:' . rawurlencode($this->externalKey) . '/rowset',
            [$row]
        );
    }
}
