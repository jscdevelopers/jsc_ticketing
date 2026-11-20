<?php
declare(strict_types=1);
namespace App\Tickets;

use App\Http\ApiException;

/** Deliberately bounded JSON Schema subset. Unknown keywords fail closed. No eval or regex. */
final class IntakeSchema
{
    public static function validateDefinition(array $schema): void
    {
        if (($schema['type'] ?? null) !== 'object' || ($schema['additionalProperties'] ?? null) !== false
            || array_diff(array_keys($schema), ['type','properties','required','additionalProperties'])
            || !isset($schema['properties']) || !is_array($schema['properties']) || count($schema['properties']) > 30
            || (!empty($schema['properties']) && array_is_list($schema['properties']))) {
            throw new \InvalidArgumentException('Schema requires object type, properties, and additionalProperties=false.');
        }
        $required = array_key_exists('required', $schema) ? $schema['required'] : [];
        if (!is_array($required) || !array_is_list($required) || count($required) !== count(array_unique($required, SORT_REGULAR))) {
            throw new \InvalidArgumentException('Required fields must be a unique list.');
        }
        foreach ($required as $name) {
            if (!is_string($name) || !array_key_exists($name, $schema['properties'])) {
                throw new \InvalidArgumentException('Required field must exist in properties.');
            }
        }
        foreach ($schema['properties'] as $name => $rule) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]{0,49}$/D', $name) || !is_array($rule)) {
                throw new \InvalidArgumentException('Invalid field name or definition.');
            }
            $type = $rule['type'] ?? '';
            $keywords = match ($type) {
                'string' => ['type','title','enum','minLength','maxLength'],
                'integer', 'number' => ['type','title','enum','minimum','maximum'],
                'boolean' => ['type','title'],
                default => throw new \InvalidArgumentException('Only string, integer, number and boolean fields are supported.'),
            };
            if (array_diff(array_keys($rule), $keywords) || (array_key_exists('title', $rule) && (!is_string($rule['title']) || strlen($rule['title']) > 100))) {
                throw new \InvalidArgumentException('Unsupported field keyword or title.');
            }
            foreach (['minimum','maximum','minLength','maxLength'] as $bound) {
                if (array_key_exists($bound, $rule) && ((!is_int($rule[$bound]) && !is_float($rule[$bound])) || !is_finite((float)$rule[$bound]))) {
                    throw new \InvalidArgumentException('Field bounds must be numeric.');
                }
            }
            foreach (['minLength','maxLength'] as $bound) {
                if (isset($rule[$bound]) && (!is_int($rule[$bound]) || $rule[$bound] < 0 || $rule[$bound] > 4000)) {
                    throw new \InvalidArgumentException('String length bounds must be integers between 0 and 4000.');
                }
            }
            if (($rule['minimum'] ?? -INF) > ($rule['maximum'] ?? INF)
                || ($rule['minLength'] ?? 0) > ($rule['maxLength'] ?? 4000)) {
                throw new \InvalidArgumentException('Field bounds are reversed.');
            }
            if (array_key_exists('enum', $rule)) {
                if (!is_array($rule['enum']) || !array_is_list($rule['enum']) || count($rule['enum']) < 1 || count($rule['enum']) > 50) {
                    throw new \InvalidArgumentException('Enum requires 1..50 values.');
                }
                foreach ($rule['enum'] as $value) {
                    if (!self::matches($value, $type) || (is_string($value) && (self::length($value) > 4000 || str_contains($value, "\0")))) {
                        throw new \InvalidArgumentException('Enum values must match the field type.');
                    }
                }
            }
        }
    }

    public static function validateFields(array $schema, array $fields): void
    {
        self::validateDefinition($schema);
        if (array_diff(array_keys($fields), array_keys($schema['properties']))) {
            throw new ApiException(422, 'invalid_fields', 'An unsupported form field was supplied.');
        }
        foreach ($schema['required'] ?? [] as $name) {
            if (!array_key_exists($name, $fields) || (is_string($fields[$name]) && trim($fields[$name]) === '')) {
                throw new ApiException(422, 'invalid_fields', 'Complete required field: ' . $name);
            }
        }
        foreach ($fields as $name => $value) {
            $rule = $schema['properties'][$name];
            if (!self::matches($value, $rule['type'])
                || (isset($rule['enum']) && !in_array($value, $rule['enum'], true))
                || (is_string($value) && (str_contains($value, "\0") || self::length($value) < ($rule['minLength'] ?? 0) || self::length($value) > ($rule['maxLength'] ?? 4000)))
                || ((is_int($value) || is_float($value)) && ($value < ($rule['minimum'] ?? -INF) || $value > ($rule['maximum'] ?? INF)))) {
                throw new ApiException(422, 'invalid_fields', 'Invalid form field: ' . $name);
            }
        }
    }

    public static function length(string $value): int
    {
        return preg_match_all('/./us', $value, $matches) === false ? PHP_INT_MAX : count($matches[0]);
    }

    private static function matches(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || (is_float($value) && is_finite($value)),
            'boolean' => is_bool($value),
        };
    }
}
