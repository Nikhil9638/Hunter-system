<?php

declare(strict_types=1);

namespace Hunter\Controllers\Admin;

use Hunter\Http\Request;
use Hunter\Http\Response;
use Hunter\Services\AuditService;
use Hunter\Services\AuthContext;
use Hunter\Services\CatalogueService;
use Hunter\Services\ContentService;

/** Administrator content management and audit history (api-contract.md §10). Admin only; mutations need CSRF. */
final class ContentController
{
    private const QUESTION_FIELDS = ['topic_id', 'for_quiz', 'for_assessment', 'difficulty', 'stem', 'code_snippet', 'explanation', 'choices'];

    public function __construct(private ContentService $content, private CatalogueService $catalogue, private AuditService $audit)
    {
    }

    // ---- topics and lessons
    public function topics(Request $r, AuthContext $ctx): void
    {
        Response::data(200, $this->content->topics());
    }

    public function patchTopic(Request $r, AuthContext $ctx, int $topicId): void
    {
        Response::data(200, $this->content->patchTopic($ctx->userId, $topicId, $r->jsonObject(['expected_version', 'summary', 'status'])));
    }

    public function lesson(Request $r, AuthContext $ctx, int $topicId): void
    {
        Response::data(200, $this->content->lesson($topicId));
    }

    public function saveLesson(Request $r, AuthContext $ctx, int $topicId): void
    {
        $res = $this->content->saveLesson($ctx->userId, $topicId, $r->jsonObject(['expected_revision', 'title', 'body_markdown']));
        Response::data($res['created'] ? 201 : 200, $res['lesson']);
    }

    public function publishLesson(Request $r, AuthContext $ctx, int $topicId): void
    {
        Response::data(200, $this->content->lessonStatus($ctx->userId, $topicId, 'published', $r->jsonObject(['expected_revision'])));
    }

    public function unpublishLesson(Request $r, AuthContext $ctx, int $topicId): void
    {
        Response::data(200, $this->content->lessonStatus($ctx->userId, $topicId, 'draft', $r->jsonObject(['expected_revision'])));
    }

    public function retireLesson(Request $r, AuthContext $ctx, int $topicId): void
    {
        Response::data(200, $this->content->lessonStatus($ctx->userId, $topicId, 'retired', $r->jsonObject(['expected_revision'])));
    }

    // ---- questions
    public function questions(Request $r, AuthContext $ctx): void
    {
        Response::data(200, $this->content->questions($r->query));
    }

    public function question(Request $r, AuthContext $ctx, int $id): void
    {
        Response::data(200, $this->content->question($id));
    }

    public function createQuestion(Request $r, AuthContext $ctx): void
    {
        Response::data(201, $this->content->createQuestion($ctx->userId, $r->jsonObject(self::QUESTION_FIELDS)));
    }

    public function updateQuestion(Request $r, AuthContext $ctx, int $id): void
    {
        Response::data(200, $this->content->updateQuestion($ctx->userId, $id, $r->jsonObject([...self::QUESTION_FIELDS, 'expected_revision'])));
    }

    public function activateQuestion(Request $r, AuthContext $ctx, int $id): void
    {
        Response::data(200, $this->content->activateQuestion($ctx->userId, $id, $r->jsonObject(['expected_revision'])));
    }

    public function retireQuestion(Request $r, AuthContext $ctx, int $id): void
    {
        Response::data(200, $this->content->retireQuestion($ctx->userId, $id, $r->jsonObject(['expected_revision', 'confirm'])));
    }

    // ---- badges
    public function badges(Request $r, AuthContext $ctx): void
    {
        Response::data(200, $this->catalogue->all());
    }

    public function createBadge(Request $r, AuthContext $ctx): void
    {
        Response::data(201, $this->catalogue->create($ctx->userId, $r->jsonObject(['slug', 'name', 'description', 'image_ref', 'price'])));
    }

    public function updateBadge(Request $r, AuthContext $ctx, int $id): void
    {
        Response::data(200, $this->catalogue->update($ctx->userId, $id, $r->jsonObject(['expected_version', 'name', 'description', 'image_ref', 'price'])));
    }

    public function approvePrice(Request $r, AuthContext $ctx, int $id): void
    {
        Response::data(200, $this->catalogue->approvePrice($ctx->userId, $id, $r->jsonObject(['expected_version', 'price'])));
    }

    public function badgeStatus(Request $r, AuthContext $ctx, int $id): void
    {
        Response::data(200, $this->catalogue->setStatus($ctx->userId, $id, $r->jsonObject(['expected_version', 'status'])));
    }

    // ---- audit
    public function auditLog(Request $r, AuthContext $ctx): void
    {
        Response::data(200, $this->audit->page($r->query));
    }
}
