<?php

function dbContractFail($message)
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function extractMethodCalls($source, $needle)
{
    $calls = [];
    $offset = 0;
    $length = strlen($source);

    while (($start = strpos($source, $needle, $offset)) !== false) {
        $open = $start + strlen($needle) - 1;
        $depth = 0;
        $quote = null;
        $escaped = false;
        $end = null;

        for ($i = $open; $i < $length; ++$i) {
            $char = $source[$i];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }
            if ($char === '(') {
                ++$depth;
                continue;
            }
            if ($char === ')') {
                --$depth;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        if ($end === null) {
            dbContractFail('Unable to parse ' . $needle . ' call.');
        }

        $calls[] = substr($source, $start, $end - $start + 1);
        $offset = $end + 1;
    }

    return $calls;
}

$root = dirname(__DIR__) . '/pssalesforcebridge';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$violations = [];

foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $source = file_get_contents($path);
    foreach (['->getRow(', '->getValue('] as $needle) {
        foreach (extractMethodCalls($source, $needle) as $call) {
            if (stripos($call, 'limit 1') !== false) {
                $violations[] = str_replace(dirname(__DIR__) . '/', '', $path) . ': ' . preg_replace('/\s+/', ' ', $call);
            }
        }
    }
}

if (!empty($violations)) {
    dbContractFail(
        "PrestaShop Db::getRow()/getValue() append LIMIT 1 automatically. Remove manual limits:\n- "
        . implode("\n- ", $violations)
    );
}

fwrite(STDOUT, "Db single-row contract: OK\n");
