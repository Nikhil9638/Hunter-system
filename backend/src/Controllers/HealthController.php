<?php

declare(strict_types=1);

namespace Hunter\Controllers;

use Hunter\Db\Database;
use Hunter\Http\Request;
use Hunter\Http\Response;
use Hunter\Support\PasswordPolicy;
use Hunter\Support\Text;
use Throwable;

/** GET /health: booleans only (no versions, paths or settings). 503 if anything required is missing. */
final class HealthController
{
    public const SCHEMA_VERSION = '2026.10.14-3';

    public function __construct(private Database $db)
    {
    }

    public function show(Request $request): void
    {
        $checks = ['database' => false, 'schema' => false, 'intl' => Text::intlAvailable(), 'argon2id' => PasswordPolicy::argon2idAvailable()];
        try {
            $row = $this->db->one('SELECT schema_version FROM schema_info WHERE id = 1');
            $checks['database'] = true;
            $checks['schema'] = ($row['schema_version'] ?? null) === self::SCHEMA_VERSION;
        } catch (Throwable) {
            // reported as false
        }
        $ok = !in_array(false, $checks, true);
        Response::data($ok ? 200 : 503, ['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks]);
    }
}
