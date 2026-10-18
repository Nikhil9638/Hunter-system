<?php

declare(strict_types=1);

namespace Hunter\Controllers;

use Hunter\Http\Request;
use Hunter\Http\Response;
use Hunter\Services\AuthContext;
use Hunter\Services\BadgeService;

/** Badge store, purchases, own profile and showcase (api-contract.md §8). Students only. */
final class BadgeController
{
    public function __construct(private BadgeService $badges)
    {
    }

    public function store(Request $request, AuthContext $ctx): void
    {
        Response::data(200, $this->badges->store($ctx->userId));
    }

    public function purchase(Request $request, AuthContext $ctx, int $badgeId): void
    {
        if ($request->body !== '') {
            $request->jsonObject([]);                                            // no fields accepted; the price is never sent
        }
        Response::data(200, $this->badges->purchase($ctx->userId, $badgeId));
    }

    public function profile(Request $request, AuthContext $ctx): void
    {
        Response::data(200, $this->badges->profile($ctx->userId));
    }

    public function setShowcase(Request $request, AuthContext $ctx): void
    {
        Response::data(200, $this->badges->setShowcase($ctx->userId, $request->jsonObject(['badge_ids'])));
    }
}
