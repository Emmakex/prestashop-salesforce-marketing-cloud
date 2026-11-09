<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;

use RuntimeException;

/** Pure proposed Data Cloud records: NO HTTP, credentials, ingestion or marketing sends. */
final class IngestionRecordMapper
{
    private $namespace;

    public function __construct($namespace)
    {
        $namespace = trim((string) $namespace);
        if (!preg_match('/^[A-Za-z0-9_-]{2,32}$/D', $namespace)) {
            throw new RuntimeException('data_cloud_invalid_namespace');
        }
        $this->namespace = $namespace;
    }

    public function customer(array $source)
    {
        $shopId = $this->positiveId($source, 'shop_id');
        $customerId = $this->positiveId($source, 'customer_id');
        $key = 'ps_' . $shopId . '_' . $customerId;
        if (!isset($source['contact_key']) || (string) $source['contact_key'] !== $key) {
            throw new RuntimeException('data_cloud_customer_key_mismatch');
        }
        $email = isset($source['email']) ? trim((string) $source['email']) : '';
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('data_cloud_invalid_email');
        }
        return [
            'source_key' => $this->namespace . ':' . $key,
            'source_namespace' => $this->namespace,
            'shop_id' => $shopId,
            'customer_id' => $customerId,
            'email' => $email,
            'first_name' => isset($source['firstname']) ? (string) $source['firstname'] : '',
            'last_name' => isset($source['lastname']) ? (string) $source['lastname'] : '',
            // Raw source attributes, NOT proof of legal marketing consent.
            'newsletter_flag' => !empty($source['newsletter']),
            'partner_optin_flag' => !empty($source['optin']),
            'active_flag' => !empty($source['active']),
            // PrestaShop local timestamps have no explicit timezone.
            'source_updated_local' => isset($source['date_upd']) ? (string) $source['date_upd'] : '',
        ];
    }

    public function order(array $source)
    {
        $shopId = $this->positiveId($source, 'shop_id');
        $orderId = $this->positiveId($source, 'order_id');
        $key = 'ps_' . $shopId . '_' . $orderId;
        if (!isset($source['order_key']) || (string) $source['order_key'] !== $key) {
            throw new RuntimeException('data_cloud_order_key_mismatch');
        }
        $customerId = isset($source['customer_id']) ? (int) $source['customer_id'] : 0;
        $cartId = isset($source['cart_id']) ? (int) $source['cart_id'] : 0;
        if ($customerId < 0 || $cartId < 0) {
            throw new RuntimeException('data_cloud_invalid_relationship');
        }
        $contactKey = $customerId > 0 ? 'ps_' . $shopId . '_' . $customerId : '';
        if (isset($source['contact_key']) && (string) $source['contact_key'] !== $contactKey) {
            throw new RuntimeException('data_cloud_order_contact_mismatch');
        }
        $currency = isset($source['currency']) ? strtoupper(trim((string) $source['currency'])) : '';
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new RuntimeException('data_cloud_invalid_currency');
        }
        $total = isset($source['total_paid']) ? $source['total_paid'] : null;
        if (!is_numeric($total) || !is_finite((float) $total)) {
            throw new RuntimeException('data_cloud_invalid_total');
        }
        return [
            'source_key' => $this->namespace . ':' . $key,
            'source_namespace' => $this->namespace,
            'shop_id' => $shopId,
            'order_id' => $orderId,
            'customer_source_key' => $contactKey === '' ? '' : $this->namespace . ':' . $contactKey,
            'cart_source_key' => $cartId > 0 ? $this->namespace . ':ps_' . $shopId . '_' . $cartId : '',
            'currency' => $currency,
            'total_paid' => (float) $total,
            'paid_flag' => !empty($source['paid']),
            'state_id' => isset($source['state_id']) ? (int) $source['state_id'] : 0,
            'source_updated_local' => isset($source['date_upd']) ? (string) $source['date_upd'] : '',
        ];
    }

    private function positiveId(array $data, $field)
    {
        if (!isset($data[$field]) || !is_numeric($data[$field])
            || (string) (int) $data[$field] !== (string) $data[$field]
            || (int) $data[$field] <= 0) {
            throw new RuntimeException('data_cloud_invalid_source_id');
        }
        return (int) $data[$field];
    }
}
