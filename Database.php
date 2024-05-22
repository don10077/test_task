<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
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
        $position = $this->multiStrPos($query, ['?', '{', '}']);
        if ($position && empty($args)) {
            throw new Exception('Missing arguments');
        }

        if ($position) {
            $query = $this->prepareTemplate($query, $position, $args[0]);
            array_shift($args);
            return $this->buildQuery($query, $args);
        }

        return $query;
    }

    public function skip(): string
    {
        return 'AND';
    }

    public function multiStrPos(string $haystack, array $needles, int $offset = 0): false|int
    {
        $position = false;
        foreach ($needles as $needle) {
            $positionNew = strpos($haystack, $needle, $offset);
            if (
                $positionNew !== false &&
                (
                    !$position ||
                    $position > $positionNew
                )
            ) {
                $position = $positionNew;
            }
        }
        return $position;
    }

    /**
     * @throws Exception
     */
    protected function prepareTemplate(string $query, int $position, string|int|float|bool|array|null $needle): string
    {
        $key = substr($query, $position, 1);
        if ($key === '{') {
            return $this->fillingBracketTemplate($query, $position, $needle);
        }
        return $this->fillingTemplate($query, $position, $needle);
    }

    /**
     * @throws Exception
     */
    protected function fillingTemplate($query, int $position, string|int|float|bool|array|null $needle): string
    {
        $key = trim(substr($query, $position, 2));
        return match ($key) {
            '?' => $this->replaceStandard($query, $needle),
            '?d' => $this->replaceInt($query, $needle),
            '?f' => $this->replaceFloat($query, $needle),
            '?a' => $this->replaceArray($query, $needle),
            '?#' => $this->replaceIdentification($query, $needle),
            default => throw new Exception("Unknown template parameter $key"),
        };
    }

    /**
     * @throws Exception
     */
    protected function fillingBracketTemplate($query, int $position, string|int|float|bool|array $needle): string
    {
        $positionCloseBlock = strpos($query, '}', $position);
        if ($positionCloseBlock === false) {
            throw new Exception("The closing bracket } is missing  in query: $query");
        }

        $positionNextOpenCloseBlock = $this->multiStrPos($query, ['{', '}'], $position + 1);
        if ($positionNextOpenCloseBlock !== $positionCloseBlock) {
            throw new Exception("double opening of the block in query: $query");
        }

        $positionSkipParameter = strpos($query, $needle, $position);
        if (
            $positionSkipParameter !== false &&
            $positionSkipParameter < $positionCloseBlock
        ) {
            return preg_replace('~{.+?}~', '', $query, 1);
        }

        $query = preg_replace('/[{}]/', '', $query, 2);
        return $this->buildQuery($query, [$needle]);
    }

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
     */
    protected function replaceInt(string $query, string|int|float|bool|null $key): string
    {
        return preg_replace('/\?d/', is_null($key) ? 'NULL' : intval($key), $query, 1);
    }

    /**
     * @throws Exception
     */
    protected function replaceFloat(string $query, string|int|float|bool|null $key): string
    {
        return preg_replace('/\?f/', is_null($key) ? 'NULL': floatval($key), $query, 1);
    }

    protected function replaceIdentification(string $query, string|array $key): string
    {
        $value = is_array($key) ? $this->parseArray($key, "`") : "`$key`";
        return preg_replace('/\?#/', $value, $query, 1);
    }

    protected function replaceArray(string $query, string|array $key): string
    {
        $value = is_array($key) ? $this->parseArray($key) : "'$key'";
        return preg_replace('/\?a/', $value, $query, 1);
    }

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
