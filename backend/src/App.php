<?php

declare(strict_types=1);

namespace Hunter;

use ErrorException;
use Hunter\Controllers\AuthController;
use Hunter\Controllers\BadgeController;
use Hunter\Controllers\HealthController;
use Hunter\Controllers\LearningController;
use Hunter\Controllers\StudentController;
use Hunter\Db\Database;
use Hunter\Http\ApiError;
use Hunter\Http\Request;
use Hunter\Http\Response;
use Hunter\Http\Router;
use Hunter\Repositories\AdminOverviewRepository;
use Hunter\Repositories\AssessmentRepository;
use Hunter\Repositories\AuditRepository;
use Hunter\Repositories\CatalogueRepository;
use Hunter\Repositories\ContentRepository;
use Hunter\Repositories\BadgeRepository;
use Hunter\Repositories\QuizRepository;
use Hunter\Repositories\StudentRepository;
use Hunter\Repositories\SessionRepository;
use Hunter\Repositories\ThrottleRepository;
use Hunter\Repositories\UserRepository;
use Hunter\Services\AdminTrackingService;
use Hunter\Services\AssessmentService;
use Hunter\Services\AuditService;
use Hunter\Services\CatalogueService;
use Hunter\Services\ContentService;
use Hunter\Services\DashboardService;
use Hunter\Services\LearningService;
use Hunter\Services\OnboardingService;
use Hunter\Services\AuthService;
use Hunter\Services\BadgeService;
use Hunter\Services\LoginThrottle;
use Hunter\Services\SessionManager;
use Hunter\Support\Config;
use Hunter\Support\Logger;
use Hunter\Support\PasswordPolicy;
use Hunter\Support\Text;
use Throwable;

/**
 * HTTP entry: wiring, routes and the request pipeline
 *   route match -> request checks (origin, content type) -> authentication + role
 *   -> CSRF -> controller. Errors become the JSON envelope; unexpected errors are
 *   logged with an error_id and reported as 500 without internals.
 */
final class App
{
    private Database $db;
    private SessionManager $sessions;
    private LoginThrottle $throttle;
    private Router $router;

    private function __construct(private Config $config)
    {
        $this->db = new Database($config);
        $sessionRepo = new SessionRepository($this->db);
        $this->sessions = new SessionManager($config, $sessionRepo);
        $this->throttle = new LoginThrottle($config, new ThrottleRepository($this->db));
        $auth = new AuthService($this->db, new UserRepository($this->db), $sessionRepo, $this->sessions, $this->throttle);
        $authC = new AuthController($auth);
        $health = new HealthController($this->db);
        $adminStudents = new \Hunter\Controllers\Admin\StudentController(new AdminTrackingService($sessionRepo, new AdminOverviewRepository($this->db)));
        $studentRepo = new StudentRepository($this->db);
        $assessmentRepo = new AssessmentRepository($this->db);
        $student = new StudentController(
            new OnboardingService($this->db, $studentRepo),
            new AssessmentService($this->db, $assessmentRepo, $studentRepo),
            new DashboardService($studentRepo, $assessmentRepo),
        );
        $learning = new LearningController(new LearningService($this->db, new QuizRepository($this->db), $assessmentRepo, $studentRepo));
        $badges = new BadgeController(new BadgeService($this->db, new BadgeRepository($this->db), $studentRepo, $config));
        $auditRepo = new AuditRepository($this->db);
        $adminContent = new \Hunter\Controllers\Admin\ContentController(
            new ContentService($this->db, new ContentRepository($this->db), $auditRepo),
            new CatalogueService($this->db, new CatalogueRepository($this->db), $auditRepo, $config),
            new AuditService($auditRepo),
        );

        $r = new Router();
        $r->add('GET', '/health', [$health, 'show']);
        $r->add('POST', '/auth/register', [$authC, 'register']);
        $r->add('POST', '/auth/login', [$authC, 'login']);
        $r->add('POST', '/auth/logout', [$authC, 'logout'], ['auth' => 'any', 'csrf' => true]);
        $r->add('GET', '/auth/me', [$authC, 'me'], ['auth' => 'any']);
        $r->add('GET', '/admin/students', [$adminStudents, 'index'], ['auth' => 'admin']);
        $r->add('GET', '/admin/students/{user}', [$adminStudents, 'show'], ['auth' => 'admin']);
        $r->add('GET', '/admin/students/{user}/logins', [$adminStudents, 'logins'], ['auth' => 'admin']);
        $r->add('GET', '/admin/login-events', [$adminStudents, 'loginEvents'], ['auth' => 'admin']);

        // Milestone 2: onboarding, placement assessment, dashboard, topics (students only)
        $r->add('GET', '/experience-levels', [$student, 'experienceLevels'], ['auth' => 'student']);
        $r->add('PUT', '/me/experience', [$student, 'chooseExperience'], ['auth' => 'student', 'csrf' => true]);
        $r->add('POST', '/assessment', [$student, 'startAssessment'], ['auth' => 'student', 'csrf' => true]);
        $r->add('PUT', '/assessment/{attempt}/answers/{position}', [$student, 'saveAnswer'], ['auth' => 'student', 'csrf' => true]);
        $r->add('POST', '/assessment/{attempt}/submit', [$student, 'submitAssessment'], ['auth' => 'student', 'csrf' => true]);
        $r->add('GET', '/assessment/result', [$student, 'assessmentResult'], ['auth' => 'student']);
        $r->add('GET', '/me/dashboard', [$student, 'dashboard'], ['auth' => 'student']);
        $r->add('GET', '/topics', [$student, 'topics'], ['auth' => 'student']);

        // Milestone 3: lessons, topic quizzes, rewards, progression, rank (students only)
        $r->add('GET', '/topics/{topic}/lesson', [$learning, 'lesson'], ['auth' => 'student']);
        $r->add('POST', '/topics/{topic}/quiz-attempts', [$learning, 'startQuiz'], ['auth' => 'student', 'csrf' => true]);
        $r->add('GET', '/topics/{topic}/quiz-attempts', [$learning, 'topicAttempts'], ['auth' => 'student']);
        $r->add('GET', '/quiz-attempts/{attempt}', [$learning, 'attempt'], ['auth' => 'student']);
        $r->add('PUT', '/quiz-attempts/{attempt}/answers/{position}', [$learning, 'saveAnswer'], ['auth' => 'student', 'csrf' => true]);
        $r->add('POST', '/quiz-attempts/{attempt}/submit', [$learning, 'submit'], ['auth' => 'student', 'csrf' => true]);
        $r->add('GET', '/me/rank-history', [$learning, 'rankHistory'], ['auth' => 'student']);
        $r->add('GET', '/me/coins', [$learning, 'coins'], ['auth' => 'student']);

        // Milestone 4: badge store, purchases, own profile and showcase (students only)
        $r->add('GET', '/badges', [$badges, 'store'], ['auth' => 'student']);
        $r->add('POST', '/badges/{badge}/purchase', [$badges, 'purchase'], ['auth' => 'student', 'csrf' => true]);
        $r->add('GET', '/me/profile', [$badges, 'profile'], ['auth' => 'student']);
        $r->add('PUT', '/me/showcase', [$badges, 'setShowcase'], ['auth' => 'student', 'csrf' => true]);

        // Milestone 5: administrator content management and audit history (admins only; mutations need CSRF)
        $admin = ['auth' => 'admin'];
        $adminW = ['auth' => 'admin', 'csrf' => true];
        $r->add('GET', '/admin/topics', [$adminContent, 'topics'], $admin);
        $r->add('PATCH', '/admin/topics/{topic}', [$adminContent, 'patchTopic'], $adminW);
        $r->add('GET', '/admin/topics/{topic}/lesson', [$adminContent, 'lesson'], $admin);
        $r->add('PUT', '/admin/topics/{topic}/lesson', [$adminContent, 'saveLesson'], $adminW);
        $r->add('POST', '/admin/topics/{topic}/lesson/publish', [$adminContent, 'publishLesson'], $adminW);
        $r->add('POST', '/admin/topics/{topic}/lesson/unpublish', [$adminContent, 'unpublishLesson'], $adminW);
        $r->add('POST', '/admin/topics/{topic}/lesson/retire', [$adminContent, 'retireLesson'], $adminW);
        $r->add('GET', '/admin/questions', [$adminContent, 'questions'], $admin);
        $r->add('POST', '/admin/questions', [$adminContent, 'createQuestion'], $adminW);
        $r->add('GET', '/admin/questions/{question}', [$adminContent, 'question'], $admin);
        $r->add('PUT', '/admin/questions/{question}', [$adminContent, 'updateQuestion'], $adminW);
        $r->add('POST', '/admin/questions/{question}/activate', [$adminContent, 'activateQuestion'], $adminW);
        $r->add('POST', '/admin/questions/{question}/retire', [$adminContent, 'retireQuestion'], $adminW);
        $r->add('GET', '/admin/badges', [$adminContent, 'badges'], $admin);
        $r->add('POST', '/admin/badges', [$adminContent, 'createBadge'], $adminW);
        $r->add('PUT', '/admin/badges/{badge}', [$adminContent, 'updateBadge'], $adminW);
        $r->add('POST', '/admin/badges/{badge}/approve-price', [$adminContent, 'approvePrice'], $adminW);
        $r->add('POST', '/admin/badges/{badge}/status', [$adminContent, 'badgeStatus'], $adminW);
        $r->add('GET', '/admin/audit-log', [$adminContent, 'auditLog'], $admin);
        $this->router = $r;
    }

    public static function handleHttp(): void
    {
        ini_set('display_errors', '0');
        ini_set('log_errors', '0');
        error_reporting(E_ALL);
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $config = Config::load();
            $config->assertComplete();
            (new self($config))->handle(Request::fromGlobals($config->basePath() . '/api/v1'));
        } catch (ApiError $e) {
            Response::error($e);
        } catch (Throwable $e) {
            $id = bin2hex(random_bytes(8));
            Logger::error($e, $id);
            Response::error(new ApiError(500, 'internal_error', 'Something went wrong. Please try again.', ['error_id' => $id]));
        }
    }

    private function handle(Request $request): void
    {
        [$handler, $params, $options] = $this->router->match($request->method, $request->path);

        if ($request->isStateChanging()) {
            $this->assertOrigin($request);
            if ($request->body !== '' && !$this->isJson($request)) {
                throw new ApiError(415, 'unsupported_media_type', 'Send the request body as application/json.');
            }
        }
        if ($request->path !== '/health') {
            if (!Text::intlAvailable() || !PasswordPolicy::argon2idAvailable()) {
                throw new ApiError(503, 'service_unavailable', 'The service is not correctly configured.');   // fail closed
            }
            $this->throttle->purgeExpired();                                     // bounded retention, every request
        }

        $ctx = null;
        if ($options['auth'] !== null) {
            $ctx = $this->sessions->authenticate($request);
            if ($options['auth'] !== 'any' && $ctx->role !== $options['auth']) {
                throw ApiError::forbidden();
            }
            if ($options['csrf']) {
                $this->sessions->assertCsrf($request, $ctx);
            }
        }
        $ctx === null ? $handler($request, ...$params) : $handler($request, $ctx, ...$params);
    }

    /** A browser Origin, when present, must be one of ours (cross-site requests are refused). */
    private function assertOrigin(Request $request): void
    {
        $origin = $request->header('Origin');
        if ($origin !== null && !in_array($origin, $this->config->allowedOrigins(), true)) {
            throw new ApiError(403, 'csrf_failed', 'Cross-site requests are not allowed.');
        }
    }

    private function isJson(Request $request): bool
    {
        $type = strtolower(trim(explode(';', (string)$request->header('Content-Type'))[0]));
        return $type === 'application/json';
    }
}
