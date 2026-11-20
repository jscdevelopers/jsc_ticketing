<?php
namespace App\Http;

/** JSON and multipart request representation used by the creditors routes. */
final class Request
{
    public string $method;
    public string $path;
    /** @var array<string, mixed> */
    public array $query;
    /** @var array<string, mixed> */
    public array $headers;
    /** @var array<string, mixed> */
    public array $body;
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $files;
    /** @var array<string, string> */
    public array $params = [];
    /** @var array<string, mixed> */
    public array $attributes = [];
    /** @var array<string, mixed>|null */
    public ?array $user = null;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $body
     * @param array<string, array<int, array<string, mixed>>> $files
     */
    public function __construct(string $method, string $path, array $query, array $headers, array $body, array $files)
    {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->query = $query;
        $this->headers = $headers;
        $this->body = $body;
        $this->files = $files;
    }

    public static function fromGlobals(): self
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $contentType = strtolower((string)($headers['Content-Type'] ?? $headers['content-type'] ?? ''));
        $rawBody = file_get_contents('php://input') ?: '';
        $body = [];
        $parseError = null;
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $body = $decoded;
            } elseif (trim($rawBody) !== '') {
                $parseError = 'Malformed JSON request body.';
            }
        } elseif (is_array($_POST ?? null)) {
            $body = $_POST;
        }

        $request = new self(
            (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/',
            is_array($_GET ?? null) ? $_GET : [],
            is_array($headers) ? $headers : [],
            $body,
            self::normaliseFiles(is_array($_FILES ?? null) ? $_FILES : [])
        );
        if ($parseError !== null) {
            $request->attributes['body_parse_error'] = $parseError;
        }
        return $request;
    }

    public function header(string $key, ?string $default = null): ?string
    {
        foreach ($this->headers as $name => $value) {
            if (strcasecmp((string)$name, $key) === 0) {
                return is_array($value) ? (string)($value[0] ?? $default) : (string)$value;
            }
        }
        return $default;
    }

    /** @return array<string, mixed>|null */
    public function uploadedFile(string $key): ?array
    {
        return $this->files[$key][0] ?? null;
    }

    /** @param array<string, mixed> $files @return array<string, array<int, array<string, mixed>>> */
    private static function normaliseFiles(array $files): array
    {
        $normalised = [];
        foreach ($files as $field => $spec) {
            if (!is_array($spec) || !array_key_exists('name', $spec)) {
                continue;
            }
            $normalised[(string)$field] = self::flattenFiles(
                $spec['name'] ?? null,
                $spec['type'] ?? null,
                $spec['tmp_name'] ?? null,
                $spec['error'] ?? null,
                $spec['size'] ?? null,
            );
        }
        return $normalised;
    }

    /** @return array<int, array<string, mixed>> */
    private static function flattenFiles(mixed $name, mixed $type, mixed $tmpName, mixed $error, mixed $size): array
    {
        if (is_array($name)) {
            $items = [];
            foreach ($name as $index => $childName) {
                array_push($items, ...self::flattenFiles(
                    $childName,
                    is_array($type) ? ($type[$index] ?? null) : null,
                    is_array($tmpName) ? ($tmpName[$index] ?? null) : null,
                    is_array($error) ? ($error[$index] ?? null) : null,
                    is_array($size) ? ($size[$index] ?? null) : null,
                ));
            }
            return $items;
        }
        return [[
            'name' => is_string($name) ? $name : '',
            'type' => is_string($type) ? $type : '',
            'tmp_name' => is_string($tmpName) ? $tmpName : '',
            'error' => is_numeric($error) ? (int)$error : UPLOAD_ERR_NO_FILE,
            'size' => is_numeric($size) ? (int)$size : 0,
        ]];
    }
}
