<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    const string VALUE_FOR_SKIP_BLOCK = 'block';
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * @throws Exception
     */
    public function buildQuery(string $query, array $args = []): string
    {
        preg_match_all("/(\{[^}]*}|\?[dfa#]?)/", $query, $match);
        $inserts = $match[0];
        if ($inserts) {
            if (count($inserts) !== count($args)) {
                throw new Exception("Args count does not match template");
            }
            $query = $this->prepareTemplate($query, $inserts[0], $args[0]);

            array_shift($args);
            return $this->buildQuery($query, $args);
        }

        if (str_contains($query, '}')) {
            throw new Exception("The opening bracket { is missing  in query: $query");
        }

        return $query;
    }

    public function skip(): string
    {
        return self::VALUE_FOR_SKIP_BLOCK;
    }

    /**
     * @throws Exception
     * @param string $query
     * @param string $key
     * @param string|int|float|bool|array|null $needle
     * @return string
     */
    protected function prepareTemplate(string $query, string $key, string|int|float|bool|array|null $needle): string
    {
        if (str_starts_with($key, '{')) {
            return $this->fillingBracketTemplate($query, $needle);
        }
        return $this->fillingTemplate($query, $key, $needle);
    }

    /**
     * @throws Exception
     * @param string $query
     * @param string $key
     * @param string|int|float|bool|array|null $needle
     * @return string
     */
    protected function fillingTemplate(string $query, string $key, string|int|float|bool|array|null $needle): string
    {
        return match ($key) {
            '?'  => $this->replaceStandard($query, $needle),
            '?d' => $this->replaceInt($query, $needle),
            '?f' => $this->replaceFloat($query, $needle),
            '?a' => $this->replaceArray($query, $needle),
            '?#' => $this->replaceIdentification($query, $needle),
            default => throw new Exception("Unknown template parameter $key"),
        };
    }

    /**
     * @throws Exception
     * @param string $query
     * @param string|int|float|bool|array $needle
     * @return string
     */
    protected function fillingBracketTemplate(string $query, string|int|float|bool|array $needle): string
    {
        $positionOpenBracket = strpos($query, '{');
        $positionCloseBracket = strpos($query, '}', $positionOpenBracket);
        if ($positionCloseBracket === false) {
            throw new Exception("The closing bracket } is missing  in query: $query");
        }

        $positionNextOpenCloseBracket = strpos($query, '{', $positionOpenBracket + 1);
        if ( $positionNextOpenCloseBracket && $positionNextOpenCloseBracket < $positionCloseBracket) {
            throw new Exception("double opening of the bracket in query: $query");
        }

        $positionSkipParameter = strpos($query, $needle, $positionOpenBracket);
        if (
            $positionSkipParameter !== false &&
            $positionSkipParameter < $positionCloseBracket
        ) {
            return preg_replace('~{.+?}~', '', $query, 1);
        }

        $query = preg_replace('/[{}]/', '', $query, 2);
        return $this->buildQuery($query, [$needle]);
    }

    /**
     * @param string $query
     * @param string|int|float|bool $key
     * @return string
     */
    protected function replaceStandard(string $query, string|int|float|bool $key): string
    {
        $value = match (gettype($key)) {
            'boolean' => $key ? 1 : 0,
            'string' => "'$key'",
            'integer', 'double' => $key,
            'NULL' => 'NULL',
        };
        return preg_replace('/\?/', $value, $query, 1);
    }

    /**
     * @throws Exception
     * @param string $query
     * @param string|int|float|bool|null $key
     * @return string
     */
    protected function replaceInt(string $query, string|int|float|bool|null $key): string
    {
        return preg_replace('/\?d/', is_null($key) ? 'NULL' : intval($key), $query, 1);
    }

    /**
     * @param string $query
     * @param string|int|float|bool|null $key
     * @return string
     */
    protected function replaceFloat(string $query, string|int|float|bool|null $key): string
    {
        return preg_replace('/\?f/', is_null($key) ? 'NULL': floatval($key), $query, 1);
    }

    /**
     * @param string $query
     * @param string|array $key
     * @return string
     */
    protected function replaceIdentification(string $query, string|array $key): string
    {
        $value = is_array($key) ? $this->parseArray($key, "`") : "`$key`";
        return preg_replace('/\?#/', $value, $query, 1);
    }

    /**
     * @param string $query
     * @param string|array $key
     * @return string
     */
    protected function replaceArray(string $query, string|array $key): string
    {
        $value = is_array($key) ? $this->parseArray($key) : "'$key'";
        return preg_replace('/\?a/', $value, $query, 1);
    }

    /**
     * @param array $array
     * @param string $separator
     * @return string
     */
    protected function parseArray(array $array, string $separator = "'"): string
    {
        foreach ($array as $key => $value) {
            $str = $value ? $separator . $value . $separator : 'NULL';
            if (is_numeric($key)) {
                if (!is_numeric($value)) {
                    $array[$key] = $str;
                }
                continue;
            }
            $array[$key] = "`$key` = $str";
       }
        return implode(', ', $array);
    }
}
