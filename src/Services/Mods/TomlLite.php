<?php

namespace ChristianHnl\AiDebugger\Services\Mods;

/**
 * Minimal, lenient TOML reader for (neo)forge mods.toml files.
 *
 * Supports tables, arrays of tables, dotted table names, strings (basic, literal, multiline),
 * numbers, booleans, inline arrays and inline tables (shallow). Anything it doesn't understand is skipped.
 */
class TomlLite
{
    /** @return array<string, mixed> */
    public static function parse(string $toml): array
    {
        $result = [];
        $current = &$result;

        $lines = preg_split('/\R/', $toml) ?: [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = trim($lines[$i]);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // [[array.of.tables]]
            if (preg_match('/^\[\[\s*([^\]]+?)\s*\]\]/', $line, $m)) {
                unset($current);
                $current = &self::arrayTable($result, self::splitKey($m[1]));

                continue;
            }

            // [table]
            if (preg_match('/^\[\s*([^\]]+?)\s*\]/', $line, $m)) {
                unset($current);
                $current = &self::table($result, self::splitKey($m[1]));

                continue;
            }

            if (!preg_match('/^("[^"]*"|\'[^\']*\'|[A-Za-z0-9_.\-]+)\s*=\s*(.*)$/s', $line, $m)) {
                continue;
            }

            $keyParts = self::splitKey($m[1]);
            $raw = $m[2];

            // Multiline strings
            foreach (['"""', "'''"] as $quote) {
                if (str_starts_with($raw, $quote)) {
                    $body = substr($raw, 3);
                    while (!str_contains($body, $quote) && $i + 1 < $count) {
                        $body .= "\n" . $lines[++$i];
                    }

                    $end = strpos($body, $quote);
                    $value = $end === false ? $body : substr($body, 0, $end);
                    self::assign($current, $keyParts, ltrim($value, "\n"));

                    continue 2;
                }
            }

            // Multiline arrays
            if (str_starts_with($raw, '[')) {
                while (self::bracketDepth($raw) > 0 && $i + 1 < $count) {
                    $raw .= ' ' . trim($lines[++$i]);
                }
            }

            self::assign($current, $keyParts, self::value(self::stripComment($raw)));
        }

        return $result;
    }

    /** @return string[] */
    protected static function splitKey(string $key): array
    {
        preg_match_all('/"([^"]*)"|\'([^\']*)\'|([^.\s]+)/', $key, $matches, PREG_SET_ORDER);

        return array_map(fn ($match) => $match[3] ?? $match[2] ?? $match[1], $matches);
    }

    /**
     * @param  array<string, mixed>  $root
     * @param  string[]  $path
     * @return array<string, mixed>
     */
    protected static function &table(array &$root, array $path): array
    {
        $node = &$root;
        foreach ($path as $part) {
            if (!isset($node[$part]) || !is_array($node[$part])) {
                $node[$part] = [];
            }

            // Descend into the last element of an array of tables
            if (array_is_list($node[$part]) && !empty($node[$part]) && is_array(end($node[$part]))) {
                $node = &$node[$part][array_key_last($node[$part])];
            } else {
                $node = &$node[$part];
            }
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $root
     * @param  string[]  $path
     * @return array<string, mixed>
     */
    protected static function &arrayTable(array &$root, array $path): array
    {
        $last = array_pop($path);
        $parent = &self::table($root, $path);

        if (!isset($parent[$last]) || !is_array($parent[$last]) || !array_is_list($parent[$last])) {
            $parent[$last] = [];
        }

        $parent[$last][] = [];

        return $parent[$last][array_key_last($parent[$last])];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  string[]  $path
     */
    protected static function assign(array &$node, array $path, mixed $value): void
    {
        $last = array_pop($path);
        foreach ($path as $part) {
            if (!isset($node[$part]) || !is_array($node[$part])) {
                $node[$part] = [];
            }
            $node = &$node[$part];
        }

        $node[$last] = $value;
    }

    protected static function bracketDepth(string $raw): int
    {
        $depth = 0;
        $quote = null;
        $length = strlen($raw);

        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];

            if ($quote) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '#') {
                break;
            } elseif ($char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ']' || $char === '}') {
                $depth--;
            }
        }

        return $depth;
    }

    protected static function stripComment(string $raw): string
    {
        $quote = null;
        $length = strlen($raw);

        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];

            if ($quote) {
                if ($char === '\\' && $quote === '"') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '#') {
                return trim(substr($raw, 0, $i));
            }
        }

        return trim($raw);
    }

    protected static function value(string $raw): mixed
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        if ($raw[0] === '"') {
            $end = strrpos($raw, '"');

            return stripcslashes(substr($raw, 1, $end > 0 ? $end - 1 : null));
        }

        if ($raw[0] === "'") {
            $end = strrpos($raw, "'");

            return substr($raw, 1, $end > 0 ? $end - 1 : null);
        }

        if ($raw === 'true' || $raw === 'false') {
            return $raw === 'true';
        }

        if (is_numeric(str_replace('_', '', $raw))) {
            $number = str_replace('_', '', $raw);

            return str_contains($number, '.') ? (float) $number : (int) $number;
        }

        if ($raw[0] === '[') {
            return array_map(fn ($item) => self::value($item), self::splitList(substr($raw, 1, -1)));
        }

        if ($raw[0] === '{') {
            $table = [];
            foreach (self::splitList(substr($raw, 1, -1)) as $pair) {
                if (preg_match('/^\s*("[^"]*"|\'[^\']*\'|[A-Za-z0-9_.\-]+)\s*=\s*(.*)$/s', $pair, $m)) {
                    self::assign($table, self::splitKey($m[1]), self::value($m[2]));
                }
            }

            return $table;
        }

        return $raw;
    }

    /** @return string[] */
    protected static function splitList(string $raw): array
    {
        $items = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $length = strlen($raw);

        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];

            if ($quote) {
                $buffer .= $char;
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ']' || $char === '}') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                if (trim($buffer) !== '') {
                    $items[] = trim($buffer);
                }
                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $items[] = trim($buffer);
        }

        return $items;
    }
}
