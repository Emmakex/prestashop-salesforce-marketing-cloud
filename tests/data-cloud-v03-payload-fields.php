<?php
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/IngestionPayloadBuilder.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/StagedUtcPayloadBuilder.php';
echo json_encode(\Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\StagedUtcPayloadBuilder::fields(), JSON_UNESCAPED_SLASHES);
