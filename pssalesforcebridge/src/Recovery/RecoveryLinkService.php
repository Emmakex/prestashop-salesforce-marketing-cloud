<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Recovery;

use RuntimeException;

final class RecoveryLinkService
{
    private $tokens;
    private $link;
    private $ttlHours;
    private $moduleName;

    public function __construct(RecoveryTokenRepository $tokens, $link, $ttlHours, $moduleName = 'pssalesforcebridge')
    {
        $this->tokens = $tokens;
        $this->link = $link;
        $this->ttlHours = max(1, min(720, (int) $ttlHours));
        $this->moduleName = (string) $moduleName;
    }

    public function issueUrl($shopId, $cartId, $customerId, $contactKey, $snapshotHash)
    {
        if (!is_object($this->link) || !method_exists($this->link, 'getModuleLink')) {
            throw new RuntimeException('PrestaShop Link service is unavailable for recovery URL generation.');
        }

        $token = $this->tokens->issue(
            $shopId,
            $cartId,
            $customerId,
            $contactKey,
            $snapshotHash,
            $this->ttlHours
        );

        return $this->link->getModuleLink(
            $this->moduleName,
            'recover',
            ['t' => $token],
            true,
            null,
            (int) $shopId
        );
    }
}
