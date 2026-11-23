<?php
declare(strict_types=1);

/** Copy this folder to a private backend directory; no Composer package is needed. */
class NAuthClient
{
    private const MODE = 'openssl-ecdh-p256-aes-256-gcm-v1';
    private const CONTEXT = 'nauth-app-auth-v1';
    private const MAX_BODY = 1048576;
    private const BAD_STATES = ['rejected', 'expired', 'consumed', 'inactive', 'blocked', 'cancelled', 'canceled',
        'removed', 'deleted', 'revoked', 'suspended', 'disabled'];
    protected array $config;
    private ?string $serverKey = null;

    public function __construct(array|string|null $configPath = null)
    {
        // Existing applications can reuse their private deployment settings without writing credentials to disk.
        if (is_array($configPath)) {
            $config = $configPath;
            $configPath = __DIR__ . '/.nauth-config';
        } else {
            $configPath = $configPath ?? __DIR__ . '/.nauth-config';
            $raw = @file_get_contents($configPath);
            if (!is_string($raw)) {
                throw new InvalidArgumentException('Create nauth-lib/.nauth-config from .nauth-config.example in a private backend folder.');
            }
            $config = json_decode($raw, true);
        }
        if (!is_array($config) || array_is_list($config)) {
            throw new InvalidArgumentException('.nauth-config must contain a JSON object.');
        }
        foreach (['api_base', 'api_key', 'api_secret', 'app_url', 'server_url'] as $name) {
            if (!is_string($config[$name] ?? null) || trim($config[$name]) === '') {
                throw new InvalidArgumentException('Set ' . $name . ' in .nauth-config.');
            }
        }
        foreach (['api_base', 'app_url', 'server_url'] as $name) {
            $url = parse_url($config[$name]);
            if (!is_array($url) || strtolower($url['scheme'] ?? '') !== 'https' || empty($url['host'])
                || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || isset($url['query'])) {
                throw new InvalidArgumentException($name . ' must be an HTTPS URL without credentials, query, or fragment.');
            }
        }
        if (array_key_exists('verify_tls', $config) && $config['verify_tls'] !== true) {
            throw new InvalidArgumentException('TLS verification is required. Use ca_file for a private certificate authority.');
        }
        $timeout = $config['timeout_seconds'] ?? 15;
        if (!is_int($timeout) || $timeout < 1 || $timeout > 120) {
            throw new InvalidArgumentException('timeout_seconds must be an integer from 1 to 120.');
        }
        if (!function_exists('openssl_pkey_derive') || !in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            throw new InvalidArgumentException('Enable PHP OpenSSL with P-256 and AES-256-GCM support.');
        }
        $config['timeout_seconds'] = $timeout;
        $config['api_base'] = rtrim($config['api_base'], '/');
        if (isset($config['ca_file'])) {
            if (!is_string($config['ca_file']) || $config['ca_file'] === '') {
                throw new InvalidArgumentException('ca_file must name a readable CA certificate file.');
            }
            $caFile = $config['ca_file'];
            if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|[/\\\\])~', $caFile)) {
                $caFile = dirname($configPath) . DIRECTORY_SEPARATOR . $caFile;
            }
            if (!is_file($caFile) || !is_readable($caFile)) {
                throw new InvalidArgumentException('ca_file must name a readable CA certificate file.');
            }
            $config['ca_file'] = $caFile;
        }
        $this->config = $config;
    }

    public function enroll(string $id, string $username, array $extra = []): array
    {
        return $this->request('/app-auth/enrollment/start', 'enroll', $this->identity($id, $username) + $extra);
    }

    public function status(string $id, string $username, string $enrollmentId = ''): array
    {
        $payload = $this->identity($id, $username);
        if ($enrollmentId !== '') {
            $payload['enrollment_id'] = $enrollmentId;
        }
        return $this->request('/app-auth/enrollment/status', 'status', $payload);
    }

    /** Eligibility check only; finish authentication with verify() or biometricStatus(). */
    public function checkUser(string $id, string $username, string $enrollmentId = ''): array
    {
        return $this->status($id, $username, $enrollmentId);
    }

    public function verify(string $id, string $username, string $code, array $extra = []): array
    {
        return $this->request('/app-auth/verify', 'verify', $this->identity($id, $username) + ['code' => $code] + $extra);
    }

    public function startBiometric(string $id, string $username, array $extra = []): array
    {
        return $this->request('/app-auth/biometric/start', 'biometric_start', $this->identity($id, $username)
            + $extra + ['purpose' => 'login', 'expires_minutes' => 3, 'message' => 'Sign in to this app']);
    }

    public function biometricStatus(string $challengeId): array
    {
        return $this->request('/app-auth/biometric/status', 'biometric_status', ['challenge_id' => $challengeId]);
    }

    private function identity(string $id, string $username): array
    {
        return ['external_user_id' => $id, 'external_username' => $username];
    }

    private function request(string $endpoint, string $operation, array $payload): array
    {
        $status = 0;
        $retryAfter = null;
        try {
            $deadline = microtime(true) + $this->config['timeout_seconds'];
            if ($this->serverKey === null) {
                $metadata = $this->httpJson('GET', '/app-auth/crypto', null, $deadline);
                if ($metadata['status'] < 200 || $metadata['status'] >= 300) {
                    return $this->failure('http_error', $metadata['status'], $metadata['retry_after']);
                }
                if (($metadata['body']['crypto']['enabled'] ?? null) !== true) {
                    return $this->failure('protocol_error');
                }
                $key = $metadata['body']['crypto']['public_keys'][self::MODE] ?? null;
                if (!is_string($key)) {
                    return $this->failure('protocol_error');
                }
                $this->publicKey($key);
                $this->serverKey = $key;
            }
            $keyOptions = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
            // PHP installations on Windows may lack OpenSSL's compiled default config path.
            foreach ([getenv('OPENSSL_CONF'), 'C:/xampp/php/extras/openssl/openssl.cnf', '/etc/ssl/openssl.cnf'] as $candidate) {
                if (is_string($candidate) && is_file($candidate)) {
                    $keyOptions['config'] = $candidate;
                    break;
                }
            }
            $private = @openssl_pkey_new($keyOptions);
            if ($private === false) {
                throw new RuntimeException('protocol_error');
            }
            $details = openssl_pkey_get_details($private);
            $shared = @openssl_pkey_derive($this->publicKey($this->serverKey), $private, 32);
            if (!is_string($shared) || strlen($shared) !== 32 || !is_string($details['key'] ?? null)) {
                throw new RuntimeException('protocol_error');
            }
            $sessionKey = hash('sha256', self::CONTEXT . $shared, true);
            $plaintext = json_encode([
                'api_key' => $this->config['api_key'], 'api_secret' => $this->config['api_secret'],
                'app_url' => $this->config['app_url'], 'server_url' => $this->config['server_url'],
            ] + $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen($plaintext) > self::MAX_BODY / 2) {
                return $this->failure('invalid_input');
            }
            $nonce = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $sessionKey, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
            if (!is_string($ciphertext) || strlen($tag) !== 16) {
                throw new RuntimeException('protocol_error');
            }
            $response = $this->httpJson('POST', $endpoint, ['crypto' => [
                'mode' => self::MODE, 'client_public_key' => base64_encode($details['key']),
                'nonce' => base64_encode($nonce), 'ciphertext' => base64_encode($ciphertext), 'tag' => base64_encode($tag),
            ]], $deadline);
            $status = $response['status'];
            $retryAfter = $response['retry_after'];
            $envelope = $response['body']['crypto'] ?? null;
            if (!is_array($envelope)) {
                return $this->failure($status >= 200 && $status < 300 ? 'protocol_error' : 'http_error', $status, $retryAfter);
            }
            if (($envelope['mode'] ?? null) !== self::MODE || !is_string($envelope['server_public_key'] ?? null)
                || !hash_equals($this->serverKey, $envelope['server_public_key'])) {
                throw new RuntimeException('protocol_error');
            }
            $text = @openssl_decrypt($this->base64($envelope['ciphertext'] ?? null), 'aes-256-gcm', $sessionKey,
                OPENSSL_RAW_DATA, $this->base64($envelope['nonce'] ?? null, 12), $this->base64($envelope['tag'] ?? null, 16));
            if (!is_string($text)) {
                throw new RuntimeException('protocol_error');
            }
            $decoded = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded)) || !str_starts_with(ltrim($text), '{')) {
                throw new RuntimeException('protocol_error');
            }
            if ($status < 200 || $status >= 300 || (array_key_exists('error', $decoded) && $decoded['error'] !== null)) {
                return $this->failure('http_error', $status, $retryAfter, $decoded);
            }
            $eligible = ($decoded['active'] ?? null) === true
                && (!array_key_exists('authentication_allowed', $decoded) || $decoded['authentication_allowed'] === true)
                && (!array_key_exists('biometric_setup_required', $decoded) || $decoded['biometric_setup_required'] === false)
                && (!array_key_exists('mfa_active', $decoded) || $decoded['mfa_active'] === true)
                && (!array_key_exists('access_blocked', $decoded) || $decoded['access_blocked'] === false)
                && $this->stateEligible($decoded, 'status')
                && $this->stateEligible($decoded, 'enrollment_status');
            $allowed = $eligible && (($operation === 'verify' && ($decoded['valid'] ?? null) === true
                && (!array_key_exists('code_allowed', $decoded) || $decoded['code_allowed'] === true)
                && (!array_key_exists('biometric_required', $decoded) || $decoded['biometric_required'] === false))
                || ($operation === 'biometric_status' && ($decoded['approved'] ?? null) === true));
            return ['ok' => true, 'allowed' => $allowed, 'data' => $decoded === [] ? new stdClass() : $decoded, 'error' => null];
        } catch (Throwable $error) {
            // Re-discover rotated keys on the next caller-initiated attempt; never retry an auth operation.
            $this->serverKey = null;
            $code = in_array($error->getMessage(), ['network_error', 'timeout', 'invalid_input'], true)
                ? $error->getMessage() : 'protocol_error';
            return $this->failure($code, $status, $retryAfter);
        }
    }

    private function publicKey(string $encoded)
    {
        $key = @openssl_pkey_get_public($this->base64($encoded));
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC
            || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            throw new RuntimeException('protocol_error');
        }
        return $key;
    }

    private function stateEligible(array $body, string $field): bool
    {
        return !array_key_exists($field, $body) || (is_string($body[$field])
            && !in_array(strtolower(trim($body[$field])), self::BAD_STATES, true));
    }

    private function base64($encoded, ?int $length = null): string
    {
        if (!is_string($encoded) || $encoded === '' || strlen($encoded) > self::MAX_BODY
            || !preg_match('~^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$~D', $encoded)) {
            throw new RuntimeException('protocol_error');
        }
        $decoded = base64_decode($encoded, true);
        if (!is_string($decoded) || $decoded === '' || ($length !== null && strlen($decoded) !== $length)) {
            throw new RuntimeException('protocol_error');
        }
        return $decoded;
    }

    /** Protected only so tests can exercise the real protocol without changing production TLS. */
    protected function httpJson(string $method, string $endpoint, ?array $payload, float $deadline): array
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException('timeout');
        }
        $rawBody = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = ['Accept: application/json'];
        if ($rawBody !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $raw = '';
        $responseHeaders = [];
        $status = 0;
        if (function_exists('curl_init')) {
            $curl = curl_init($this->config['api_base'] . $endpoint);
            $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT_MS => max(1, (int)($remaining * 1000)), CURLOPT_CONNECTTIMEOUT_MS => max(1, (int)($remaining * 1000)),
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$raw): int {
                    if (strlen($raw) + strlen($chunk) > self::MAX_BODY) { return 0; }
                    $raw .= $chunk;
                    return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                    $responseHeaders[] = trim($line);
                    return strlen($line);
                }];
            if ($rawBody !== null) { $options[CURLOPT_POSTFIELDS] = $rawBody; }
            if (isset($this->config['ca_file'])) { $options[CURLOPT_CAINFO] = $this->config['ca_file']; }
            curl_setopt_array($curl, $options);
            $success = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $curlError = curl_errno($curl);
            curl_close($curl);
            if ($success === false) {
                throw new RuntimeException($curlError === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'network_error');
            }
        } else {
            $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false];
            if (isset($this->config['ca_file'])) { $ssl['cafile'] = $this->config['ca_file']; }
            $context = stream_context_create(['ssl' => $ssl, 'http' => [
                'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $rawBody ?? '',
                'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0, 'timeout' => $remaining,
            ]]);
            $stream = @fopen($this->config['api_base'] . $endpoint, 'rb', false, $context);
            if ($stream === false) { throw new RuntimeException('network_error'); }
            try {
                $responseHeaders = $http_response_header ?? [];
                while (!feof($stream)) {
                    $remaining = $deadline - microtime(true);
                    if ($remaining <= 0) { throw new RuntimeException('timeout'); }
                    stream_set_timeout($stream, (int)$remaining, max(1, (int)(($remaining - floor($remaining)) * 1000000)));
                    $chunk = fread($stream, min(8192, self::MAX_BODY + 1 - strlen($raw)));
                    if ($chunk === false) { throw new RuntimeException('network_error'); }
                    $raw .= $chunk;
                    if (strlen($raw) > self::MAX_BODY) { throw new RuntimeException('protocol_error'); }
                    if (stream_get_meta_data($stream)['timed_out']) { throw new RuntimeException('timeout'); }
                }
            } finally { fclose($stream); }
            foreach ($responseHeaders as $header) {
                if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $match)) { $status = (int)$match[1]; }
            }
        }
        $retryAfter = null;
        foreach ($responseHeaders as $header) {
            if (preg_match('~^Retry-After:\s*(\d+)\s*$~i', $header, $match)) { $retryAfter = (int)$match[1]; }
        }
        if ($status >= 300 && $status < 400) { return ['status' => $status, 'retry_after' => $retryAfter, 'body' => []]; }
        try { $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
        catch (JsonException $error) {
            if ($status < 200 || $status >= 300) { $decoded = []; }
            else { throw new RuntimeException('protocol_error'); }
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) { throw new RuntimeException('protocol_error'); }
        return ['status' => $status, 'retry_after' => $retryAfter, 'body' => $decoded];
    }

    private function failure(string $code, int $status = 0, ?int $retryAfter = null, array $data = []): array
    {
        $messages = ['network_error' => 'Cannot reach nAuth securely.', 'timeout' => 'nAuth request timed out.',
            'protocol_error' => 'nAuth returned an invalid or unauthenticated response.', 'invalid_input' => 'nAuth input is too large or invalid.',
            'http_error' => $status === 429 ? 'Too many attempts. Wait before trying again.' : 'nAuth rejected the request.'];
        return ['ok' => false, 'allowed' => false, 'data' => $data === [] ? new stdClass() : $data,
            'error' => ['code' => $code, 'message' => $messages[$code], 'status' => $status, 'retry_after' => $retryAfter]];
    }
}

function nauth(?string $configPath = null): NAuthClient
{
    return new NAuthClient($configPath);
}
