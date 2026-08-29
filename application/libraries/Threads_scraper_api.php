<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * The only provider adapter allowed to obtain Threads endorse-post statistics.
 * It deliberately exposes the provider's asynchronous POST/GET contract rather
 * than pretending that a completed job always contains usable statistics.
 */
class Threads_scraper_api
{
    protected $baseUrl;
    protected $apiKey;
    protected $timeout;

    public function __construct(array $config = [])
    {
        $this->baseUrl = rtrim(trim((string) ($config['base_url'] ?? env('SOCIAL_SCRAPER_BASE_URL', ''))), '/');
        $this->apiKey = trim((string) ($config['api_key'] ?? env('SOCIAL_SCRAPER_API_KEY', '')));
        $this->timeout = max(5, min(120, intval($config['timeout'] ?? env('SOCIAL_SCRAPER_TIMEOUT', 20))));
    }

    public function submitPost(string $link, ?string $idempotencyKey = null): array
    {
        $headers = [];
        // This header is sent only after the provider contract has explicitly
        // documented support for it. The default avoids silently changing it.
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        return $this->request('POST', '/api/v1/posts/scrape', ['link' => $link], $headers);
    }

    public function getJob(string $jobId): array
    {
        return $this->request('GET', '/api/v1/jobs/' . rawurlencode($jobId));
    }

    protected function request(string $method, string $path, ?array $payload = null, array $extraHeaders = []): array
    {
        if ($this->baseUrl === '' || $this->apiKey === '') {
            return $this->failure('provider_configuration_error', 'Konfigurasi layanan scraper belum lengkap.', 0);
        }

        $headers = array_merge([
            'Accept: application/json',
            'X-API-Key: ' . $this->apiKey,
        ], $extraHeaders);
        $responseHeaders = [];
        $curl = curl_init($this->baseUrl . $path);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$responseHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ];
        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return $this->failure('provider_contract_invalid', 'Payload scraper tidak valid.', 0);
            }
            $options[CURLOPT_POSTFIELDS] = $json;
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $errno = curl_errno($curl);
        $curlError = curl_error($curl);
        $httpStatus = intval(curl_getinfo($curl, CURLINFO_HTTP_CODE));
        curl_close($curl);

        if ($errno !== 0) {
            $code = $errno === CURLE_OPERATION_TIMEDOUT ? 'provider_timeout'
                : ($errno === CURLE_COULDNT_RESOLVE_HOST ? 'provider_network_error' : 'provider_connection_error');
            return $this->failure($code, 'Layanan scraper tidak dapat dihubungi.', 0, [
                'ambiguous' => strtoupper($method) === 'POST',
                'diagnostic' => self::sanitize($curlError),
            ]);
        }

        $data = json_decode((string) $raw, true);
        if ($httpStatus < 200 || $httpStatus >= 300) {
            $message = is_array($data)
                ? strval($data['message'] ?? $data['detail'] ?? ($data['error']['message'] ?? $data['error'] ?? ''))
                : '';
            $code = $httpStatus === 429 ? 'provider_rate_limited'
                : (in_array($httpStatus, [500, 502, 503, 504], true) ? 'provider_server_error'
                : (in_array($httpStatus, [400, 404], true) ? 'provider_request_rejected' : 'provider_http_error'));
            return $this->failure($code, self::userMessage($code), $httpStatus, [
                'retry_after' => self::retryAfter($responseHeaders['retry-after'] ?? null),
                'diagnostic' => self::sanitize($message),
            ]);
        }
        if (!is_array($data)) {
            return $this->failure('provider_contract_invalid', 'Respons layanan scraper tidak valid.', $httpStatus, [
                'diagnostic' => 'non_json_response',
            ]);
        }

        return [
            'ok' => true,
            'http_status' => $httpStatus,
            'data' => $data,
            'retry_after' => self::retryAfter($responseHeaders['retry-after'] ?? null),
        ];
    }

    protected function failure(string $code, string $message, int $httpStatus, array $extra = []): array
    {
        return array_merge([
            'ok' => false,
            'error_code' => $code,
            'message' => $message,
            'http_status' => $httpStatus,
            'retry_after' => null,
            'diagnostic' => '',
            'ambiguous' => false,
        ], $extra);
    }

    public static function retryAfter($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (ctype_digit((string) $value)) {
            return max(1, min(3600, intval($value)));
        }
        $time = strtotime((string) $value);
        return $time === false ? null : max(1, min(3600, $time - time()));
    }

    public static function sanitize(string $message): string
    {
        $message = preg_replace('/(x-api-key|authorization|token)\s*[:=]\s*[^\s,;]+/i', '$1=<redacted>', $message);
        return trim(substr((string) $message, 0, 240));
    }

    public static function userMessage(string $code): string
    {
        $messages = [
            'invalid_threads_url' => 'URL Threads tidak valid. Pastikan URL mengarah langsung ke sebuah post Threads.',
            'post_not_found' => 'Post Threads tidak dapat ditemukan, telah dihapus, atau tidak dapat diakses.',
            'post_inaccessible' => 'Post Threads tidak dapat ditemukan, telah dihapus, atau tidak dapat diakses.',
            'provider_parse_error' => 'Data post Threads tidak dapat dibaca oleh layanan scraper.',
            'provider_timeout' => 'Layanan scraper sedang mengalami gangguan. Sistem akan mencoba kembali secara otomatis.',
            'provider_rate_limited' => 'Layanan scraper sedang sibuk. Sistem akan mencoba kembali secara otomatis.',
            'provider_server_error' => 'Layanan scraper sedang mengalami gangguan. Sistem akan mencoba kembali secara otomatis.',
            'provider_contract_invalid' => 'Respons layanan scraper belum dapat divalidasi. Sistem akan mencoba kembali secara terbatas.',
            'max_attempts_exceeded' => 'Scraping gagal setelah beberapa percobaan. Silakan coba lagi nanti.',
            'provider_submit_uncertain' => 'Status pengiriman ke layanan scraper tidak dapat dipastikan. Item tidak dikirim ulang otomatis.',
            'provider_configuration_error' => 'Konfigurasi layanan scraper belum lengkap.',
        ];
        return $messages[$code] ?? 'Scraping Threads gagal. Silakan coba lagi nanti.';
    }
}

/** Pure outcome mapping, kept separate from CodeIgniter for regression tests. */
class Threads_scraper_outcome
{
    public static function isValidPostUrl(string $url): bool
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
            return false;
        }
        $host = strtolower(preg_replace('/^www\./', '', strval($parts['host'])));
        if (!in_array($host, ['threads.net', 'threads.com'], true)) {
            return false;
        }
        return preg_match('#/(?:@[^/]+/)?post/[A-Za-z0-9_-]+/?$#', strval($parts['path'])) === 1;
    }

    public static function submitJobId(array $payload): ?string
    {
        $jobId = $payload['job_id'] ?? ($payload['data']['job_id'] ?? ($payload['id'] ?? null));
        $jobId = is_scalar($jobId) ? trim((string) $jobId) : '';
        return $jobId === '' ? null : substr($jobId, 0, 191);
    }

    public static function poll(array $payload): array
    {
        $topError = self::errorText($payload['error'] ?? null);
        $status = strtolower(trim((string) ($payload['status'] ?? '')));
        if ($topError !== '') {
            return self::providerError($topError);
        }
        if (in_array($status, ['queued', 'pending', 'started', 'running'], true)) {
            return ['kind' => 'waiting'];
        }
        if ($status !== 'completed') {
            return ['kind' => 'contract_retry', 'error_code' => 'provider_contract_invalid', 'message' => 'Status job scraper tidak dikenali.'];
        }
        $result = $payload['result'] ?? null;
        if (!is_array($result)) {
            return ['kind' => 'contract_retry', 'error_code' => 'provider_contract_invalid', 'message' => 'Hasil job scraper tidak valid.'];
        }
        $resultError = self::errorText($result['error'] ?? null);
        if ($resultError !== '') {
            return self::providerError($resultError);
        }
        $metrics = [];
        foreach (['likes' => 'like', 'views' => 'view', 'comments' => 'comment', 'reposts' => 'share'] as $provider => $internal) {
            if (!array_key_exists($provider, $result) || !is_numeric($result[$provider]) || floatval($result[$provider]) < 0) {
                return ['kind' => 'contract_retry', 'error_code' => 'provider_contract_invalid', 'message' => 'Hasil job Threads tidak memenuhi kontrak statistik.'];
            }
            $metrics[$internal] = intval($result[$provider]);
        }
        return [
            'kind' => 'completed',
            'response' => [
                'status' => true,
                'stats_validated' => true,
                'stats_source' => 'threads_scraper',
                'data' => array_merge($metrics, ['collect' => 0]),
            ],
        ];
    }

    protected static function providerError(string $message): array
    {
        $value = strtolower($message);
        if (strpos($value, 'could not parse post data') !== false || strpos($value, 'parse') !== false) {
            return ['kind' => 'terminal', 'error_code' => 'provider_parse_error', 'message' => Threads_scraper_api::userMessage('provider_parse_error')];
        }
        if (strpos($value, 'private') !== false || strpos($value, 'inaccessible') !== false || strpos($value, 'not accessible') !== false) {
            return ['kind' => 'terminal', 'error_code' => 'post_inaccessible', 'message' => Threads_scraper_api::userMessage('post_inaccessible')];
        }
        if (strpos($value, 'not found') !== false || strpos($value, 'deleted') !== false) {
            return ['kind' => 'terminal', 'error_code' => 'post_not_found', 'message' => Threads_scraper_api::userMessage('post_not_found')];
        }
        return ['kind' => 'terminal', 'error_code' => 'provider_terminal_error', 'message' => Threads_scraper_api::sanitize($message) ?: 'Post Threads tidak dapat diproses oleh layanan scraper.'];
    }

    protected static function errorText($error): string
    {
        if (is_string($error) || is_numeric($error)) {
            return trim((string) $error);
        }
        if (is_array($error)) {
            return trim((string) ($error['message'] ?? $error['detail'] ?? ''));
        }
        return '';
    }
}
