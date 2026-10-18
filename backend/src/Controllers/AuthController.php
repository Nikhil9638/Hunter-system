<?php

declare(strict_types=1);

namespace Hunter\Controllers;

use Hunter\Http\Request;
use Hunter\Http\Response;
use Hunter\Services\AuthContext;
use Hunter\Services\AuthService;

/** /auth/* endpoints (api-contract.md §2). Thin: parse input, call the service, shape JSON. */
final class AuthController
{
    public function __construct(private AuthService $auth)
    {
    }

    public function register(Request $request): void
    {
        $input = $request->jsonObject(['email', 'display_name', 'password']);
        Response::data(201, $this->auth->register($request, $input));
    }

    public function login(Request $request): void
    {
        $input = $request->jsonObject(['email', 'password']);
        Response::data(200, $this->auth->login($request, $input));
    }

    public function logout(Request $request, AuthContext $ctx): void
    {
        $this->auth->logout($request, $ctx);
        Response::data(200, ['logged_out' => true]);
    }

    public function me(Request $request, AuthContext $ctx): void
    {
        Response::data(200, ['user' => $this->auth->userSelf($ctx->userId), 'csrf_token' => $ctx->csrfToken]);
    }
}
