<?php

declare(strict_types=1);

namespace Hunter\Services;

use Hunter\Http\ApiError;
use Hunter\Repositories\AuditRepository;
use Hunter\Support\Time;

/** Paginated, filtered administrator audit history (api-contract.md §10.6). */
final class AuditService
{
    public function __construct(private AuditRepository $audit)
    {
    }

    public function page(array $query): array
    {
        $f = [];
        $filters = ['entity_type' => null, 'entity_id' => null, 'admin_id' => null, 'action' => null, 'since' => null];
        if (isset($query['entity_type'])) {
            in_array($query['entity_type'], AuditRepository::ENTITY_TYPES, true) ? $filters['entity_type'] = $query['entity_type'] : $f['entity_type'] = 'invalid';
        }
        if (isset($query['action'])) {
            in_array($query['action'], AuditRepository::ACTIONS, true) ? $filters['action'] = $query['action'] : $f['action'] = 'invalid';
        }
        $filters['entity_id'] = Paging::int($query, 'entity_id', 1, 4294967295, null, $f);
        $filters['admin_id'] = Paging::int($query, 'admin_id', 1, 4294967295, null, $f);
        $filters['since'] = Paging::since($query, $f);
        [$page, $perPage] = Paging::parse($query, $f);
        if ($f !== []) {
            throw ApiError::validation($f);
        }
        $r = $this->audit->page($filters, $page, $perPage);
        return [
            'entries' => array_map(static function (array $a): array {
                $after = $a['after_data'] === null ? null : json_decode($a['after_data'], true);
                return [
                    'id' => (int)$a['id'], 'at' => Time::iso($a['created_at']),
                    'admin' => ['id' => (int)$a['admin_user_id'], 'display_name' => $a['display_name']],
                    'action' => $a['action'], 'operation' => is_array($after) ? ($after['operation'] ?? null) : null,
                    'entity_type' => $a['entity_type'], 'entity_id' => (int)$a['entity_id'],
                    'before' => $a['before_data'] === null ? null : json_decode($a['before_data'], true),
                    'after' => $after,
                ];
            }, $r['rows']),
            'page' => $page, 'per_page' => $perPage, 'total' => $r['total'],
        ];
    }
}
