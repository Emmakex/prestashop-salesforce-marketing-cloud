<?php

if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.1.0');
}

final class Configuration
{
    public static $values = [];

    public static function get($key)
    {
        return array_key_exists($key, self::$values) ? self::$values[$key] : false;
    }

    public static function updateValue($key, $value)
    {
        self::$values[$key] = $value;
        return true;
    }
}

function releaseAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$root = dirname(__DIR__);
$moduleFile = $root . '/pssalesforcebridge/pssalesforcebridge.php';
$composerFile = $root . '/pssalesforcebridge/composer.json';
$platformFile = $root . '/pssalesforcebridge/src/Compatibility/Platform.php';
$upgradeFile = $root . '/pssalesforcebridge/upgrade/upgrade-0.7.0.php';

$module = file_get_contents($moduleFile);
$composer = json_decode(file_get_contents($composerFile), true);
$platform = file_get_contents($platformFile);
$upgrade = file_get_contents($upgradeFile);

releaseAssert(strpos($module, "\$this->version = '0.7.0';") !== false, 'Module version must be 0.7.0.');
releaseAssert(isset($composer['require']['php']) && $composer['require']['php'] === '>=7.1', 'Composer PHP requirement must match the PHP 7.1 compatibility floor.');
releaseAssert(strpos($platform, "const MIN_PRESTASHOP = '1.7.8.0';") !== false, 'Minimum PrestaShop version must remain 1.7.8.0.');
releaseAssert(strpos($platform, "const MAX_PRESTASHOP = '9.99.99';") !== false, 'Maximum PrestaShop version must remain within the declared PrestaShop 9 family.');
releaseAssert(strpos($upgrade, 'CREATE TABLE') === false && strpos($upgrade, 'ALTER TABLE') === false && strpos($upgrade, 'DROP TABLE') === false, '0.7.0 upgrade must not contain schema DDL.');

$defaults = [
    'PS_SFMC_RETENTION_QUEUE_DAYS' => 30,
    'PS_SFMC_RETENTION_LOG_DAYS' => 30,
    'PS_SFMC_RETENTION_JOURNEY_DAYS' => 90,
    'PS_SFMC_RETENTION_CART_DAYS' => 30,
    'PS_SFMC_RETENTION_RECOVERY_DAYS' => 30,
    'PS_SFMC_RETENTION_LAST_RUN_AT' => '',
];

foreach ($defaults as $key => $value) {
    $needle = 'Configuration::updateValue(self::CONFIG_' . substr($key, strlen('PS_SFMC_')) . ', ';
    releaseAssert(strpos($module, $needle) !== false, 'Fresh install is missing default for ' . $key . '.');
}

require_once $upgradeFile;
releaseAssert(function_exists('upgrade_module_0_7_0'), '0.7.0 upgrade function must exist.');

Configuration::$values = [
    'PS_SFMC_RETENTION_QUEUE_DAYS' => 365,
];
releaseAssert(upgrade_module_0_7_0(null) === true, '0.7.0 upgrade should succeed.');
releaseAssert(Configuration::$values['PS_SFMC_RETENTION_QUEUE_DAYS'] === 365, 'Upgrade must preserve an existing retention value.');

foreach ($defaults as $key => $value) {
    if ($key === 'PS_SFMC_RETENTION_QUEUE_DAYS') {
        continue;
    }
    releaseAssert(array_key_exists($key, Configuration::$values), 'Upgrade must materialize missing default ' . $key . '.');
    releaseAssert(Configuration::$values[$key] === $value, 'Upgrade default mismatch for ' . $key . '.');
}

fwrite(STDOUT, "release contract: ok\n");
