<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$loginUrl = 'https://logowanie.tauron-dystrybucja.pl/login';
$elicznikBaseUrl = 'https://elicznik.tauron-dystrybucja.pl';
$dataUrl = $elicznikBaseUrl . '/energia/do/dane';
$blockUrl = $elicznikBaseUrl . '/blokada';
$sessionTtlSeconds = 15 * 60;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['config'])) {
        $env = loadAppEnv();
        $latestAvailableDate = latestAvailableDate();
        jsonResponse([
            'hasPresetCredentials' => !empty($env['TAURON_USERNAME']) && !empty($env['TAURON_PASSWORD']),
            'hasPresetSiteId' => !empty($env['TAURON_SITE_ID']),
            'latestAvailableDate' => $latestAvailableDate,
            'storageFactor' => getStorageFactor($env),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Nieprawidlowa metoda HTTP.');
    }

    $input = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    $env = loadAppEnv();
    $credentials = normalizeCredentials($input, $env);
    $requestedDate = normalizeRequestedDate($input['date'] ?? null);
    $sessionFiles = getSessionFiles($credentials);
    ensureSessionDirectory(dirname($sessionFiles['cookie']));
    cleanupExpiredSession($sessionFiles, $sessionTtlSeconds);

    if (!hasReusableSession($sessionFiles, $sessionTtlSeconds)) {
        loginToTauron($credentials, $sessionFiles['cookie'], $loginUrl, $elicznikBaseUrl, $blockUrl);
        touchSession($sessionFiles);
    }

    $isoDate = $requestedDate;
    $tauronDate = isoToTauronDate($isoDate);

    $csvResponse = fetchCsvForRange($tauronDate, $tauronDate, $sessionFiles['cookie'], $dataUrl);
    $yearStartDate = substr($isoDate, 0, 4) . '-01-01';
    $monthStartDate = substr($isoDate, 0, 7) . '-01';
    $monthEndDate = minIsoDate(latestAvailableDate(), endOfMonth($isoDate));
    $yearlyResponse = fetchCsvForRange(isoToTauronDate($yearStartDate), $tauronDate, $sessionFiles['cookie'], $dataUrl);
    $monthlyResponse = fetchCsvForRange(isoToTauronDate($monthStartDate), isoToTauronDate($monthEndDate), $sessionFiles['cookie'], $dataUrl);

    if (sessionNeedsRefresh($csvResponse) || sessionNeedsRefresh($yearlyResponse) || sessionNeedsRefresh($monthlyResponse)) {
        loginToTauron($credentials, $sessionFiles['cookie'], $loginUrl, $elicznikBaseUrl, $blockUrl);
        touchSession($sessionFiles);
        $csvResponse = fetchCsvForRange($tauronDate, $tauronDate, $sessionFiles['cookie'], $dataUrl);
        $yearlyResponse = fetchCsvForRange(isoToTauronDate($yearStartDate), $tauronDate, $sessionFiles['cookie'], $dataUrl);
        $monthlyResponse = fetchCsvForRange(isoToTauronDate($monthStartDate), isoToTauronDate($monthEndDate), $sessionFiles['cookie'], $dataUrl);
    }

    if (($csvResponse['status'] ?? 500) >= 400) {
        throw new RuntimeException('Tauron zwrocil blad HTTP ' . ($csvResponse['status'] ?? 500) . '.');
    }
    if (($yearlyResponse['status'] ?? 500) >= 400) {
        throw new RuntimeException('Tauron zwrocil blad HTTP ' . ($yearlyResponse['status'] ?? 500) . ' dla danych rocznych.');
    }
    if (($monthlyResponse['status'] ?? 500) >= 400) {
        throw new RuntimeException('Tauron zwrocil blad HTTP ' . ($monthlyResponse['status'] ?? 500) . ' dla danych miesiecznych.');
    }

    $csvBody = $csvResponse['body'] ?? '';
    $yearlyCsvBody = $yearlyResponse['body'] ?? '';
    $monthlyCsvBody = $monthlyResponse['body'] ?? '';
    if (stripos($csvBody, '<html') !== false) {
        throw new RuntimeException('Tauron zamiast danych zwrocil strone HTML. Mozliwe, ze potrzebny jest site id.');
    }
    if (stripos($yearlyCsvBody, '<html') !== false) {
        throw new RuntimeException('Tauron zamiast danych zwrocil strone HTML dla magazynu rocznego.');
    }
    if (stripos($monthlyCsvBody, '<html') !== false) {
        throw new RuntimeException('Tauron zamiast danych zwrocil strone HTML dla wykresu miesiecznego.');
    }

    touchSession($sessionFiles);
    $storageFactor = getStorageFactor($env);
    jsonResponse(summarizeRows(
        parseTauronCsv($csvBody),
        $isoDate,
        $storageFactor,
        summarizeStorageRange(parseTauronCsv($yearlyCsvBody), $storageFactor, $yearStartDate, $isoDate),
        summarizeDailyRange(parseTauronCsv($monthlyCsvBody), $monthStartDate, $monthEndDate)
    ));
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function loadEnv(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || stringStartsWith($trimmed, '#')) {
            continue;
        }

        $separator = strpos($trimmed, '=');
        if ($separator === false) {
            continue;
        }

        $key = trim(substr($trimmed, 0, $separator));
        $value = trim(substr($trimmed, $separator + 1));
        $values[$key] = trim($value, " \t\n\r\0\x0B\"'");
    }

    return $values;
}

function loadPhpConfig(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $values = require $path;
    return is_array($values) ? $values : [];
}

function loadAppEnv(): array
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

function normalizeCredentials(array $input, array $env): array
{
    $usernameInput = trim((string)($input['username'] ?? ''));
    $passwordInput = trim((string)($input['password'] ?? ''));
    $siteIdInput = trim((string)($input['siteId'] ?? ''));

    $username = $usernameInput !== '' ? $usernameInput : trim((string)($env['TAURON_USERNAME'] ?? ''));
    $password = $passwordInput !== '' ? $passwordInput : trim((string)($env['TAURON_PASSWORD'] ?? ''));
    $siteId = $siteIdInput !== '' ? $siteIdInput : trim((string)($env['TAURON_SITE_ID'] ?? ''));

    if ($username === '' || $password === '') {
        throw new RuntimeException('Podaj login i haslo do eLicznika Tauron.');
    }

    return [
        'username' => $username,
        'password' => $password,
        'siteId' => $siteId,
    ];
}

function normalizeRequestedDate($value): string
{
    $date = trim((string)($value ?? ''));
    if ($date === '') {
        return latestAvailableDate();
    }

    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date, new DateTimeZone('Europe/Warsaw'));
    $errors = DateTimeImmutable::getLastErrors();

    if (!$parsed || ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
        throw new RuntimeException('Nieprawidlowy format daty. Uzyj YYYY-MM-DD.');
    }

    $requestedIso = $parsed->format('Y-m-d');
    $latestIso = latestAvailableDate();

    if ($requestedIso > $latestIso) {
        throw new RuntimeException('Mozna pobrac dane najpozniej do ' . $latestIso . '.');
    }

    return $requestedIso;
}

function getSessionFiles(array $credentials): array
{
    $sessionKey = hash('sha256', implode('|', [
        $credentials['username'],
        $credentials['siteId'],
    ]));
    $baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tauron_elicznik_sessions';

    return [
        'cookie' => $baseDir . DIRECTORY_SEPARATOR . $sessionKey . '.cookie',
        'meta' => $baseDir . DIRECTORY_SEPARATOR . $sessionKey . '.json',
    ];
}

function ensureSessionDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
}

function cleanupExpiredSession(array $sessionFiles, int $ttlSeconds): void
{
    if (!is_file($sessionFiles['meta'])) {
        return;
    }

    $metadata = readSessionMeta($sessionFiles['meta']);
    $lastUsed = (int)($metadata['last_used'] ?? 0);

    if ($lastUsed > 0 && (time() - $lastUsed) <= $ttlSeconds) {
        return;
    }

    deleteSessionFiles($sessionFiles);
}

function hasReusableSession(array $sessionFiles, int $ttlSeconds): bool
{
    if (!is_file($sessionFiles['cookie']) || !is_file($sessionFiles['meta'])) {
        return false;
    }

    $metadata = readSessionMeta($sessionFiles['meta']);
    $lastUsed = (int)($metadata['last_used'] ?? 0);

    return $lastUsed > 0 && (time() - $lastUsed) <= $ttlSeconds;
}

function readSessionMeta(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function touchSession(array $sessionFiles): void
{
    file_put_contents($sessionFiles['meta'], json_encode([
        'last_used' => time(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function deleteSessionFiles(array $sessionFiles): void
{
    if (is_file($sessionFiles['cookie'])) {
        unlink($sessionFiles['cookie']);
    }

    if (is_file($sessionFiles['meta'])) {
        unlink($sessionFiles['meta']);
    }
}

function loginToTauron(array $credentials, string $cookieFile, string $loginUrl, string $elicznikBaseUrl, string $blockUrl): void
{
    if (!is_file($cookieFile)) {
        touch($cookieFile);
    }

    $getLoginResponse = performRequest($loginUrl, 'GET', [], $cookieFile);
    $csrfToken = '';
    if (preg_match('/name="_csrf" value="([^"]+)"/', $getLoginResponse['body'] ?? '', $matches)) {
        $csrfToken = $matches[1];
    }

    $loginParams = [
        'username' => $credentials['username'],
        'password' => $credentials['password'],
        'service' => $elicznikBaseUrl,
    ];
    if ($csrfToken !== '') {
        $loginParams['_csrf'] = $csrfToken;
    }

    $loginResponse = performRequest($loginUrl, 'POST', $loginParams, $cookieFile);

    if (($loginResponse['final_url'] ?? '') === $blockUrl) {
        throw new RuntimeException('Tauron chwilowo blokuje dostep do konta z tego adresu. Spróbuj zmienić IP (restart routera) i odczekać chwilę.');
    }

    $loginHtml = strtolower($loginResponse['body'] ?? '');
    if (stringContains($loginResponse['final_url'] ?? '', '/login') && (stringContains($loginHtml, 'login lub has') || stringContains($loginHtml, 'niepoprawne dane'))) {
        throw new RuntimeException('Niepoprawny login lub haslo do Taurona.');
    }

    if ($credentials['siteId'] !== '') {
        performRequest($elicznikBaseUrl . '/ustaw_punkt', 'POST', [
            'site[client]' => $credentials['siteId'],
        ], $cookieFile);
    }
}

function fetchCsvForRange(string $fromDate, string $toDate, string $cookieFile, string $dataUrl): array
{
    return performRequest($dataUrl, 'GET', [
        'form[from]' => $fromDate,
        'form[to]' => $toDate,
        'form[type]' => 'godzin',
        'form[energy][consum]' => '1',
        'form[energy][oze]' => '1',
        'form[energy][netto]' => '1',
        'form[energy][netto_oze]' => '1',
        'form[fileType]' => 'CSV',
    ], $cookieFile);
}

function sessionNeedsRefresh(array $response): bool
{
    $body = strtolower((string)($response['body'] ?? ''));
    $finalUrl = strtolower((string)($response['final_url'] ?? ''));

    if (stringContains($finalUrl, '/login')) {
        return true;
    }

    return stringContains($body, '<html') && stringContains($body, 'login');
}

function performRequest(string $url, string $method, array $params, string $cookieFile): array
{
    $ch = curl_init();
    if ($ch === false) {
        throw new RuntimeException('Nie udalo sie zainicjalizowac polaczenia cURL.');
    }

    $finalUrl = $url;
    if ($method === 'GET' && $params !== []) {
        $separator = stringContains($url, '?') ? '&' : '?';
        $finalUrl .= $separator . http_build_query($params);
    }

    $headers = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
        'Accept-Encoding: identity',
        'Accept-Language: pl-PL,pl;q=0.9,en-US;q=0.8,en;q=0.7',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: same-site',
        'Sec-Fetch-User: ?1',
    ];

    if (stringContains($url, 'logowanie.tauron-dystrybucja.pl')) {
        $headers[] = 'Referer: https://logowanie.tauron-dystrybucja.pl/login';
        $headers[] = 'Origin: https://logowanie.tauron-dystrybucja.pl';
    } else {
        $headers[] = 'Referer: https://elicznik.tauron-dystrybucja.pl/';
        $headers[] = 'Origin: https://elicznik.tauron-dystrybucja.pl';
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $finalUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => 'identity',
        CURLOPT_HTTP_CONTENT_DECODING => false,
        CURLOPT_AUTOREFERER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }

    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Blad polaczenia z Tauronem: ' . $error);
    }

    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    return [
        'status' => $status,
        'body' => $body,
        'final_url' => $effectiveUrl,
    ];
}

function parseTauronCsv(string $csvText): array
{
    $lines = preg_split('/\r\n|\n|\r/', $csvText) ?: [];
    $lines = array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));

    if (count($lines) < 2) {
        throw new RuntimeException('Tauron zwrocil pusty albo nieczytelny plik CSV.');
    }

    $headers = str_getcsv($lines[0], ';');
    $headers = array_map('trim', $headers);

    $dataIndex = array_search('Data', $headers, true);
    $typeIndex = array_search('Rodzaj', $headers, true);
    $valueIndex = null;

    foreach ($headers as $index => $header) {
        if (stringContains($header, 'Warto')) {
            $valueIndex = $index;
            break;
        }
    }

    if ($dataIndex === false || $typeIndex === false || $valueIndex === null) {
        throw new RuntimeException('Nie udalo sie rozpoznac formatu CSV z Taurona.');
    }

    $rows = [];
    foreach (array_slice($lines, 1) as $line) {
        $columns = str_getcsv($line, ';');
        $timestamp = trim((string)($columns[$dataIndex] ?? ''));
        $type = normalizeType(trim((string)($columns[$typeIndex] ?? '')));
        $valueRaw = trim((string)($columns[$valueIndex] ?? '0'));
        $value = (float)str_replace(',', '.', $valueRaw);

        if ($timestamp === '' || $type === '') {
            continue;
        }

        $rows[] = [
            'timestamp' => $timestamp,
            'type' => $type,
            'value' => $value,
        ];
    }

    return $rows;
}

function summarizeRows(array $rows, string $isoDate, float $storageFactor, array $yearlyStorage, array $monthlyDaily): array
{
    $hourly = [];
    $hourSequence = [];

    foreach ($rows as $row) {
        if (!stringStartsWith($row['timestamp'], $isoDate)) {
            continue;
        }

        $slot = buildHourSlot($row['timestamp']);
        $hourKey = $slot['key'];

        if (!isset($hourly[$hourKey])) {
            $hourly[$hourKey] = [
                'hour' => $slot['label'],
                'hourStart' => $slot['start'],
                'imported' => 0.0,
                'exported' => 0.0,
                'netImported' => 0.0,
                'netExported' => 0.0,
            ];
            $hourSequence[] = $hourKey;
        }

        switch ($row['type']) {
            case 'pobor':
            case 'pobór':
                $hourly[$hourKey]['imported'] += $row['value'];
                break;
            case 'oddanie':
                $hourly[$hourKey]['exported'] += $row['value'];
                break;
            case 'pobrana po zbilansowaniu':
                $hourly[$hourKey]['netImported'] += $row['value'];
                break;
            case 'oddana po zbilansowaniu':
                $hourly[$hourKey]['netExported'] += $row['value'];
                break;
        }
    }

    $hourly = normalizeHourlyOrder($hourly, $hourSequence);

    $totals = [
        'imported' => 0.0,
        'exported' => 0.0,
        'netImported' => 0.0,
        'netExported' => 0.0,
        'fullyBalanced' => 0.0,
        'prosumerBalance' => 0.0,
        'availableFromStorage' => 0.0,
        'storageDeficit' => 0.0,
        'storageFactor' => $storageFactor,
    ];

    foreach ($hourly as $row) {
        $totals['imported'] += $row['imported'];
        $totals['exported'] += $row['exported'];
        $totals['netImported'] += $row['netImported'];
        $totals['netExported'] += $row['netExported'];
    }

    $totals['fullyBalanced'] = $totals['netExported'] - $totals['netImported'];
    $totals['prosumerBalance'] = ($totals['exported'] * $storageFactor) - $totals['imported'];
    $totals['availableFromStorage'] = max($totals['prosumerBalance'], 0);
    $totals['storageDeficit'] = max(-$totals['prosumerBalance'], 0);

    return [
        'date' => $isoDate,
        'totals' => $totals,
        'yearlyStorage' => $yearlyStorage,
        'hourly' => array_values($hourly),
        'monthlyDaily' => $monthlyDaily,
    ];
}

function summarizeStorageRange(array $rows, float $storageFactor, string $startDate, string $endDate): array
{
    $totals = [
        'imported' => 0.0,
        'exported' => 0.0,
        'prosumerBalance' => 0.0,
        'availableFromStorage' => 0.0,
        'storageDeficit' => 0.0,
        'storageFactor' => $storageFactor,
        'periodStart' => $startDate,
        'periodEnd' => $endDate,
        'year' => substr($endDate, 0, 4),
    ];

    foreach ($rows as $row) {
        $rowDate = substr((string)$row['timestamp'], 0, 10);
        if ($rowDate < $startDate || $rowDate > $endDate) {
            continue;
        }

        if ($row['type'] === 'pobor' || $row['type'] === 'pobór') {
            $totals['imported'] += $row['value'];
        } elseif ($row['type'] === 'oddanie') {
            $totals['exported'] += $row['value'];
        }
    }

    $totals['prosumerBalance'] = ($totals['exported'] * $storageFactor) - $totals['imported'];
    $totals['availableFromStorage'] = max($totals['prosumerBalance'], 0);
    $totals['storageDeficit'] = max(-$totals['prosumerBalance'], 0);

    return $totals;
}

function summarizeDailyRange(array $rows, string $startDate, string $endDate): array
{
    $daily = [];
    $cursor = DateTimeImmutable::createFromFormat('Y-m-d', $startDate, new DateTimeZone('Europe/Warsaw'));
    $end = DateTimeImmutable::createFromFormat('Y-m-d', $endDate, new DateTimeZone('Europe/Warsaw'));

    while ($cursor && $end && $cursor <= $end) {
        $key = $cursor->format('Y-m-d');
        $daily[$key] = [
            'date' => $key,
            'dayLabel' => $cursor->format('d'),
            'imported' => 0.0,
            'exported' => 0.0,
        ];
        $cursor = $cursor->modify('+1 day');
    }

    foreach ($rows as $row) {
        $rowDate = substr((string)$row['timestamp'], 0, 10);
        if ($rowDate < $startDate || $rowDate > $endDate) {
            continue;
        }

        if (!isset($daily[$rowDate])) {
            $daily[$rowDate] = [
                'date' => $rowDate,
                'dayLabel' => substr($rowDate, 8, 2),
                'imported' => 0.0,
                'exported' => 0.0,
            ];
        }

        if ($row['type'] === 'pobor' || $row['type'] === 'pobór') {
            $daily[$rowDate]['imported'] += $row['value'];
        } elseif ($row['type'] === 'oddanie') {
            $daily[$rowDate]['exported'] += $row['value'];
        }
    }

    ksort($daily);
    return array_values($daily);
}

function buildHourSlot(string $timestamp): array
{
    $time = substr($timestamp, 11, 8);
    $slotStart = DateTimeImmutable::createFromFormat('H:i:s', $time, new DateTimeZone('Europe/Warsaw'));

    if (!$slotStart) {
        $fallback = substr($timestamp, 11, 5);
        return [
            'key' => $fallback,
            'start' => $fallback,
            'label' => $fallback,
        ];
    }

    $slotEnd = $slotStart->modify('+1 hour');

    return [
        'key' => $slotStart->format('H:i'),
        'start' => $slotStart->format('H:i'),
        'label' => $slotStart->format('H:i') . ' - ' . $slotEnd->format('H:i'),
    ];
}

function normalizeHourlyOrder(array $hourly, array $hourSequence): array
{
    if ($hourly === []) {
        return [];
    }

    if (count($hourly) === 24 && $hourSequence !== [] && $hourSequence[0] !== '00:00') {
        $normalized = [];
        foreach (array_values($hourSequence) as $index => $rawKey) {
            $startHour = str_pad((string)$index, 2, '0', STR_PAD_LEFT) . ':00';
            $endHour = str_pad((string)(($index + 1) % 24), 2, '0', STR_PAD_LEFT) . ':00';
            $row = $hourly[$rawKey];
            $row['hourStart'] = $startHour;
            $row['hour'] = $startHour . ' - ' . $endHour;
            $normalized[$startHour] = $row;
        }

        return $normalized;
    }

    ksort($hourly);
    return $hourly;
}

function normalizeType(string $value): string
{
    $value = strtolower($value);
    return strtr($value, [
        'ą' => 'a',
        'ć' => 'c',
        'ę' => 'e',
        'ł' => 'l',
        'ń' => 'n',
        'ó' => 'o',
        'ś' => 's',
        'ż' => 'z',
        'ź' => 'z',
    ]);
}

function stringContains(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    return strpos($haystack, $needle) !== false;
}

function stringStartsWith(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    return strncmp($haystack, $needle, strlen($needle)) === 0;
}

function warsawToday(): string
{
    $date = new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'));
    return $date->format('Y-m-d');
}

function latestAvailableDate(): string
{
    $date = new DateTimeImmutable('yesterday', new DateTimeZone('Europe/Warsaw'));
    return $date->format('Y-m-d');
}

function endOfMonth(string $isoDate): string
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $isoDate, new DateTimeZone('Europe/Warsaw'));
    if (!$date) {
        return $isoDate;
    }

    return $date->modify('last day of this month')->format('Y-m-d');
}

function minIsoDate(string $first, string $second): string
{
    return $first <= $second ? $first : $second;
}

function getStorageFactor(array $env): float
{
    $raw = trim((string)($env['TAURON_STORAGE_FACTOR'] ?? '0.8'));
    $value = (float)str_replace(',', '.', $raw);

    if ($value <= 0 || $value > 1) {
        return 0.8;
    }

    return $value;
}

function isoToTauronDate(string $isoDate): string
{
    [$year, $month, $day] = explode('-', $isoDate);
    return $day . '.' . $month . '.' . $year;
}

function jsonResponse(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
