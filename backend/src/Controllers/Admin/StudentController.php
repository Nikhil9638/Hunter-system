<?php

declare(strict_types=1);

namespace Hunter\Controllers\Admin;

use Hunter\Http\Request;
use Hunter\Http\Response;
use Hunter\Services\AdminTrackingService;
use Hunter\Services\AuthContext;

/** Administrator student tracking (api-contract.md §9). Admin only, read-only. */
final class StudentController
{
    public function __construct(private AdminTrackingService $tracking)
    {
    }

    public function index(Request $request, AuthContext $ctx): void
    {
        Response::data(200, $this->tracking->students($request->query));
    }

    public function show(Request $request, AuthContext $ctx, int $userId): void
    {
        Response::data(200, $this->tracking->student($userId));
    }

    public function logins(Request $request, AuthContext $ctx, int $userId): void
    {
        Response::data(200, $this->tracking->logins($userId, $request->query));
    }

    public function loginEvents(Request $request, AuthContext $ctx): void
    {
        Response::data(200, $this->tracking->loginEvents($request->query));
    }
}
