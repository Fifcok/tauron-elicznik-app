<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const GREE_GENERIC_KEY = 'a3K8Bx%2r8Y7#xDh';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['config'])) {
        $env = loadGreeEnv();
        jsonResponse([
            'hasConfig' => greeConfigIsComplete($env),
        ]);
    }

    $env = loadGreeEnv();
    if (!greeConfigIsComplete($env)) {
        throw new RuntimeException('Brak konfiguracji GREE_AC_IP w config.local.php.');
    }

    $ip = trim((string)$env['GREE_AC_IP']);
    $port = (int)($env['GREE_AC_PORT'] ?? 7000);
    $deviceLabel = trim((string)($env['GREE_AC_NAME'] ?? '')) ?: 'Klimatyzacja';

    $values = greeFetchStatus($ip, $port);

    $pow = (int)($values['Pow'] ?? 0);
    $mod = (int)($values['Mod'] ?? 0);
    $setTem = (int)($values['SetTem'] ?? 0);
    $wdSpd = (int)($values['WdSpd'] ?? 0);
    $swUpDn = (int)($values['SwUpDn'] ?? 0);
    $temUn = (int)($values['TemUn'] ?? 0);
    $turbo = (int)($values['Tur'] ?? 0);
    $quiet = (int)($values['Quiet'] ?? 0);

    jsonResponse([
        'deviceName' => $deviceLabel,
        'on' => $pow === 1,
        'mode' => greeModeLabel($mod),
        'setTemp' => $setTem,
        'tempUnit' => $temUn === 1 ? 'F' : 'C',
        'fanSpeed' => greeFanLabel($wdSpd),
        'swing' => greeSwingLabel($swUpDn),
        'turbo' => $turbo === 1,
        'quiet' => $quiet === 1,
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function loadPhpConfig(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $values = require $path;
    return is_array($values) ? $values : [];
}

function loadGreeEnv(): array
{
    $candidates = [
        dirname(__DIR__) . '/config.local.php',
        __DIR__ . '/../config.local.php',
    ];

    foreach ($candidates as $path) {
        $values = loadPhpConfig($path);
        if ($values !== []) {
            return $values;
        }
    }

    return [];
}

function greeConfigIsComplete(array $env): bool
{
    return trim((string)($env['GREE_AC_IP'] ?? '')) !== '';
}

function jsonResponse(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function greeModeLabel(int $code): string
{
    return match ($code) {
        0 => 'Auto',
        1 => 'Chłodzenie',
        2 => 'Osuszanie',
        3 => 'Wentylator',
        4 => 'Grzanie',
        default => 'Nieznany',
    };
}

function greeFanLabel(int $code): string
{
    return match ($code) {
        0 => 'Auto',
        1 => 'Niska',
        2 => 'Średnio-niska',
        3 => 'Średnia',
        4 => 'Średnio-wysoka',
        5 => 'Wysoka',
        default => 'Nieznana',
    };
}

function greeSwingLabel(int $code): string
{
    return match ($code) {
        0 => 'Ustawienie domyślne',
        1 => 'Bujanie (pełny zakres)',
        2 => 'Zablokowany - najwyższa pozycja',
        3 => 'Zablokowany - górna-środkowa pozycja',
        4 => 'Zablokowany - środkowa pozycja',
        5 => 'Zablokowany - dolna-środkowa pozycja',
        6 => 'Zablokowany - najniższa pozycja',
        7 => 'Bujanie w dolnym zakresie',
        8 => 'Bujanie w środkowym zakresie',
        9 => 'Bujanie w górnym zakresie',
        default => 'Nieznane',
    };
}

/**
 * Gree/EWPE Smart local protocol (UDP, port 7000). Scan+bind uses a fixed
 * "generic" AES key shared by all Gree devices; bind returns a per-device key
 * used to encrypt the actual status/control payloads. Verified end-to-end
 * against a real unit - see cross-reference implementations like
 * tomikaa22/gree-remote for the same handshake shape.
 */
function greeEncrypt(string $key, string $plaintext): string
{
    $cipher = openssl_encrypt($plaintext, 'aes-128-ecb', $key, OPENSSL_RAW_DATA);
    if ($cipher === false) {
        throw new RuntimeException('Szyfrowanie AES dla Gree nie powiodlo sie.');
    }

    return base64_encode($cipher);
}

function greeDecrypt(string $key, string $base64Cipher): string
{
    $cipherBytes = base64_decode($base64Cipher, true);
    if ($cipherBytes === false) {
        throw new RuntimeException('Nieprawidlowe dane base64 w odpowiedzi Gree.');
    }

    $plain = openssl_decrypt($cipherBytes, 'aes-128-ecb', $key, OPENSSL_RAW_DATA);
    if ($plain === false) {
        throw new RuntimeException('Deszyfrowanie odpowiedzi Gree nie powiodlo sie.');
    }

    return $plain;
}

function greeUdpTransaction(string $ip, int $port, string $payload, int $timeoutSec = 3, int $attempts = 2): string
{
    $lastError = null;

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client("udp://{$ip}:{$port}", $errno, $errstr, $timeoutSec);
        if ($socket === false) {
            $lastError = "Nie udalo sie polaczyc z klimatyzacja Gree ({$ip}:{$port}): {$errstr}";
            continue;
        }

        stream_set_timeout($socket, $timeoutSec);
        fwrite($socket, $payload);
        $response = fread($socket, 8192);
        $meta = stream_get_meta_data($socket);
        fclose($socket);

        if ($response === false || $response === '' || ($meta['timed_out'] ?? false)) {
            // UDP nie gwarantuje dostarczenia - pojedynczy zgubiony pakiet
            // jest normalny dla slabego modulu WiFi w tych klimatyzacjach.
            $lastError = 'Brak odpowiedzi od klimatyzacji Gree (timeout). Sprawdz adres IP i czy urzadzenie jest w sieci.';
            continue;
        }

        return $response;
    }

    throw new RuntimeException($lastError ?? 'Brak odpowiedzi od klimatyzacji Gree.');
}

function greeFetchStatus(string $ip, int $port): array
{
    $scanResponse = greeUdpTransaction($ip, $port, json_encode(['t' => 'scan'], JSON_THROW_ON_ERROR));
    $scanData = json_decode($scanResponse, true, 512, JSON_THROW_ON_ERROR);
    $devInfo = json_decode(
        greeDecrypt(GREE_GENERIC_KEY, (string)($scanData['pack'] ?? '')),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $mac = (string)($devInfo['mac'] ?? '');
    if ($mac === '') {
        throw new RuntimeException('Nie udalo sie odczytac adresu MAC klimatyzacji Gree.');
    }

    $bindInner = json_encode(['mac' => $mac, 't' => 'bind', 'uid' => 0], JSON_THROW_ON_ERROR);
    $bindOuter = json_encode([
        'cid' => 'app',
        'i' => 1,
        'pack' => greeEncrypt(GREE_GENERIC_KEY, $bindInner),
        't' => 'pack',
        'tcid' => $mac,
        'uid' => 0,
    ], JSON_THROW_ON_ERROR);

    $bindResponse = greeUdpTransaction($ip, $port, $bindOuter);
    $bindData = json_decode($bindResponse, true, 512, JSON_THROW_ON_ERROR);
    $bindResult = json_decode(
        greeDecrypt(GREE_GENERIC_KEY, (string)($bindData['pack'] ?? '')),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $deviceKey = (string)($bindResult['key'] ?? '');
    if ($deviceKey === '') {
        throw new RuntimeException('Nie udalo sie sparowac z klimatyzacja Gree (brak klucza urzadzenia).');
    }

    $cols = ['Pow', 'Mod', 'SetTem', 'WdSpd', 'SwUpDn', 'SwingLfRig', 'TemUn', 'Tur', 'Quiet'];
    $statusInner = json_encode(['cols' => $cols, 'mac' => $mac, 't' => 'status'], JSON_THROW_ON_ERROR);
    $statusOuter = json_encode([
        'cid' => 'app',
        'i' => 0,
        'pack' => greeEncrypt($deviceKey, $statusInner),
        't' => 'pack',
        'tcid' => $mac,
        'uid' => 0,
    ], JSON_THROW_ON_ERROR);

    $statusResponse = greeUdpTransaction($ip, $port, $statusOuter);
    $statusData = json_decode($statusResponse, true, 512, JSON_THROW_ON_ERROR);
    $statusResult = json_decode(
        greeDecrypt($deviceKey, (string)($statusData['pack'] ?? '')),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $resultCols = $statusResult['cols'] ?? [];
    $resultDat = $statusResult['dat'] ?? [];

    return array_combine($resultCols, $resultDat);
}
