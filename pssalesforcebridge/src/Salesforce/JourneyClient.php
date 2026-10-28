<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Salesforce;

use RuntimeException;

final class JourneyClient
{
    private $client;
    private $eventDefinitionKey;

    public function __construct(RestClient $client, $eventDefinitionKey)
    {
        $this->client = $client;
        $this->eventDefinitionKey = trim((string) $eventDefinitionKey);

        if ($this->eventDefinitionKey === '') {
            throw new RuntimeException('Journey Event Definition Key is not configured.');
        }

        if (!preg_match('/^[A-Za-z0-9_-]+$/', $this->eventDefinitionKey)) {
            throw new RuntimeException('Journey Event Definition Key contains unsupported characters.');
        }
    }

    public function fire($contactKey, array $data)
    {
        $contactKey = trim((string) $contactKey);
        if ($contactKey === '') {
            throw new RuntimeException('Journey event ContactKey is missing.');
        }

        $response = $this->client->postJson('/interaction/v1/events', [
            'ContactKey' => $contactKey,
            'EventDefinitionKey' => $this->eventDefinitionKey,
            'Data' => $data,
        ]);

        if (!is_array($response) || empty($response['eventInstanceId'])) {
            throw new RuntimeException('Salesforce Journey Builder did not return eventInstanceId.');
        }

        return [
            'event_instance_id' => (string) $response['eventInstanceId'],
            'response' => $response,
        ];
    }
}
