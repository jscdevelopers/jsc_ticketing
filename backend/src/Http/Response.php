<?php
declare(strict_types=1);
namespace App\Http;

final class Response
{
    public string $format = 'json';
    public mixed $payload;

    public function __construct(public string $body = '', public int $status = 200, public array $headers = []) {}

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $isError = $status >= 400;
        $payload = [
            'status' => $isError ? 'error' : 'success',
            'code' => $status,
            'message' => $isError ? ($data['error'] ?? 'Request failed.') : 'Request completed successfully.',
            'data' => $isError ? null : ($data['data'] ?? $data),
            'errors' => $isError ? ($data['errors'] ?? null) : null,
            'meta' => (object)($data['meta'] ?? []),
        ];
        if (isset($data['error_code'])) {
            $payload['meta']->error_code = $data['error_code'];
        }
        $response = new self(json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), $status,
            ['Content-Type' => 'application/json; charset=UTF-8'] + $headers);
        $response->payload = $payload;
        return $response;
    }
}
