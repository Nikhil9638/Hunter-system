<?php
/**
 * Shared helpers for live API integration tests (CLI only).
 */

declare(strict_types=1);

namespace Hunter\Tests;

/** Preserve historical two-ready-topic assertions on an explicitly attested seed fixture. */
function seedFixtureApi(\Hunter\Support\Config $config,string $default):string
{
    if(getenv('HUNTER_M7_ISOLATED')!=='1')return $default;
    if($config->get('DB_NAME')!=='hunter_system_m7_seed_fixture'||$config->get('DB_USER')!=='hunter_m7_seed')throw new \RuntimeException('Refusing unexpected seed fixture config.');
    $api='http://127.0.0.1:8097/hunter-system/api/v1';
    requireFixtureBackend($api,$config->get('DB_NAME'),$config->get('DB_USER'));
    return $api;
}
function expectedIntegrationAccount(\Hunter\Support\Config $config):bool
{
    return [$config->get('DB_NAME'),$config->get('DB_USER')] === (getenv('HUNTER_M7_ISOLATED')==='1'
        ? ['hunter_system_m7_seed_fixture','hunter_m7_seed'] : ['hunter_system_dev','hunter_app']);
}

/** Fixture-only server attestation before any test account/content mutation. */
function requireFixtureBackend(string $api, string $database, string $account): void
{
    $allowed = [
        'http://127.0.0.1:8095/hunter-system/api/v1' => ['hunter_system_final_legacy_fixture'=>['hunter_final_legacy']],
        'http://127.0.0.1:8096/hunter-system/api/v1' => ['hunter_system_final_legacy_fixture'=>['hunter_final_legacy']],
        'http://127.0.0.1:8097/hunter-system/api/v1' => ['hunter_system_m7_seed_fixture' => ['hunter_m7_seed']],
        'http://127.0.0.1:8098/hunter-system/api/v1' => ['hunter_system_m3_fixture' => ['hunter_fixture','hunter_m7_fixture']],
        'http://127.0.0.1:8099/hunter-system/api/v1' => ['hunter_system_m3_fixture' => ['hunter_fixture','hunter_m7_fixture']],
    ];
    if (!in_array($account, $allowed[$api][$database] ?? [], true)) {
        throw new \RuntimeException('Refusing unexpected fixture base URL/database/account.');
    }
    $curl = curl_init($api . '/__test/identity');
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]);
    $raw = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
    $identity = json_decode((string)$raw, true)['data'] ?? null;
    if ($status !== 200 || $identity !== ['database'=>$database,'account'=>$account]) {
        throw new \RuntimeException('Refusing fixture backend/config identity mismatch.');
    }
}

/** One browser-like client: its own cookie jar and CSRF token. */
final class Client
{
    public string $jar;
    public ?string $csrf = null;

    public function __construct(public string $api, string $dir, string $name)
    {
        $this->jar = $dir . DIRECTORY_SEPARATOR . $name . '.cookies';
        @unlink($this->jar);
    }

    /** @return array{status:int, json:mixed, raw:string} */
    public function req(string $method, string $path, mixed $json = null, array $headers = [], bool $useCsrf = true): array
    {
        $ch = curl_init($this->api . $path);
        curl_setopt_array($ch, $this->options($method, $json, $headers, $useCsrf) + [CURLOPT_COOKIEFILE => $this->jar, CURLOPT_COOKIEJAR => $this->jar]);
        $raw = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $this->result($status, $raw);
    }

    /** Options for an async handle (explicit Cookie header; jars are not safe to share concurrently). */
    public function asyncHandle(string $method, string $path, mixed $json = null)
    {
        $ch = curl_init($this->api . $path);
        curl_setopt_array($ch, $this->options($method, $json, [], true) + [CURLOPT_COOKIE => 'HSSESSID=' . $this->sessionId()]);
        return $ch;
    }

    private function options(string $method, mixed $json, array $headers, bool $useCsrf): array
    {
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($useCsrf && $this->csrf !== null && $method !== 'GET') {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        $o = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60];
        if ($json !== null) {
            $o[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE);
        }
        return $o;
    }

    public function result(int $status, string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['data']['csrf_token'])) {
            $this->csrf = $decoded['data']['csrf_token'];
        }
        return ['status' => $status, 'json' => $decoded, 'raw' => $raw];
    }

    public function sessionId(): ?string
    {
        foreach (is_file($this->jar) ? file($this->jar) : [] as $line) {
            $p = explode("\t", trim($line));
            if (count($p) >= 7 && $p[5] === 'HSSESSID') {
                return $p[6];
            }
        }
        return null;
    }
}

/**
 * Run requests concurrently in a fixed arrival order while $hold() keeps a database
 * lock; $release() is called after all requests were sent and have had time to block.
 *
 * @param list<array{0:Client,1:\CurlHandle}> $requests in arrival order
 * @return list<array{status:int, json:mixed, raw:string}>
 */
function orderedConcurrent(array $requests, callable $release, int $gapMs = 300): array
{
    $mh = curl_multi_init();
    $running = 0;
    foreach ($requests as [$client, $ch]) {
        curl_multi_add_handle($mh, $ch);
        $until = microtime(true) + $gapMs / 1000;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.02);
        } while (microtime(true) < $until);
    }
    $release();
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.05);
    } while ($running > 0);
    $out = [];
    foreach ($requests as [$client, $ch]) {
        $out[] = $client->result((int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string)curl_multi_getcontent($ch));
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

/** Recursively list every key in a decoded JSON value. */
function allKeys(mixed $v): array
{
    $keys = [];
    if (is_array($v)) {
        foreach ($v as $k => $child) {
            if (is_string($k)) {
                $keys[] = $k;
            }
            array_push($keys, ...allKeys($child));
        }
    }
    return $keys;
}
