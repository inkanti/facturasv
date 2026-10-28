<?php

declare(strict_types=1);

// Validador reducido para las reglas draft-07 utilizadas por los esquemas MH incluidos.
function validateJsonSchema(mixed $data, array $schema, string $path = '$'): array
{
    $errors = [];
    $type = $schema['type'] ?? null;
    if ($type !== null && !jsonSchemaTypeMatches($data, (array) $type)) {
        return ["{$path}: el tipo de dato no es válido."];
    }
    if (array_key_exists('const', $schema) && $data !== $schema['const']) {
        $errors[] = "{$path}: no coincide con el valor requerido.";
    }
    if (isset($schema['enum']) && !in_array($data, $schema['enum'], true)) {
        $errors[] = "{$path}: no pertenece a los valores permitidos.";
    }
    if (is_string($data)) {
        if (isset($schema['minLength']) && mb_strlen($data) < $schema['minLength']) {
            $errors[] = "{$path}: es demasiado corto.";
        }
        if (isset($schema['maxLength']) && mb_strlen($data) > $schema['maxLength']) {
            $errors[] = "{$path}: es demasiado largo.";
        }
        if (isset($schema['pattern']) && @preg_match('/' . str_replace('/', '\/', $schema['pattern']) . '/u', $data) !== 1) {
            $errors[] = "{$path}: no cumple el formato requerido.";
        }
        if (($schema['format'] ?? '') === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            $errors[] = "{$path}: debe ser una fecha YYYY-MM-DD.";
        }
    }
    if (is_int($data) || is_float($data)) {
        if (isset($schema['minimum']) && $data < $schema['minimum']) $errors[] = "{$path}: está por debajo del mínimo.";
        if (isset($schema['maximum']) && $data > $schema['maximum']) $errors[] = "{$path}: supera el máximo.";
        if (isset($schema['exclusiveMinimum']) && $data <= $schema['exclusiveMinimum']) $errors[] = "{$path}: debe ser mayor que el mínimo.";
        if (isset($schema['exclusiveMaximum']) && $data >= $schema['exclusiveMaximum']) $errors[] = "{$path}: debe ser menor que el máximo.";
        if (isset($schema['multipleOf'])) {
            $quotient = $data / $schema['multipleOf'];
            if (abs($quotient - round($quotient)) > 0.00001) $errors[] = "{$path}: tiene demasiados decimales.";
        }
    }
    if (is_array($data) && array_is_list($data)) {
        if (isset($schema['minItems']) && count($data) < $schema['minItems']) $errors[] = "{$path}: no tiene suficientes elementos.";
        if (isset($schema['maxItems']) && count($data) > $schema['maxItems']) $errors[] = "{$path}: tiene demasiados elementos.";
        if (isset($schema['items'])) {
            foreach ($data as $index => $item) {
                $errors = array_merge($errors, validateJsonSchema($item, $schema['items'], $path . '[' . $index . ']'));
            }
        }
    }
    if (is_array($data) && !array_is_list($data)) {
        foreach (($schema['required'] ?? []) as $required) {
            if (!array_key_exists($required, $data)) $errors[] = "{$path}: falta {$required}.";
        }
        $properties = $schema['properties'] ?? [];
        if (($schema['additionalProperties'] ?? true) === false) {
            foreach (array_keys($data) as $key) {
                if (!array_key_exists($key, $properties)) $errors[] = "{$path}: la propiedad {$key} no está permitida.";
            }
        }
        foreach ($properties as $key => $rules) {
            if (array_key_exists($key, $data)) $errors = array_merge($errors, validateJsonSchema($data[$key], $rules, $path . '.' . $key));
        }
    }
    return $errors;
}

function jsonSchemaTypeMatches(mixed $data, array $types): bool
{
    foreach ($types as $type) {
        $matches = match ($type) {
            'object' => is_array($data) && !array_is_list($data),
            'array' => is_array($data) && array_is_list($data),
            'string' => is_string($data),
            'integer' => is_int($data),
            'number' => is_int($data) || is_float($data),
            'null' => $data === null,
            'boolean' => is_bool($data),
            default => true,
        };
        if ($matches) return true;
    }
    return false;
}
