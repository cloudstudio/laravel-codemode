<?php

declare(strict_types=1);

namespace Cloudstudio\LaravelCodemode\Support;

/**
 * Recursively resolve all JSON $ref pointers in an OpenAPI spec.
 *
 * Flattens the spec by replacing every `$ref` pointer with the
 * referenced definition, so the resulting array contains no
 * unresolved references. Detects circular references to prevent
 * infinite recursion. Only supports internal references (starting with `#/`).
 */
class RefResolver
{
    private const REF_KEY = '$ref';

    private const CIRCULAR_REF_KEY = '$circular_ref';

    /** @var array<string, string> JSON Pointer escape sequences per RFC 6901. */
    private const POINTER_ESCAPES = ['~1' => '/', '~0' => '~'];

    /**
     * Resolve all $ref pointers in the given spec.
     *
     * @param  array  $spec  The full OpenAPI spec array.
     * @return array The spec with all $ref pointers resolved inline.
     */
    public static function resolve(array $spec): array
    {
        return static::resolveRefs($spec, $spec, []);
    }

    /**
     * Recursively walk the spec tree and resolve each $ref pointer.
     *
     * @param  mixed  $node  Current node being traversed.
     * @param  array  $root  The root spec for resolving pointers against.
     * @param  list<string>  $seen  Refs already visited in this path (circular guard).
     * @return mixed The node with all nested $ref pointers resolved inline.
     */
    private static function resolveRefs(mixed $node, array $root, array $seen): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        if (isset($node[self::REF_KEY]) && is_string($node[self::REF_KEY])) {
            return static::resolveRef($node[self::REF_KEY], $root, $seen);
        }

        foreach ($node as $key => $value) {
            $node[$key] = static::resolveRefs($value, $root, $seen);
        }

        return $node;
    }

    /**
     * Resolve a single $ref pointer, guarding against circular references.
     *
     * @param  string  $ref  The JSON $ref pointer string (e.g. "#/components/schemas/Product").
     * @param  array  $root  The root spec for resolving pointers against.
     * @param  list<string>  $seen  Refs already visited in this path (circular guard).
     * @return mixed The resolved definition, a circular-ref marker, or the original $ref if unresolvable.
     */
    private static function resolveRef(string $ref, array $root, array $seen): mixed
    {
        if (in_array($ref, $seen, true)) {
            return [self::CIRCULAR_REF_KEY => $ref];
        }

        $resolved = static::resolvePointer($ref, $root);

        if ($resolved === null) {
            return [self::REF_KEY => $ref];
        }

        return static::resolveRefs($resolved, $root, [...$seen, $ref]);
    }

    /**
     * Resolve a JSON pointer (e.g. #/components/schemas/Product) against the root spec.
     *
     * @param  string  $ref  The JSON pointer string (must start with "#/").
     * @param  array  $root  The root spec to resolve the pointer against.
     * @return array|null The referenced definition array, or null if the pointer is invalid or unresolvable.
     */
    private static function resolvePointer(string $ref, array $root): ?array
    {
        if (! str_starts_with($ref, '#/')) {
            return null;
        }

        $current = $root;

        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = strtr($segment, self::POINTER_ESCAPES);

            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return is_array($current) ? $current : null;
    }
}
