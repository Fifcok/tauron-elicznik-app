<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['config'])) {
        $env = loadBoilerEnv();
        jsonResponse([
            'hasConfig' => boilerConfigIsComplete($env),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Nieprawidlowa metoda HTTP.');
    }

    $env = loadBoilerEnv();
    if (!boilerConfigIsComplete($env)) {
        throw new RuntimeException('Brak konfiguracji TAPO_BOILER_IP / TAPO_EMAIL / TAPO_PASSWORD w config.local.php.');
    }

    $ip = trim((string)$env['TAPO_BOILER_IP']);
    $email = trim((string)$env['TAPO_EMAIL']);
    $password = trim((string)$env['TAPO_PASSWORD']);
    $deviceLabel = trim((string)($env['TAPO_BOILER_NAME'] ?? '')) ?: 'Bojler';

    $input = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    $timezone = new DateTimeZone('Europe/Warsaw');
    $now = new DateTimeImmutable('now', $timezone);

    $monthStart = resolveRequestedMonth((string)($input['month'] ?? ''), $now, $timezone);
    $yearStart = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $now->format('Y') . '-01-01 00:00:00', $timezone);

    $baseUrl = 'http://' . $ip;
    $cookieFile = tempnam(sys_get_temp_dir(), 'tapo_session_');
    if ($cookieFile === false) {
        throw new RuntimeException('Nie udalo sie utworzyc pliku sesji dla Tapo.');
    }

    try {
        $session = tapoHandshake($baseUrl, $email, $password, $cookieFile);

        $deviceInfo = tapoCall($session, $baseUrl, $cookieFile, 'get_device_info');
        $deviceOn = (bool)($deviceInfo['device_on'] ?? false);
        $nicknameRaw = isset($deviceInfo['nickname']) ? base64_decode((string)$deviceInfo['nickname'], true) : false;
        $deviceName = $nicknameRaw !== false && $nicknameRaw !== '' ? $nicknameRaw : $deviceLabel;

        $yearData = tapoCall($session, $baseUrl, $cookieFile, 'get_energy_data', [
            'start_timestamp' => $yearStart->getTimestamp(),
            'end_timestamp' => $yearStart->getTimestamp(),
            'interval' => 43200,
        ]);
        // Same over-long-response quirk as the daily data: cap to 12 months
        // (Jan-Dec of the requested year) so no other year leaks into the sum.
        $yearTotalWh = array_sum(array_map('floatval', array_slice($yearData['data'] ?? [], 0, 12)));

        $monthData = tapoCall($session, $baseUrl, $cookieFile, 'get_energy_data', [
            'start_timestamp' => $monthStart->getTimestamp(),
            'end_timestamp' => $monthStart->getTimestamp(),
            'interval' => 1440,
        ]);
        $monthlyDaily = buildBoilerDailyRows($monthData['data'] ?? [], $monthStart);
    } finally {
        if (is_file($cookieFile)) {
            unlink($cookieFile);
        }
    }

    jsonResponse([
        'deviceName' => $deviceName,
        'deviceOn' => $deviceOn,
        'year' => (int)$now->format('Y'),
        'yearTotalKwh' => round($yearTotalWh / 1000, 3),
        'month' => $monthStart->format('Y-m'),
        'monthlyDaily' => $monthlyDaily,
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

function loadBoilerEnv(): array
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

function boilerConfigIsComplete(array $env): bool
{
    return trim((string)($env['TAPO_BOILER_IP'] ?? '')) !== ''
        && trim((string)($env['TAPO_EMAIL'] ?? '')) !== ''
        && trim((string)($env['TAPO_PASSWORD'] ?? '')) !== '';
}

function resolveRequestedMonth(string $requestedMonth, DateTimeImmutable $now, DateTimeZone $timezone): DateTimeImmutable
{
    $requestedMonth = trim($requestedMonth);

    if ($requestedMonth === '') {
        $monthDate = $now;
    } else {
        $monthDate = DateTimeImmutable::createFromFormat('Y-m-d', $requestedMonth . '-01', $timezone);
        if (!$monthDate) {
            throw new RuntimeException('Nieprawidlowy format miesiaca. Uzyj YYYY-MM.');
        }
    }

    if ($monthDate > $now) {
        $monthDate = $now;
    }

    return DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $monthDate->format('Y') . '-' . $monthDate->format('m') . '-01 00:00:00',
        $timezone
    );
}

function buildBoilerDailyRows(array $data, DateTimeImmutable $monthStart): array
{
    // Tapo can return more days than the requested month (e.g. a rolling
    // multi-month buffer) while still anchoring index 0 to the 1st of the
    // requested month, so trim any trailing days that spill into the next one.
    $daysInMonth = (int)$monthStart->format('t');
    $data = array_slice($data, 0, $daysInMonth);

    $rows = [];
    $cursor = $monthStart;

    foreach ($data as $value) {
        $rows[] = [
            'date' => $cursor->format('Y-m-d'),
            'dayLabel' => $cursor->format('d'),
            'energy' => round(((float)$value) / 1000, 3),
        ];
        $cursor = $cursor->modify('+1 day');
    }

    return $rows;
}

function jsonResponse(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * KLAP protocol (used by newer Tapo firmware). Algorithm cross-verified against
 * python-kasa's klaptransport and almottier/TapoP100's auth_protocol.py.
 */
function tapoAuthHash(string $username, string $password): string
{
    return hash('sha256', hash('sha1', $username, true) . hash('sha1', $password, true), true);
}

function tapoPackSeq(int $seq): string
{
    return pack('N', $seq & 0xFFFFFFFF);
}

function tapoUnpackSignedSeq(string $bytes): int
{
    $unsigned = unpack('N', $bytes)[1];
    if ($unsigned > 0x7FFFFFFF) {
        $unsigned -= 0x100000000;
    }

    return $unsigned;
}

function tapoRequestRaw(string $url, string $body, string $cookieFile, array $query = []): array
{
    $finalUrl = $url;
    if ($query !== []) {
        $finalUrl .= '?' . http_build_query($query);
    }

    $ch = curl_init();
    if ($ch === false) {
        throw new RuntimeException('Nie udalo sie zainicjalizowac polaczenia cURL z Tapo.');
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $finalUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Content-Type:'],
    ]);

    $responseBody = curl_exec($ch);
    if ($responseBody === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Blad polaczenia z gniazdkiem Tapo (' . $url . '): ' . $error);
    }

    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => (string)$responseBody];
}

function tapoHandshake(string $baseUrl, string $email, string $password, string $cookieFile): array
{
    $localSeed = random_bytes(16);
    $response = tapoRequestRaw($baseUrl . '/app/handshake1', $localSeed, $cookieFile);
    if (($response['status'] ?? 0) !== 200) {
        throw new RuntimeException('Tapo handshake1 nie powiodl sie (HTTP ' . ($response['status'] ?? 0) . '). Sprawdz adres IP gniazdka.');
    }

    $body = $response['body'] ?? '';
    if (strlen($body) < 48) {
        throw new RuntimeException('Tapo handshake1 zwrocil nieprawidlowa odpowiedz.');
    }

    $remoteSeed = substr($body, 0, 16);
    $serverHash = substr($body, 16);

    $candidates = [
        [$email, $password],
        ['', ''],
        ['kasa@tp-link.net', 'kasaSetup'],
    ];

    $authHash = null;
    foreach ($candidates as [$user, $pass]) {
        $candidateHash = tapoAuthHash($user, $pass);
        if (hash_equals(hash('sha256', $localSeed . $remoteSeed . $candidateHash, true), $serverHash)) {
            $authHash = $candidateHash;
            break;
        }
    }

    if ($authHash === null) {
        throw new RuntimeException('Nie udalo sie uwierzytelnic w Tapo - sprawdz TAPO_EMAIL i TAPO_PASSWORD w config.local.php.');
    }

    $handshake2Body = hash('sha256', $remoteSeed . $localSeed . $authHash, true);
    $response2 = tapoRequestRaw($baseUrl . '/app/handshake2', $handshake2Body, $cookieFile);
    if (($response2['status'] ?? 0) !== 200) {
        throw new RuntimeException('Tapo handshake2 nie powiodl sie (HTTP ' . ($response2['status'] ?? 0) . ').');
    }

    $key = substr(hash('sha256', 'lsk' . $localSeed . $remoteSeed . $authHash, true), 0, 16);
    $ivSeqHash = hash('sha256', 'iv' . $localSeed . $remoteSeed . $authHash, true);
    $iv = substr($ivSeqHash, 0, 12);
    $seq = tapoUnpackSignedSeq(substr($ivSeqHash, -4));
    $sig = substr(hash('sha256', 'ldk' . $localSeed . $remoteSeed . $authHash, true), 0, 28);

    return [
        'key' => $key,
        'iv' => $iv,
        'sig' => $sig,
        'seq' => $seq,
    ];
}

function tapoEncrypt(string $key, string $iv, int $seq, string $plaintext): string
{
    $cipher = openssl_encrypt($plaintext, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv . tapoPackSeq($seq));
    if ($cipher === false) {
        throw new RuntimeException('Szyfrowanie zadania do Tapo nie powiodlo sie.');
    }

    return $cipher;
}

function tapoDecrypt(string $key, string $iv, int $seq, string $ciphertext): string
{
    $plaintext = openssl_decrypt($ciphertext, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv . tapoPackSeq($seq));
    if ($plaintext === false) {
        throw new RuntimeException('Deszyfrowanie odpowiedzi z Tapo nie powiodlo sie.');
    }

    return $plaintext;
}

function tapoCall(array &$session, string $baseUrl, string $cookieFile, string $method, array $params = []): array
{
    $session['seq']++;
    $seq = $session['seq'];

    $payload = ['method' => $method];
    if ($params !== []) {
        $payload['params'] = $params;
    }

    $plaintext = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $cipherText = tapoEncrypt($session['key'], $session['iv'], $seq, $plaintext);
    $signature = hash('sha256', $session['sig'] . tapoPackSeq($seq) . $cipherText, true);
    $requestBody = $signature . $cipherText;

    $response = tapoRequestRaw($baseUrl . '/app/request', $requestBody, $cookieFile, ['seq' => $seq]);
    if (($response['status'] ?? 0) !== 200) {
        throw new RuntimeException('Zadanie "' . $method . '" do Tapo nie powiodlo sie (HTTP ' . ($response['status'] ?? 0) . ').');
    }

    $responseBody = $response['body'] ?? '';
    if (strlen($responseBody) <= 32) {
        throw new RuntimeException('Tapo zwrocilo pusta odpowiedz dla "' . $method . '".');
    }

    $decrypted = tapoDecrypt($session['key'], $session['iv'], $seq, substr($responseBody, 32));
    $data = json_decode($decrypted, true);

    if (!is_array($data)) {
        throw new RuntimeException('Nie udalo sie zdekodowac odpowiedzi Tapo dla "' . $method . '".');
    }

    if (($data['error_code'] ?? -1) !== 0) {
        throw new RuntimeException('Tapo zwrocilo blad ' . ($data['error_code'] ?? '?') . ' dla "' . $method . '".');
    }

    return $data['result'] ?? [];
}
