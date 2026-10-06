<?php
// json2dir: create the directory tree a JSON document describes, in the current directory.
// Implements RFC J2D-1 (https://github.com/kitsunoff/awesome-json2dir/blob/main/spec/rfc-json2dir.md).
// Core PHP only: json_decode (built in since PHP 8) and the file system functions.

class Json2dirError extends Exception {}

function fail(string $message): void
{
    throw new Json2dirError($message);
}

// Turn PHP warnings (failed mkdir, unlink, ...) into exceptions.
set_error_handler(function (int $no, string $msg) {
    if (!(error_reporting() & $no)) return false; // silenced with @
    throw new Json2dirError(preg_replace('/^\w+\(\): /', '', $msg));
});

// §3: json_decode rejects invalid UTF-8, lone surrogates, comments, trailing commas, NaN and
// trailing data. Objects decode as stdClass so {} and [] stay distinct. A leading BOM is ignored.
function parse(string $bytes)
{
    if (strncmp($bytes, "\xEF\xBB\xBF", 3) === 0) $bytes = substr($bytes, 3);
    try {
        return json_decode($bytes, false, 100000, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fail('input is not valid JSON: ' . $e->getMessage());
    }
}

// Members of an object as [name => value], names as strings, in ascending byte order.
function members(stdClass $obj): array
{
    $out = [];
    foreach ($obj as $name => $value) $out[(string)$name] = $value;
    uksort($out, fn($a, $b) => strcmp((string)$a, (string)$b));
    return $out;
}

// §4.2.1: names are used exactly; trailing "/" or "/." forms are rejected, not trimmed.
function checkName(string $name, string $where): void
{
    if ($name === '' || $name === '.' || $name === '..' || strpos($name, '/') !== false || strpos($name, "\0") !== false)
        fail("$where: invalid name " . json_encode($name, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
}

// §4, §6: validate the whole document before touching the file system.
function validate($value, string $where): void
{
    if (is_string($value)) return;
    if (is_array($value)) {
        if (count($value) !== 2 || !is_string($value[0]) || !is_string($value[1]))
            fail("$where: an array must be [\"link\", target] or [\"script\", content]");
        if ($value[0] !== 'link' && $value[0] !== 'script') fail("$where: unknown array kind \"{$value[0]}\"");
        if ($value[0] === 'link' && strpos($value[1], "\0") !== false) fail("$where: a link target cannot contain NUL");
        return;
    }
    if ($value instanceof stdClass) {
        foreach (members($value) as $name => $child) {
            $name = (string)$name;
            $path = $where === '.' ? $name : "$where/$name";
            checkName($name, $path);
            validate($child, $path);
        }
        return;
    }
    fail("$where: " . ($value === null ? 'null' : gettype($value)) . ' values are not allowed');
}

// lstat that returns null for a missing entry; never follows a symlink.
function lstatOrNull(string $p): ?array
{
    clearstatcache(true, $p);
    $st = @lstat($p);
    return $st === false ? null : $st;
}

function isDir(?array $st): bool
{
    return $st !== null && ($st['mode'] & 0170000) === 0040000;
}

// §5.2, §5.3: an existing non-directory is removed (a symlink itself, never its target);
// §5.4: a directory in the way of a non-object is an error.
function clear(string $p, ?array $st): void
{
    if ($st === null) return;
    if (isDir($st)) fail("$p: a directory is in the way");
    unlink($p);
}

function writeFile(string $p, string $content, bool $executable): void
{
    // 'x' = O_CREAT | O_EXCL, mode 0666 & ~umask.
    $f = fopen($p, 'xb');
    try {
        if ($content !== '' && fwrite($f, $content) !== strlen($content)) fail("$p: short write");
    } finally {
        fclose($f);
    }
    if ($executable) {
        clearstatcache(true, $p);
        chmod($p, (fileperms($p) & 07777) | 0111);
    }
}

function apply(string $dir, stdClass $tree): void
{
    foreach (members($tree) as $name => $value) {
        $p = $dir . '/' . $name;
        $st = lstatOrNull($p);
        if (is_string($value)) {
            clear($p, $st);
            writeFile($p, $value, false);
        } elseif (is_array($value)) {
            clear($p, $st);
            if ($value[0] === 'link') symlink($value[1], $p);
            else writeFile($p, $value[1], true);
        } else {
            if (!isDir($st)) {
                if ($st !== null) unlink($p);
                mkdir($p);
            }
            apply($p, $value);
        }
    }
}

function main(array $args): int
{
    if (count($args) > 0) {
        fwrite(STDERR, "usage: json2dir < document.json\n");
        return 2;
    }
    try {
        $doc = parse(file_get_contents('php://stdin'));
        if (!($doc instanceof stdClass)) fail('the root of the document must be an object');
        validate($doc, '.');
        apply('.', $doc);
        return 0;
    } catch (Throwable $e) {
        fwrite(STDERR, 'json2dir: ' . $e->getMessage() . "\n");
        return 1;
    }
}

exit(main(array_slice($argv, 1)));
