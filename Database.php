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
        if ($position && !empty($args)) {
            $this->prepareTemplate($query, $position, $args[0]);
            array_shift($args);
        } else if ($position && empty($args)) {
            throw new Exception('Missing arguments');
        } else {
            return $query;
        }

        return $this->buildQuery($query, $args);
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
    protected function prepareTemplate(string &$query, int $position, string|int|float|bool|array $needle): void
    {
        $key = substr($query, $position, 1);
        if ($key === '{') {
            $positionCloseBlock = strpos($query, '}', $position);
            if ($positionCloseBlock === false ) {
                throw new Exception("The closing bracket  } is missing");
            }
            $positionNextOpenCloseBlock = $this->multiStrPos($query, ['{', '}'],$position + 1);
            if ($positionNextOpenCloseBlock !== $positionCloseBlock ) {
                throw new Exception("double opening of the block");
            }
            $positionSkipParameter = strpos($query, $needle, $position);
            if(
                $positionSkipParameter !== false &&
                $positionSkipParameter < $positionCloseBlock
            ) {
                $query = preg_replace('~{.+?}~', '', $query, 1);
               } else {
                $query = preg_replace('/{|}/', '', $query, 2);
                $query = $this->buildQuery($query, [$needle]);
            }
        } else {
            $this->fillingTemplate($query, $position, $needle);
        }
    }

    /**
     * @throws Exception
     */
    protected function fillingTemplate(&$query, int $position, string|int|float|bool|array $needle): void
    {
        $key = trim(substr($query, $position, 2));
        match ($key) {
            '?'  => $this->replaceStandard($query, $needle),
            '?d' => $this->replaceInt($query, $needle),
            '?f' => $this->replaceFloat($query, $needle),
            '?a' => $this->replaceArray($query, $needle),
            '?#' => $this->replaceIdentification($query, $needle),
            default => throw new Exception("Unknown template parameter $key"),
        };
    }
    protected function replaceStandard(string &$query, string|int|float|bool $key): void
    {
        $value = match (gettype($key)){
            'boolean' => $key ? 1 : 0,
            'string' => "'$key'",
            'integer', 'double' => $key,
            'NULL' => 'NULL',
        };
        $query = preg_replace('/\?/', $value, $query, 1);
    }

    protected function replaceArray(string &$query, string|array|null $key): void
    {
        if (is_array($key)){
            $value = $this->parseArray($key);
        } else {
            $value = "'$key'";
        }
        $query = preg_replace('/\?a/', $value, $query, 1);
    }

    protected function replaceInt(string &$query, string|int|float|bool|array|null $key): void
    {
        $query = preg_replace('/\?d/',intval($key), $query, 1);
    }

    protected function replaceFloat(string &$query, string|int|float|bool|array|null $key): void
    {
        $query = preg_replace('/\?f/',floatval($key), $query, 1);
    }

    protected function replaceIdentification(string &$query, string|array|null $key): void
    {
        if (is_array($key)){
            $value = $this->parseArray($key, "`");
        } else {
            $value = "`$key`";
        }
        $query = preg_replace('/\?#/', $value, $query, 1);
    }

    protected function parseArray(array &$array, string $separator = "'"): string
    {
        foreach ($array as $key => $value) {
            $str = $value ? $separator.$value.$separator : 'NULL';
            if (is_numeric($key)) {
                if (!is_numeric($value)) {
                    $array[$key] = $str;
                }
            } else {
                $array[$key] = "`$key` = $str";
            }
        }
        return implode(', ', $array);
    }
}
