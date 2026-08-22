#!/usr/bin/env php
<?php
/**
 * Forma remote CLI — talks to Agent API.
 *
 *   export FORMA_X_URL=https://example.com
 *   export FORMA_X_TOKEN=fx_...
 *   php tools/formax.php site
 *   php tools/formax.php posts
 *   php tools/formax.php get-post welcome
 *   php tools/formax.php put-post welcome --file=post.json
 *   php tools/formax.php get-page home
 *   php tools/formax.php put-page home --file=page.json
 *   php tools/formax.php flush-cache
 */
function usage(): void {
    echo <<<TXT
Forma remote CLI

Env: FORMA_X_URL  FORMA_X_TOKEN

Commands:
  site
  pages | posts | media | snippets
  get-page <filename>
  put-page <filename> --file=payload.json
  get-post <filename>
  put-post <filename> --file=payload.json
  flush-cache
  export                 JSON (stdout)
  export-site [file.zip] Full package zip (default: formax-site-DATE.zip)

TXT;
}

function request(string $method, string $path, ?array $json = null): array {
    $base = rtrim(getenv('FORMA_X_URL') ?: '', '/');
    $token = getenv('FORMA_X_TOKEN') ?: '';
    if ($base === '' || $token === '') {
        fwrite(STDERR, "Set FORMA_X_URL and FORMA_X_TOKEN\n");
        exit(1);
    }
    $url = $base . '/api/v1' . $path;
    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 60,
    ]);
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false) {
        fwrite(STDERR, 'curl error: ' . curl_error($ch) . "\n");
        exit(1);
    }
    $data = json_decode($body, true);
    if ($code >= 400) {
        fwrite(STDERR, "HTTP $code: " . ($data['error'] ?? $body) . "\n");
        exit(1);
    }
    return is_array($data) ? $data : ['raw' => $body];
}

function argFile(array $argv): ?array {
    foreach ($argv as $a) {
        if (str_starts_with($a, '--file=')) {
            $path = substr($a, 7);
            $data = json_decode(file_get_contents($path), true);
            if (!is_array($data)) {
                fwrite(STDERR, "Invalid JSON file\n");
                exit(1);
            }
            return $data;
        }
    }
    return null;
}

$cmd = $argv[1] ?? '';
switch ($cmd) {
    case 'site':
        echo json_encode(request('GET', '/site'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        break;
    case 'pages':
        echo json_encode(request('GET', '/pages'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        break;
    case 'posts':
        echo json_encode(request('GET', '/posts'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        break;
    case 'media':
        echo json_encode(request('GET', '/media'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        break;
    case 'snippets':
        echo json_encode(request('GET', '/snippets'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        break;
    case 'get-page':
        $f = $argv[2] ?? '';
        echo json_encode(request('GET', '/pages/' . rawurlencode($f)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        break;
    case 'put-page':
        $f = $argv[2] ?? '';
        $payload = argFile($argv) ?? [];
        echo json_encode(request('PUT', '/pages/' . rawurlencode($f), $payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        break;
    case 'get-post':
        $f = $argv[2] ?? '';
        echo json_encode(request('GET', '/posts/' . rawurlencode($f)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        break;
    case 'put-post':
        $f = $argv[2] ?? '';
        $payload = argFile($argv) ?? [];
        echo json_encode(request('PUT', '/posts/' . rawurlencode($f), $payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        break;
    case 'flush-cache':
        echo json_encode(request('POST', '/cache/flush', []), JSON_PRETTY_PRINT) . "\n";
        break;
    case 'export':
        echo json_encode(request('GET', '/export'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        break;
    case 'export-site':
        $out = $argv[2] ?? ('formax-site-' . date('Ymd-His') . '.zip');
        $base = rtrim(getenv('FORMA_X_URL') ?: '', '/');
        $token = getenv('FORMA_X_TOKEN') ?: '';
        if ($base === '' || $token === '') {
            fwrite(STDERR, "Set FORMA_X_URL and FORMA_X_TOKEN\n");
            exit(1);
        }
        $ch = curl_init($base . '/api/v1/export/site');
        $fh = fopen($out, 'wb');
        if (!$fh) {
            fwrite(STDERR, "Cannot write $out\n");
            exit(1);
        }
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'X-Forma-Token: ' . $token,
                'Accept: application/zip',
            ],
            CURLOPT_TIMEOUT        => 600,
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        fclose($fh);
        if ($ok === false || $code >= 400) {
            @unlink($out);
            fwrite(STDERR, "export-site failed HTTP $code: " . curl_error($ch) . "\n");
            exit(1);
        }
        echo "Wrote $out (" . filesize($out) . " bytes)\n";
        break;
    default:
        usage();
        exit($cmd === '' || $cmd === 'help' ? 0 : 1);
}
