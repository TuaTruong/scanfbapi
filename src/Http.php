<?php
declare(strict_types=1);

final class HttpResponse
{
    public function __construct(
        public readonly int $status,       // 0 = lỗi mạng / timeout
        public readonly string $body,
        public readonly ?string $error = null,
        public readonly int $durationMs = 0,
    ) {
    }

    public function json(): ?array
    {
        $data = json_decode($this->body, true);
        return is_array($data) ? $data : null;
    }
}

/** Tầng HTTP tách riêng để test thay bằng bản giả. */
interface HttpClient
{
    /** @param list<string> $headers */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse;
}

final class CurlHttpClient implements HttpClient
{
    /** Giữ một handle để tái dùng kết nối TLS (keep-alive) giữa các lần gọi. */
    private ?CurlHandle $ch = null;

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse
    {
        $this->ch ??= curl_init();
        $ch = $this->ch;
        curl_reset($ch);
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'scanpostfbapi/1.0',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $started = microtime(true);
        $raw = curl_exec($ch);
        $duration = (int) round((microtime(true) - $started) * 1000);
        if ($raw === false) {
            $error = curl_error($ch);
            $this->ch = null; // kết nối hỏng thì lần sau mở handle mới
            return new HttpResponse(0, '', $error, $duration);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return new HttpResponse($status, (string) $raw, null, $duration);
    }
}
