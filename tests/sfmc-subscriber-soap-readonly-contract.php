<?php

require_once __DIR__ . '/review/SfmcSubscriberStatusParser.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Review\SfmcSubscriberStatusParser;

function subscriberCheck($predicate, $message)
{
    if (!$predicate) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function subscriberXml($status, $key, $overall = 'OK')
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"'
        . ' xmlns:api="http://exacttarget.com/wsdl/partnerAPI">'
        . '<soap:Body><api:RetrieveResponseMsg>'
        . '<api:OverallStatus>' . $overall . '</api:OverallStatus>'
        . '<api:Results><api:SubscriberKey>' . $key . '</api:SubscriberKey>'
        . '<api:Status>' . $status . '</api:Status>'
        . '</api:Results></api:RetrieveResponseMsg></soap:Body></soap:Envelope>';
}

foreach (['Active', 'Unsubscribed', 'Held', 'Bounced', 'Deleted'] as $status) {
    $result = SfmcSubscriberStatusParser::inspect(subscriberXml($status, 'ps_test_1_4'), 'ps_test_1_4');
    subscriberCheck($result['verified'] === true && $result['status'] === $status
        && $result['send_authorized'] === false, 'Status must be read-only: ' . $status);
}

$bad = [
    [subscriberXml('Active', 'other_contact'), 'ps_test_1_4', 'subscriber_key_mismatch_or_missing'],
    [subscriberXml('ACTIVE', 'ps_test_1_4'), 'ps_test_1_4', 'unknown_subscriber_status'],
    [subscriberXml('Active', 'ps_test_1_4', 'Error'), 'ps_test_1_4', 'incomplete_or_failed_retrieve'],
    ['invalid <xml', 'ps_test_1_4', 'malformed_xml'],
    ['', 'ps_test_1_4', 'unverified_response'],
    [subscriberXml('Active', 'ps_test_1_4'), '', 'unverified_response'],
    ['<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><foo>&xxe;</foo>',
        'ps_test_1_4', 'unsupported_xml'],
    ['<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
        . '<soap:Body><soap:Fault><faultstring>Test</faultstring></soap:Fault></soap:Body></soap:Envelope>',
        'ps_test_1_4', 'soap_fault'],
];
foreach ($bad as $case) {
    $result = SfmcSubscriberStatusParser::inspect($case[0], $case[1]);
    subscriberCheck($result['verified'] === false && $result['send_authorized'] === false
        && $result['reason'] === $case[2], 'Malformed or unsafe SOAP was accepted: ' . $case[2]);
}

$ambiguous = str_replace('</soap:Body>',
    '<api:RetrieveResponseMsg xmlns:api="http://exacttarget.com/wsdl/partnerAPI"></api:RetrieveResponseMsg></soap:Body>',
    subscriberXml('Active', 'ps_test_1_4'));
subscriberCheck(SfmcSubscriberStatusParser::inspect($ambiguous, 'ps_test_1_4')['verified'] === false,
    'Ambiguous SOAP results must not pass.');

$source = file_get_contents(__DIR__ . '/review/SfmcSubscriberStatusParser.php');
foreach (['curl_', 'Db::', '->fire(', '->enqueue(', 'file_get_contents('] as $forbidden) {
    subscriberCheck(strpos($source, $forbidden) === false,
        'Review-only SOAP parser must not have external effects.');
}
echo "SFMC Subscriber SOAP read-only status contract passed; never send-authorizing.\n";
