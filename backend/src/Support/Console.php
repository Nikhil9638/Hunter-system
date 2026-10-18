<?php

declare(strict_types=1);

namespace Hunter\Support;

use RuntimeException;

/** Helpers for CLI scripts (backend/bin). Refuse to run under a web server. */
final class Console
{
    public static function requireCli(): void
    {
        if (PHP_SAPI !== 'cli') {
            if (!headers_sent()) {
                http_response_code(404);
            }
            exit;
        }
    }

    /**
     * Read a secret without echo when a terminal is attached:
     *   Windows: PowerShell Read-Host -AsSecureString (UTF-8 output captured, never shown)
     *   others:  stty -echo
     * When STDIN is not a terminal (automation/tests), one line is read from STDIN.
     * Secrets are never accepted as arguments or environment variables.
     */
    public static function readSecret(string $prompt): string
    {
        // Decide once, before anything is read: calling stream_isatty() after a
        // buffered read discards PHP's read-ahead buffer (the next piped line).
        static $tty = null;
        $tty ??= stream_isatty(STDIN);
        if (!$tty) {
            $line = fgets(STDIN);
            if ($line === false) {
                throw new RuntimeException('No input on STDIN.');
            }
            return rtrim($line, "\r\n");
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $ps = '[Console]::OutputEncoding = [Text.Encoding]::UTF8; '
                . '$s = Read-Host -AsSecureString -Prompt ' . "'" . str_replace("'", "''", $prompt) . "'" . '; '
                . '$b = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($s); '
                . 'try { [Console]::Out.Write([Runtime.InteropServices.Marshal]::PtrToStringBSTR($b)) } '
                . 'finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b) }';
            $encoded = base64_encode(mb_convert_encoding($ps, 'UTF-16LE', 'UTF-8'));
            $proc = proc_open(['powershell.exe', '-NoProfile', '-EncodedCommand', $encoded],
                [0 => STDIN, 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
            if (!is_resource($proc)) {
                throw new RuntimeException('Could not start PowerShell for hidden input.');
            }
            $secret = (string)stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            proc_close($proc);
            fwrite(STDOUT, PHP_EOL);
            return rtrim($secret, "\r\n");
        }
        fwrite(STDOUT, $prompt . ': ');
        shell_exec('stty -echo');
        $line = fgets(STDIN);
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
        return rtrim((string)$line, "\r\n");
    }

    /** @return array<string,string> --key=value options */
    public static function options(array $argv): array
    {
        $out = [];
        foreach (array_slice($argv, 1) as $arg) {
            if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m)) {
                $out[$m[1]] = $m[2] ?? '1';
            }
        }
        return $out;
    }
}
