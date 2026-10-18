<?php

declare(strict_types=1);

namespace Hunter\Controllers;

use Hunter\Http\Request;
use Hunter\Http\Response;
use Hunter\Services\AssessmentService;
use Hunter\Services\AuthContext;
use Hunter\Services\DashboardService;
use Hunter\Services\OnboardingService;

/** Onboarding, placement assessment, dashboard and topic list (api-contract.md §3-§5). Students only. */
final class StudentController
{
    public function __construct(
        private OnboardingService $onboarding,
        private AssessmentService $assessment,
        private DashboardService $dashboard,
    ) {
    }

    public function experienceLevels(Request $request, AuthContext $ctx): void
    {
        Response::data(200, $this->onboarding->levels());
    }

    public function chooseExperience(Request $request, AuthContext $ctx): void
    {
        Response::data(200, $this->onboarding->chooseExperience($ctx->userId, $request->jsonObject(['experience_level_id'])));
    }

    public function startAssessment(Request $request, AuthContext $ctx): void
    {
        if ($request->body !== '') {
            $request->jsonObject([]);                                            // no fields accepted
        }
        $r = $this->assessment->start($ctx->userId);
        Response::data($r['created'] ? 201 : 200, $r['attempt'] + ['resumed' => !$r['created']]);
    }

    public function saveAnswer(Request $request, AuthContext $ctx, int $attemptId, int $position): void
    {
        Response::data(200, $this->assessment->answer($ctx->userId, $attemptId, $position, $request->jsonObject(['choice_id'])));
    }

    public function submitAssessment(Request $request, AuthContext $ctx, int $attemptId): void
    {
        if ($request->body !== '') {
            $request->jsonObject([]);
        }
        Response::data(200, $this->assessment->submit($ctx->userId, $attemptId));
    }

    public function assessmentResult(Request $request, AuthContext $ctx): void
    {
        Response::data(200, $this->assessment->result($ctx->userId));
    }

    public function dashboard(Request $request, AuthContext $ctx): void
    {
        Response::data(200, $this->dashboard->dashboard($ctx->userId));
    }

    public function topics(Request $request, AuthContext $ctx): void
    {
        Response::data(200, $this->dashboard->topics($ctx->userId));
    }
}
