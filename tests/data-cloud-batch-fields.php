<?php
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/IngestionPayloadBuilder.php';
echo json_encode(\Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\IngestionPayloadBuilder::fields());
