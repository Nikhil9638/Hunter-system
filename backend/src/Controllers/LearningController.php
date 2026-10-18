<?php

declare(strict_types=1);

namespace Hunter\Controllers;

use Hunter\Http\Request;
use Hunter\Http\Response;
use Hunter\Services\AuthContext;
use Hunter\Services\LearningService;

/** Lessons, topic quizzes, rank history and the coin ledger (api-contract.md §5-§7). Students only. */
final class LearningController
{
    public function __construct(private LearningService $learning)
    {
    }

    public function lesson(Request $request, AuthContext $ctx, int $topicId): void
    {
        Response::data(200, $this->learning->lesson($ctx->userId, $topicId));
    }

    public function startQuiz(Request $request, AuthContext $ctx, int $topicId): void
    {
        $r = $this->learning->startQuiz($ctx->userId, $topicId, $request->jsonObject(['started_via']));
        Response::data($r['created'] ? 201 : 200, $r['attempt'] + ['resumed' => !$r['created']]);
    }

    public function attempt(Request $request, AuthContext $ctx, int $attemptId): void
    {
        Response::data(200, $this->learning->attempt($ctx->userId, $attemptId));
    }

    public function saveAnswer(Request $request, AuthContext $ctx, int $attemptId, int $position): void
    {
        Response::data(200, $this->learning->answer($ctx->userId, $attemptId, $position, $request->jsonObject(['choice_id'])));
    }

    public function submit(Request $request, AuthContext $ctx, int $attemptId): void
    {
        if ($request->body !== '') {
            $request->jsonObject([]);                                            // no fields accepted
        }
        Response::data(200, $this->learning->submit($ctx->userId, $attemptId));
    }

    public function topicAttempts(Request $request, AuthContext $ctx, int $topicId): void
    {
        Response::data(200, $this->learning->topicAttempts($ctx->userId, $topicId));
    }

    public function rankHistory(Request $request, AuthContext $ctx): void
    {
        Response::data(200, $this->learning->rankHistory($ctx->userId));
    }

    public function coins(Request $request, AuthContext $ctx): void
    {
        Response::data(200, $this->learning->coins($ctx->userId, $request->query));
    }
}
