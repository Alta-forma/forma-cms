#!/usr/bin/env php
<?php
/**
 * Watch Forma sites for DreamHost-style PHP/FastCGI death.
 *
 * Usage:
 *   php tools/watch-php.php https://alta-forma.com https://forma-cms.me
 *   php tools/watch-php.php --file tools/watch-sites.txt
 *
 * Exit 0 = all PHP ok, 2 = PHP down on at least one host (static may still work).
 *
 * Pair with a LaunchAgent / cron every 5 minutes. Alert on exit 2.
 */
$urls = [];
$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--file' && isset($args[$i + 1])) {
        $file = $args[++$i];
        if (!is_file($file)) {
            fwrite(STDERR, "Missing file: {$file}\n");
            exit(1);
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $urls[] = $line;
        }
        continue;
    }
    $urls[] = $args[$i];
}

if (!$urls) {
    fwrite(STDERR, "Usage: php watch-php.php https://site.example [https://other]\n");
    exit(1);
}

$fail = 0;
foreach ($urls as $raw) {
    $base = rtrim($raw, '/');
    $up = fetch($base . '/up');
    $stamp = fetch($base . '/fallback/php-ok.json');
    $home = fetch($base . '/');

    $phpDead = isPhpDead($up) || isPhpDead($home);
    $stampOk = $stamp['code'] === 200 && str_contains($stamp['body'], '"ok"');

    if ($up['code'] === 200 && str_contains($up['body'], '"ok"')) {
        echo "OK   {$base}  PHP " . json_get($up['body'], 'php') . "\n";
        continue;
    }

    if ($phpDead && $stampOk) {
        echo "DOWN {$base}  PHP/FastCGI dead — last-good stamp still served (host vhost fault)\n";
        $fail++;
        continue;
    }

    if ($phpDead) {
        echo "DOWN {$base}  PHP dead (No input file specified / empty CGI path)\n";
        $fail++;
        continue;
    }

    echo "WARN {$base}  /up HTTP {$up['code']}\n";
    $fail++;
}

exit($fail > 0 ? 2 : 0);

function fetch(string $url): array {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => "User-Agent: Forma-watch-php\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
            $code = (int)$m[1];
        }
    }
    return ['code' => $code, 'body' => (string)$body];
}

function isPhpDead(array $res): bool {
    $b = $res['body'];
    return str_contains($b, 'No input file specified')
        || ($res['code'] === 404 && str_contains($b, 'No input file specified'));
}

function json_get(string $json, string $key): string {
    $d = json_decode($json, true);
    return is_array($d) ? (string)($d[$key] ?? '') : '';
}
