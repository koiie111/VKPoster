<?php

declare(strict_types=1);

namespace App\Kernel\Validation;

use App\Kernel\Http\UploadedFile;
use App\Kernel\View\Translator;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Rule-based input validation with Russian messages.
 *
 * Rules are a `'required|string|max:100'` string or a list of rule strings (use the list form when a
 * regex contains `|`). Available: required, nullable, string, int, bool, email, min:N, max:N, in:a,b,
 * regex:/…/, url (http/https), timezone, date[:format], array, file, mimes:type,type, confirmed.
 * `min`/`max` mean length for strings, value for ints, item count for arrays, kilobytes for files.
 * An empty optional field skips its remaining rules; `nullable` also accepts null for typed rules.
 */
final class Validator
{
    public function __construct(private readonly Translator $translator)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|list<string>> $rules
     * @param array<string, string> $attributes human-readable field names for messages
     */
    public function make(array $data, array $rules, array $attributes = []): Validation
    {
        $errors = [];
        $validated = [];
        foreach ($rules as $field => $ruleSet) {
            $list = is_array($ruleSet) ? $ruleSet : explode('|', $ruleSet);
            $value = $data[$field] ?? null;
            $label = $attributes[$field] ?? $field;
            $required = in_array('required', $list, true);
            $blank = $value === null || $value === '' || $value === [];
            if ($blank && !$required) {
                $validated[$field] = $value;
                continue;
            }
            foreach ($list as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, '');
                if ($name === 'nullable') {
                    continue;
                }
                $message = $this->check($name, $arg, $field, $value, $data, $label, in_array('int', $list, true));
                if ($message !== null) {
                    $errors[$field][] = $message;
                    break;
                }
            }
            if (!isset($errors[$field])) {
                $validated[$field] = $value;
            }
        }

        return new Validation($errors, $validated);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function check(string $rule, string $arg, string $field, mixed $value, array $data, string $label, bool $numeric): ?string
    {
        $ok = match ($rule) {
            'required' => !($value === null || $value === '' || $value === [] || (is_string($value) && trim($value) === '')),
            'string' => is_string($value),
            'int' => is_int($value) || (is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1),
            'bool' => in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false', 'on', 'off'], true),
            'email' => is_string($value) && strlen($value) <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'in' => (is_string($value) || is_int($value)) && in_array((string) $value, explode(',', $arg), true),
            'regex' => is_string($value) && @preg_match($arg, $value) === 1,
            'url' => is_string($value) && $this->isHttpUrl($value),
            'timezone' => is_string($value) && in_array($value, DateTimeZone::listIdentifiers(), true),
            'date' => is_string($value) && $this->isDate($value, $arg !== '' ? $arg : 'Y-m-d'),
            'array' => is_array($value),
            'file' => $value instanceof UploadedFile && $value->isValid(),
            'mimes' => $value instanceof UploadedFile && in_array($value->mimeType(), explode(',', $arg), true),
            'confirmed' => ($data[$field . '_confirmation'] ?? null) === $value,
            'min' => $this->size($value, $numeric) >= (float) $arg,
            'max' => $this->size($value, $numeric) <= (float) $arg,
            default => throw new \InvalidArgumentException(sprintf('Unknown validation rule "%s".', $rule)),
        };
        if ($ok) {
            return null;
        }

        $key = 'validation.' . $rule;
        if ($rule === 'min' || $rule === 'max') {
            $key .= '.' . $this->kind($value, $numeric);
        }

        return $this->translator->t($key, ['attribute' => $label, 'min' => $arg, 'max' => $arg, 'types' => $arg]);
    }

    private function kind(mixed $value, bool $numeric): string
    {
        return match (true) {
            $value instanceof UploadedFile => 'file',
            is_array($value) => 'array',
            is_int($value) || ($numeric && is_string($value)) => 'int',
            default => 'string',
        };
    }

    private function size(mixed $value, bool $numeric): float
    {
        return match (true) {
            $value instanceof UploadedFile => $value->size / 1024,
            is_array($value) => (float) count($value),
            is_int($value) || ($numeric && is_string($value)) => (float) $value,
            is_string($value) => (float) mb_strlen($value),
            default => 0.0,
        };
    }

    private function isHttpUrl(string $value): bool
    {
        $parts = parse_url($value);

        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    private function isDate(string $value, string $format): bool
    {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format($format) === $value;
    }
}
