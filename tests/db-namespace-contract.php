<?php

function namespaceContractFail($message)
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$root = dirname(__DIR__) . '/pssalesforcebridge/src';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$violations = [];

foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $source = file_get_contents($path);

    if (strpos($source, 'namespace ') === false) {
        continue;
    }

    // Ignore explicitly-rooted calls (\Db::). Any unqualified Db:: in a
    // namespaced source file must import PrestaShop's global Db class.
    $withoutRootedDb = str_replace('\\Db::', '', $source);
    if (!preg_match('/(?<!\\\\)\bDb::/', $withoutRootedDb)) {
        continue;
    }

    if (!preg_match('/^use\s+Db\s*;/m', $source)) {
        $violations[] = str_replace(dirname(__DIR__) . '/', '', $path);
    }
}

if (!empty($violations)) {
    namespaceContractFail(
        "Namespaced files using unqualified Db:: must import PrestaShop's global Db class:\n- "
        . implode("\n- ", $violations)
    );
}

fwrite(STDOUT, "Db namespace contract: OK\n");
