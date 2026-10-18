<?php
/**
 * Hunter System - CLI verification of the proposed PHP transactions (schema 2026.10.14-3).
 *
 * NOT the backend. It exercises the transaction designs documented in
 * docs/database-design.md (section "Transactions") against a real database,
 * including true concurrency using separate PHP processes.
 *
 * It COMMITS test records (and adds test-only content for topic 3), and history
 * tables are append-only, so it only runs against a disposable database whose
 * name ends in "_verify" (create it from schema.sql + seed.sql; drop it afterwards).
 *
 * Usage (password, if any, comes from the HUNTER_DB_PASSWORD environment variable):
 *   php database/tests/transactions_check.php --db=hunter_system_verify [--host=127.0.0.1] [--port=3306] [--user=root]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$opt = getopt('', ['host::', 'port::', 'user::', 'db:', 'worker::', 'args::', 'start-at::', 'hold-ms::']);
$cfg = [
    'host' => $opt['host'] ?? '127.0.0.1',
    'port' => (int)($opt['port'] ?? 3306),
    'user' => $opt['user'] ?? 'root',
    'pass' => getenv('HUNTER_DB_PASSWORD') !== false ? (string)getenv('HUNTER_DB_PASSWORD') : '',
    'db'   => $opt['db'] ?? '',
];
if (!preg_match('/^[A-Za-z0-9_]+_verify$/', $cfg['db'])) {
    fwrite(STDERR, "Refusing to run: --db must name a disposable database ending in _verify.\n");
    exit(2);
}

// ---------------------------------------------------------------------------
// Connection and helpers
// ---------------------------------------------------------------------------
function connect(array $cfg): PDO
{
    $pdo = new PDO(
        "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['db']};charset=utf8mb4",
        $cfg['user'],
        $cfg['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec("SET SESSION time_zone = '+00:00'");
    $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'");
    // Locking reads + READ COMMITTED: every read after acquiring a lock sees the latest committed data.
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    return $pdo;
}

function one(PDO $pdo, string $sql, array $params = []): ?array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function all(PDO $pdo, string $sql, array $params = []): array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function run(PDO $pdo, string $sql, array $params = []): int
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

function isDuplicateKey(PDOException $e): bool
{
    return ($e->errorInfo[1] ?? 0) === 1062;
}

/** Run $fn inside a transaction; roll back on any error. */
function tx(PDO $pdo, callable $fn): mixed
{
    $pdo->beginTransaction();
    try {
        $result = $fn();
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * LOCK ORDER (every write path): 1) student_profiles row, 2) attempts row, 3) other rows.
 * Locking the student row first serializes all work for one student (coins,
 * attempts, answers, rank) and keeps the order identical everywhere, so two
 * requests can wait for each other but never deadlock.
 */
function lockStudent(PDO $pdo, int $userId): array
{
    $row = one($pdo, 'SELECT * FROM student_profiles WHERE user_id = ? FOR UPDATE', [$userId]);
    if ($row === null) {
        throw new DomainException('not_a_student');
    }
    return $row;
}

function lockAttempt(PDO $pdo, int $userId, int $attemptId): array
{
    $a = one($pdo, 'SELECT * FROM attempts WHERE id = ? AND user_id = ? FOR UPDATE', [$attemptId, $userId]);
    if ($a === null) {
        throw new DomainException('attempt_not_found');
    }
    return $a;
}

function hold(int $ms): void
{
    if ($ms > 0) {
        usleep($ms * 1000);                                                   // test hook: widen the race window
    }
}

// ---------------------------------------------------------------------------
// Proposed transactions
// ---------------------------------------------------------------------------
function createUser(PDO $pdo, string $role, string $label): int
{
    return tx($pdo, function () use ($pdo, $role, $label) {
        // Random throwaway password with the approved algorithm; nothing hardcoded.
        run($pdo, 'INSERT INTO users (role, display_name, email, password_hash) VALUES (?, ?, ?, ?)', [
            $role, "Verify $label", strtolower($label) . '.' . bin2hex(random_bytes(4)) . '@example.invalid',
            password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID),
        ]);
        $id = (int)$pdo->lastInsertId();
        if ($role === 'student') {
            run($pdo, 'INSERT INTO student_profiles (user_id) VALUES (?)', [$id]);
        }
        return $id;
    });
}

function chooseExperience(PDO $pdo, int $userId, int $levelId): void
{
    tx($pdo, function () use ($pdo, $userId, $levelId) {
        $p = lockStudent($pdo, $userId);
        if ($p['onboarding_state'] !== 'not_started') {
            throw new DomainException('experience_already_chosen');
        }
        run($pdo, "UPDATE student_profiles SET experience_level_id = ?, experience_chosen_at = UTC_TIMESTAMP(3),
                   onboarding_state = 'experience_chosen', updated_at = UTC_TIMESTAMP(3) WHERE user_id = ?", [$levelId, $userId]);
    });
}

/** Copy one question and its choices into an attempt (the snapshot). */
function snapshotQuestion(PDO $pdo, int $attemptId, int $position, array $q): void
{
    run($pdo, 'INSERT INTO attempt_questions (attempt_id, position, question_id, question_revision, topic_id, skill_group_id,
               stem, code_snippet, explanation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $attemptId, $position, $q['id'], $q['revision'], $q['topic_id'], $q['skill_group_id'],
        $q['stem'], $q['code_snippet'], $q['explanation'],
    ]);
    $aqId = (int)$pdo->lastInsertId();
    run($pdo, 'INSERT INTO attempt_question_choices (attempt_question_id, source_choice_id, label, choice_text, is_correct)
               SELECT ?, id, label, choice_text, is_correct FROM question_choices WHERE question_id = ? ORDER BY label', [$aqId, $q['id']]);
}

function startAssessment(PDO $pdo, int $userId): int
{
    return tx($pdo, function () use ($pdo, $userId) {
        $p = lockStudent($pdo, $userId);
        if ($p['placement_attempt_id'] !== null) {
            throw new DomainException('placement_already_recorded');          // one official placement
        }
        if ($p['onboarding_state'] !== 'experience_chosen') {
            throw new DomainException('choose_experience_first');
        }
        $open = one($pdo, "SELECT id FROM attempts WHERE user_id = ? AND kind = 'assessment' AND status = 'in_progress'", [$userId]);
        if ($open) {
            return (int)$open['id'];                                          // resume
        }
        $policy = one($pdo, 'SELECT id FROM assessment_policies WHERE is_current = 1');
        $quota = [];
        foreach (all($pdo, 'SELECT skill_group_id, question_count FROM assessment_group_quotas WHERE policy_id = ?', [$policy['id']]) as $r) {
            $quota[(int)$r['skill_group_id']] = (int)$r['question_count'];
        }
        $pool = all($pdo, "SELECT q.id, q.revision, q.topic_id, t.skill_group_id, q.stem, q.code_snippet, q.explanation
                             FROM questions q JOIN topics t ON t.id = q.topic_id JOIN skill_groups g ON g.id = t.skill_group_id
                            WHERE q.for_assessment = 1 AND q.status = 'active'
                            ORDER BY g.sort_order, RAND()");
        $picked = [];
        foreach ($pool as $q) {
            $g = (int)$q['skill_group_id'];
            if (($quota[$g] ?? 0) > 0) {
                $picked[] = $q;
                $quota[$g]--;
            }
        }
        if (count($picked) !== 10 || array_sum($quota) !== 0) {
            throw new RuntimeException('assessment_pool_insufficient');
        }
        run($pdo, "INSERT INTO attempts (user_id, kind, attempt_number, question_count) VALUES (?, 'assessment', 1, 10)", [$userId]);
        $attemptId = (int)$pdo->lastInsertId();
        foreach ($picked as $i => $q) {
            snapshotQuestion($pdo, $attemptId, $i + 1, $q);
        }
        return $attemptId;
    });
}

function startQuiz(PDO $pdo, int $userId, int $topicId, string $via): int
{
    try {
        return tx($pdo, function () use ($pdo, $userId, $topicId, $via) {
            lockStudent($pdo, $userId);
            $ready = one($pdo, 'SELECT is_ready FROM v_topic_readiness WHERE topic_id = ?', [$topicId]);
            if (!$ready || (int)$ready['is_ready'] !== 1) {
                throw new DomainException('topic_content_not_available');
            }
            $prog = one($pdo, 'SELECT unlocked_at FROM student_topic_progress WHERE user_id = ? AND topic_id = ?', [$userId, $topicId]);
            if (!$prog || $prog['unlocked_at'] === null) {
                throw new DomainException('topic_locked');                    // I-08
            }
            $open = one($pdo, "SELECT id FROM attempts WHERE user_id = ? AND kind = 'topic_quiz' AND topic_id = ? AND status = 'in_progress'", [$userId, $topicId]);
            if ($open) {
                return (int)$open['id'];                                      // resume (I-07)
            }
            // Prefer questions this student has not seen in this topic (approved).
            $picked = all($pdo, "SELECT q.id, q.revision, q.topic_id, t.skill_group_id, q.stem, q.code_snippet, q.explanation
                                   FROM questions q JOIN topics t ON t.id = q.topic_id
                                   LEFT JOIN (SELECT DISTINCT aq.question_id FROM attempt_questions aq
                                                JOIN attempts a ON a.id = aq.attempt_id
                                               WHERE a.user_id = ? AND a.topic_id = ?) seen ON seen.question_id = q.id
                                  WHERE q.topic_id = ? AND q.for_quiz = 1 AND q.status = 'active'
                                  ORDER BY seen.question_id IS NOT NULL, RAND() LIMIT 5", [$userId, $topicId, $topicId]);
            if (count($picked) !== 5) {
                throw new RuntimeException('quiz_pool_insufficient');
            }
            $n = one($pdo, "SELECT COALESCE(MAX(attempt_number), 0) + 1 AS n FROM attempts WHERE user_id = ? AND kind = 'topic_quiz' AND topic_id = ?", [$userId, $topicId]);
            run($pdo, "INSERT INTO attempts (user_id, kind, topic_id, attempt_number, question_count, started_via) VALUES (?, 'topic_quiz', ?, ?, 5, ?)",
                [$userId, $topicId, $n['n'], $via]);
            $attemptId = (int)$pdo->lastInsertId();
            foreach ($picked as $i => $q) {
                snapshotQuestion($pdo, $attemptId, $i + 1, $q);
            }
            return $attemptId;
        });
    } catch (PDOException $e) {
        if (isDuplicateKey($e)) {                                             // lost a start race: resume the winner's attempt
            $open = one($pdo, "SELECT id FROM attempts WHERE user_id = ? AND kind = 'topic_quiz' AND topic_id = ? AND status = 'in_progress'", [$userId, $topicId]);
            if ($open) {
                return (int)$open['id'];
            }
        }
        throw $e;
    }
}

/**
 * Save one answer. Same lock order as submit (student row, then attempt row),
 * so an answer save and a submit for the same attempt are strictly serialized:
 * either the answer commits first and is graded, or the submit commits first
 * and the answer is refused. The composite FK rejects a choice of another question.
 */
function answer(PDO $pdo, int $userId, int $attemptId, int $position, int $snapshotChoiceId, int $holdMs = 0): string
{
    return tx($pdo, function () use ($pdo, $userId, $attemptId, $position, $snapshotChoiceId, $holdMs) {
        lockStudent($pdo, $userId);
        $a = lockAttempt($pdo, $userId, $attemptId);
        if ($a['status'] !== 'in_progress') {
            return 'attempt_finalized';                                       // answers never change after finalization
        }
        $n = run($pdo, 'UPDATE attempt_questions SET selected_choice_id = ?, answered_at = UTC_TIMESTAMP(3)
                         WHERE attempt_id = ? AND position = ?', [$snapshotChoiceId, $attemptId, $position]);
        if ($n !== 1) {
            throw new DomainException('answer_not_saved');
        }
        hold($holdMs);
        return 'saved';
    });
}

/** Test helper: answer the first $correct positions correctly and the rest incorrectly. */
function answerPattern(PDO $pdo, int $userId, int $attemptId, int $correct): void
{
    foreach (all($pdo, 'SELECT id, position FROM attempt_questions WHERE attempt_id = ? ORDER BY position', [$attemptId]) as $aq) {
        $c = choiceFor($pdo, (int)$aq['id'], (int)$aq['position'] <= $correct);
        answer($pdo, $userId, $attemptId, (int)$aq['position'], $c);
    }
}

function choiceFor(PDO $pdo, int $attemptQuestionId, bool $correct): int
{
    return (int)one($pdo, 'SELECT id FROM attempt_question_choices WHERE attempt_question_id = ? AND is_correct = ? ORDER BY label LIMIT 1',
        [$attemptQuestionId, $correct ? 1 : 0])['id'];
}

/** Grade from the snapshot and return the correct count; caller holds the attempt lock. */
function gradeSnapshot(PDO $pdo, int $attemptId, int $expected): int
{
    $c = one($pdo, 'SELECT COUNT(*) AS assigned, COUNT(selected_choice_id) AS answered FROM attempt_questions WHERE attempt_id = ?', [$attemptId]);
    if ((int)$c['assigned'] !== $expected || (int)$c['answered'] !== $expected) {
        throw new DomainException('attempt_incomplete');                      // I-05
    }
    run($pdo, 'UPDATE attempt_questions aq JOIN attempt_question_choices c ON c.id = aq.selected_choice_id
                  SET aq.is_correct = c.is_correct WHERE aq.attempt_id = ?', [$attemptId]);
    return (int)one($pdo, 'SELECT SUM(is_correct) AS s FROM attempt_questions WHERE attempt_id = ?', [$attemptId])['s'];
}

function submitAssessment(PDO $pdo, int $userId, int $attemptId): array
{
    return tx($pdo, function () use ($pdo, $userId, $attemptId) {
        lockStudent($pdo, $userId);
        $a = lockAttempt($pdo, $userId, $attemptId);
        if ($a['kind'] !== 'assessment') {
            throw new DomainException('attempt_not_found');
        }
        if ($a['status'] === 'submitted') {                                   // I-02: return the stored result
            return ['status' => 'already_submitted'] + one($pdo, 'SELECT * FROM assessment_results WHERE attempt_id = ?', [$attemptId]);
        }
        $policy = one($pdo, 'SELECT * FROM assessment_policies WHERE is_current = 1');
        $correct = gradeSnapshot($pdo, $attemptId, 10);
        $rank = one($pdo, 'SELECT rank_id FROM placement_rank_bands WHERE policy_id = ? AND ? BETWEEN min_correct AND max_correct', [$policy['id'], $correct]);
        $missed = one($pdo, 'SELECT g.id FROM attempt_questions aq JOIN skill_groups g ON g.id = aq.skill_group_id
                              WHERE aq.attempt_id = ? AND aq.is_correct = 0 ORDER BY g.sort_order LIMIT 1', [$attemptId]);
        $topic = $missed
            ? (int)one($pdo, 'SELECT id FROM topics WHERE skill_group_id = ? ORDER BY position LIMIT 1', [$missed['id']])['id']
            : (int)$policy['all_correct_topic_id'];
        // Approved fallback: keep the recommendation; if it is not ready, ALSO offer the nearest earlier ready topic.
        $offered = null;
        if ((int)one($pdo, 'SELECT is_ready FROM v_topic_readiness WHERE topic_id = ?', [$topic])['is_ready'] !== 1) {
            $o = one($pdo, 'SELECT topic_id FROM v_topic_readiness WHERE is_ready = 1 AND position < (SELECT position FROM topics WHERE id = ?)
                            ORDER BY position DESC LIMIT 1', [$topic]);
            $offered = $o ? (int)$o['topic_id'] : null;
        }
        run($pdo, 'INSERT INTO assessment_results (attempt_id, assessment_policy_id, correct_count, placement_rank_id, recommended_topic_id, offered_topic_id)
                   VALUES (?, ?, ?, ?, ?, ?)', [$attemptId, $policy['id'], $correct, $rank['rank_id'], $topic, $offered]);
        run($pdo, "UPDATE attempts SET status = 'submitted', submitted_at = UTC_TIMESTAMP(3) WHERE id = ?", [$attemptId]);
        run($pdo, "INSERT INTO rank_history (user_id, from_rank_id, to_rank_id, reason, evidence_attempt_id) VALUES (?, NULL, ?, 'placement', ?)",
            [$userId, $rank['rank_id'], $attemptId]);
        run($pdo, "UPDATE student_profiles SET placement_attempt_id = ?, placement_rank_id = ?, current_rank_id = ?,
                   onboarding_state = 'assessment_submitted', updated_at = UTC_TIMESTAMP(3) WHERE user_id = ?",
            [$attemptId, $rank['rank_id'], $rank['rank_id'], $userId]);
        // Topics up to the recommended one are open; nothing counts as passed.
        run($pdo, "INSERT INTO student_topic_progress (user_id, topic_id, unlocked_at, unlock_source)
                   SELECT ?, t.id, IF(t.position <= r.position, UTC_TIMESTAMP(3), NULL), IF(t.position <= r.position, 'placement', NULL)
                     FROM topics t CROSS JOIN (SELECT position FROM topics WHERE id = ?) r", [$userId, $topic]);
        return ['status' => 'finalized'] + one($pdo, 'SELECT * FROM assessment_results WHERE attempt_id = ?', [$attemptId]);
    });
}

/**
 * Rank after a pass: highest of current (placement or previously achieved) and
 * the rank earned by distinct topics passed. Records a promotion only when it rises.
 */
function applyRankProgression(PDO $pdo, int $userId, int $evidenceAttemptId): ?array
{
    $passed = (int)one($pdo, 'SELECT topics_passed FROM v_student_topics_passed WHERE user_id = ?', [$userId])['topics_passed'];
    $policy = one($pdo, 'SELECT id FROM rank_progression_policies WHERE is_current = 1');
    $earned = one($pdo, 'SELECT b.rank_id, r.sort_order FROM rank_progression_bands b JOIN ranks r ON r.id = b.rank_id
                          WHERE b.policy_id = ? AND ? BETWEEN b.min_topics_passed AND b.max_topics_passed', [$policy['id'], $passed]);
    $current = one($pdo, 'SELECT r.id, r.sort_order FROM student_profiles p JOIN ranks r ON r.id = p.current_rank_id WHERE p.user_id = ?', [$userId]);
    if ((int)$earned['sort_order'] <= (int)$current['sort_order']) {
        return null;                                                          // never decreases; no change recorded
    }
    run($pdo, "INSERT INTO rank_history (user_id, from_rank_id, to_rank_id, reason, evidence_attempt_id, rank_policy_id, topics_passed)
               VALUES (?, ?, ?, 'promotion', ?, ?, ?)", [$userId, $current['id'], $earned['rank_id'], $evidenceAttemptId, $policy['id'], $passed]);
    run($pdo, 'UPDATE student_profiles SET current_rank_id = ?, updated_at = UTC_TIMESTAMP(3) WHERE user_id = ?', [$earned['rank_id'], $userId]);
    return ['from' => (int)$current['id'], 'to' => (int)$earned['rank_id'], 'topics_passed' => $passed];
}

function submitQuiz(PDO $pdo, int $userId, int $attemptId, int $holdMs = 0): array
{
    return tx($pdo, function () use ($pdo, $userId, $attemptId, $holdMs) {
        lockStudent($pdo, $userId);
        $a = lockAttempt($pdo, $userId, $attemptId);
        hold($holdMs);
        if ($a['kind'] !== 'topic_quiz') {
            throw new DomainException('attempt_not_found');
        }
        if ($a['status'] === 'submitted') {                                   // I-02 / I-04: idempotent duplicate
            return ['status' => 'already_submitted'] + one($pdo, 'SELECT * FROM quiz_results WHERE attempt_id = ?', [$attemptId]);
        }
        $topicId = (int)$a['topic_id'];
        $policy = one($pdo, 'SELECT * FROM quiz_grading_policies WHERE is_current = 1');
        $alreadyPassed = (bool)one($pdo, 'SELECT 1 AS x FROM attempts a JOIN quiz_results r ON r.attempt_id = a.id
                                           WHERE a.user_id = ? AND a.topic_id = ? AND r.passed = 1 LIMIT 1', [$userId, $topicId]);
        $correct = gradeSnapshot($pdo, $attemptId, 5);
        $band = one($pdo, 'SELECT * FROM quiz_grade_bands WHERE policy_id = ? AND correct_count = ?', [$policy['id'], $correct]);
        $passed = $correct >= (int)$policy['pass_mark_correct'] ? 1 : 0;
        run($pdo, 'INSERT INTO quiz_results (attempt_id, grading_policy_id, correct_count, grade_letter, percent, passed) VALUES (?, ?, ?, ?, ?, ?)',
            [$attemptId, $policy['id'], $correct, $band['grade_letter'], $band['percent'], $passed]);
        run($pdo, "UPDATE attempts SET status = 'submitted', submitted_at = UTC_TIMESTAMP(3) WHERE id = ?", [$attemptId]);

        $promotion = null;
        if ($passed) {                                                        // passing unlocks the next topic
            run($pdo, "UPDATE student_topic_progress sp JOIN topics nt ON nt.id = sp.topic_id
                         JOIN topics ct ON ct.id = ? AND nt.position = ct.position + 1
                          SET sp.unlocked_at = UTC_TIMESTAMP(3), sp.unlock_source = 'passed_previous', sp.updated_at = UTC_TIMESTAMP(3)
                        WHERE sp.user_id = ? AND sp.unlocked_at IS NULL", [$topicId, $userId]);
            if (!$alreadyPassed) {
                $promotion = applyRankProgression($pdo, $userId, $attemptId);
            }
        }

        // First-completion reward. UNIQUE(user, topic, type) is the database backstop (I-03).
        $reward = null;
        $claimed = one($pdo, "SELECT id FROM reward_claims WHERE user_id = ? AND topic_id = ? AND reward_type = 'topic_first_completion'", [$userId, $topicId]);
        if (!$claimed) {
            $rp = one($pdo, "SELECT id, coin_amount FROM reward_policies WHERE reward_type = 'topic_first_completion' AND is_current = 1");
            if ($rp) {
                run($pdo, "INSERT INTO reward_claims (user_id, topic_id, reward_type, attempt_id, reward_policy_id, coin_amount)
                           VALUES (?, ?, 'topic_first_completion', ?, ?, ?)", [$userId, $topicId, $attemptId, $rp['id'], $rp['coin_amount']]);
                $claimId = (int)$pdo->lastInsertId();
                $bal = (int)one($pdo, 'SELECT coin_balance FROM student_profiles WHERE user_id = ?', [$userId])['coin_balance'];
                run($pdo, "INSERT INTO coin_ledger (user_id, direction, amount, balance_after, entry_type, reward_claim_id)
                           VALUES (?, 'credit', ?, ?, 'topic_reward', ?)", [$userId, $rp['coin_amount'], $bal + (int)$rp['coin_amount'], $claimId]);
                $reward = (int)$rp['coin_amount'];
            }
        }
        return ['status' => 'finalized', 'correct_count' => $correct, 'grade_letter' => $band['grade_letter'],
                'percent' => (int)$band['percent'], 'passed' => $passed, 'reward' => $reward, 'promotion' => $promotion];
    });
}

function purchaseBadge(PDO $pdo, int $userId, int $badgeId, int $holdMs = 0): array
{
    try {
        return tx($pdo, function () use ($pdo, $userId, $badgeId, $holdMs) {
            $p = lockStudent($pdo, $userId);                                  // I-15: one purchase at a time per student
            hold($holdMs);
            $badge = one($pdo, 'SELECT id, price_coins, status FROM badges WHERE id = ? LOCK IN SHARE MODE', [$badgeId]);
            if (!$badge || $badge['status'] !== 'on_sale') {
                return ['status' => 'not_on_sale'];
            }
            if (one($pdo, 'SELECT id FROM badge_ownership WHERE user_id = ? AND badge_id = ?', [$userId, $badgeId])) {
                return ['status' => 'already_owned'];
            }
            $price = (int)$badge['price_coins'];                              // I-17: price from the database only
            $balance = (int)$p['coin_balance'];
            if ($balance < $price) {
                return ['status' => 'insufficient_coins', 'balance' => $balance, 'price' => $price];
            }
            run($pdo, 'INSERT INTO badge_ownership (user_id, badge_id, price_paid) VALUES (?, ?, ?)', [$userId, $badgeId, $price]);
            $ownId = (int)$pdo->lastInsertId();
            run($pdo, "INSERT INTO coin_ledger (user_id, direction, amount, balance_after, entry_type, badge_ownership_id)
                       VALUES (?, 'debit', ?, ?, 'badge_purchase', ?)", [$userId, $price, $balance - $price, $ownId]);
            return ['status' => 'purchased', 'price_paid' => $price, 'balance' => $balance - $price];
        });
    } catch (PDOException $e) {
        if (isDuplicateKey($e)) {
            return ['status' => 'already_owned'];
        }
        throw $e;
    }
}

function showcase(PDO $pdo, int $userId, int $badgeId): string
{
    try {
        return tx($pdo, function () use ($pdo, $userId, $badgeId) {
            lockStudent($pdo, $userId);
            $limit = (int)one($pdo, 'SELECT showcase_limit FROM app_policies WHERE is_current = 1')['showcase_limit'];
            $used = all($pdo, 'SELECT position FROM badge_showcase WHERE user_id = ? ORDER BY position', [$userId]);
            if (count($used) >= $limit) {
                return 'limit_reached';                                       // I-18: limit is a PHP rule
            }
            $taken = array_map('intval', array_column($used, 'position'));
            $pos = 1;
            while (in_array($pos, $taken, true)) {
                $pos++;
            }
            run($pdo, 'INSERT INTO badge_showcase (user_id, position, badge_id) VALUES (?, ?, ?)', [$userId, $pos, $badgeId]);
            return 'showcased';
        });
    } catch (PDOException $e) {
        return ($e->errorInfo[1] ?? 0) === 1452 ? 'not_owned' : (isDuplicateKey($e) ? 'already_showcased' : throw $e);
    }
}

/** TEST FUNDING ONLY. Administrator coin adjustments are not an interface feature in this version. */
function testCredit(PDO $pdo, int $adminId, int $userId, int $amount): void
{
    tx($pdo, function () use ($pdo, $adminId, $userId, $amount) {
        $p = lockStudent($pdo, $userId);
        run($pdo, "INSERT INTO coin_ledger (user_id, direction, amount, balance_after, entry_type, adjusted_by_admin_id, note)
                   VALUES (?, 'credit', ?, ?, 'adjustment', ?, 'transactions_check.php test funding')",
            [$userId, $amount, (int)$p['coin_balance'] + $amount, $adminId]);
    });
}

/** Create a placed student (experience chosen, assessment with $correct right answers submitted). */
function placedStudent(PDO $pdo, string $label, int $correct): int
{
    $uid = createUser($pdo, 'student', $label);
    chooseExperience($pdo, $uid, 1);
    $att = startAssessment($pdo, $uid);
    answerPattern($pdo, $uid, $att, $correct);
    submitAssessment($pdo, $uid, $att);
    return $uid;
}

/** Start, answer and submit one quiz. */
function takeQuiz(PDO $pdo, int $uid, int $topicId, int $correct): array
{
    $att = startQuiz($pdo, $uid, $topicId, 'direct');
    answerPattern($pdo, $uid, $att, $correct);
    return submitQuiz($pdo, $uid, $att) + ['attempt' => $att];
}

// ---------------------------------------------------------------------------
// Worker mode: one operation in its own process/connection, started at a set instant
// ---------------------------------------------------------------------------
if (isset($opt['worker'])) {
    $args = json_decode((string)$opt['args'], true);
    $pdo = connect($cfg);
    $startAt = (float)$opt['start-at'];
    while (microtime(true) < $startAt) {
        usleep(200);
    }
    $t0 = microtime(true);
    $holdMs = (int)($opt['hold-ms'] ?? 0);
    try {
        $result = match ($opt['worker']) {
            'submit'   => submitQuiz($pdo, $args['user'], $args['attempt'], $holdMs),
            'purchase' => purchaseBadge($pdo, $args['user'], $args['badge'], $holdMs),
            'start'    => ['status' => 'started', 'attempt' => startQuiz($pdo, $args['user'], $args['topic'], 'direct')],
            'answer'   => ['status' => answer($pdo, $args['user'], $args['attempt'], $args['position'], $args['choice'], $holdMs)],
        };
    } catch (Throwable $e) {
        $result = ['status' => 'error', 'error' => $e->getMessage()];
    }
    echo json_encode($result + ['t_start' => $t0, 't_end' => microtime(true)]);
    exit(0);
}

/**
 * Run two workers concurrently. Each spec: [worker, args, delay_ms, hold_ms].
 * Both processes connect first, then start at a shared instant (+ their delay).
 */
function race(array $cfg, array $specA, array $specB): array
{
    $base = microtime(true) + 1.5;
    $procs = [];
    $pipes = [];
    foreach ([$specA, $specB] as $i => [$worker, $args, $delayMs, $holdMs]) {
        $startAt = $base + $delayMs / 1000;
        $cmd = [PHP_BINARY, __FILE__, "--host={$cfg['host']}", "--port={$cfg['port']}", "--user={$cfg['user']}", "--db={$cfg['db']}",
                "--worker=$worker", '--args=' . json_encode($args), "--start-at=$startAt", "--hold-ms=$holdMs"];
        $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    $out = [];
    foreach ($procs as $i => $proc) {
        $stdout = stream_get_contents($pipes[$i][1]);
        $stderr = stream_get_contents($pipes[$i][2]);
        proc_close($proc);
        $out[$i] = json_decode($stdout, true) ?? ['status' => 'worker_failed', 'error' => trim($stdout . ' ' . $stderr)];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Test run
// ---------------------------------------------------------------------------
$results = [];
function check(string $name, bool $ok, string $detail): void
{
    global $results;
    $results[] = [$ok, $name, $detail];
    printf("%-5s %-74s %s\n", $ok ? 'PASS' : 'FAIL', $name, $detail);
}
function timing(array $a, array $b): string
{
    $first = $a['t_end'] <= $b['t_end'] ? $a : $b;
    $second = $first === $a ? $b : $a;
    return sprintf('start gap %.0f ms; second finished %.0f ms after first', abs($a['t_start'] - $b['t_start']) * 1000,
        ($second['t_end'] - $first['t_end']) * 1000);
}
/** No answer of a submitted attempt may be newer than its submission, and grading must match the stored selection. */
function answersSealed(PDO $pdo, int $attemptId): bool
{
    $r = one($pdo, 'SELECT COUNT(*) AS n,
                           SUM(aq.answered_at > a.submitted_at) AS late,
                           SUM(aq.is_correct <> c.is_correct) AS mismatch,
                           (SELECT correct_count FROM quiz_results WHERE attempt_id = a.id) AS stored,
                           SUM(c.is_correct) AS regraded
                      FROM attempts a JOIN attempt_questions aq ON aq.attempt_id = a.id
                      JOIN attempt_question_choices c ON c.id = aq.selected_choice_id
                     WHERE a.id = ? AND a.status = \'submitted\'', [$attemptId]);
    return (int)$r['n'] === 5 && (int)$r['late'] === 0 && (int)$r['mismatch'] === 0 && (int)$r['stored'] === (int)$r['regraded'];
}

$pdo = connect($cfg);
$ver = one($pdo, 'SELECT VERSION() AS v, (SELECT schema_version FROM schema_info WHERE id = 1) AS s');
echo "Server {$ver['v']}  schema {$ver['s']}  database {$cfg['db']}\n\n";
if ($ver['s'] !== '2026.10.14-3') {
    fwrite(STDERR, "Expected schema 2026.10.14-3.\n");
    exit(2);
}

// Test-only badges on sale, with the example prices from AE-4 (not real prices).
$badge = [];
foreach ([['verify-badge-x', 40], ['verify-badge-y', 30], ['verify-badge-z1', 1], ['verify-badge-z2', 1], ['verify-badge-z3', 1]] as [$slug, $price]) {
    run($pdo, "INSERT INTO badges (slug, name, description, image_ref, price_coins, price_status, status)
               VALUES (?, ?, 'transactions_check.php test badge', 'placeholder/test.png', ?, 'approved', 'on_sale')", [$slug . '-' . bin2hex(random_bytes(3)), $slug, $price]);
    $badge[$slug] = (int)$pdo->lastInsertId();
}
$admin = createUser($pdo, 'admin', 'Admin');

// T1 assessment: 4/10 -> D; recommended topic 7 is kept, nearest ready topic 2 offered; resubmit returns stored result
$s1 = createUser($pdo, 'student', 'S1');
chooseExperience($pdo, $s1, 4);                                              // "Master" (AE-8)
$asm = startAssessment($pdo, $s1);
check('T1a starting the assessment again resumes the same attempt', startAssessment($pdo, $s1) === $asm, "attempt $asm");
answerPattern($pdo, $s1, $asm, 4);
$r1 = submitAssessment($pdo, $s1, $asm);
$r2 = submitAssessment($pdo, $s1, $asm);
$prof = one($pdo, 'SELECT p.*, r.code FROM student_profiles p JOIN ranks r ON r.id = p.current_rank_id WHERE user_id = ?', [$s1]);
check('T1b "Master" + 4/10 -> placement D; recommended 7 kept, topic 2 offered',
    $r1['status'] === 'finalized' && $r2['status'] === 'already_submitted' && $prof['code'] === 'D'
    && (int)$r1['recommended_topic_id'] === 7 && (int)$r1['offered_topic_id'] === 2 && $r2['offered_topic_id'] === $r1['offered_topic_id'],
    "rank {$prof['code']}, recommended {$r1['recommended_topic_id']}, offered {$r1['offered_topic_id']}, second call: {$r2['status']}");

// T2 simultaneous duplicate submission (held lock forces overlap)
$q1 = startQuiz($pdo, $s1, 1, 'direct');
answerPattern($pdo, $s1, $q1, 2);
[$a, $b] = race($cfg, ['submit', ['user' => $s1, 'attempt' => $q1], 0, 400], ['submit', ['user' => $s1, 'attempt' => $q1], 0, 400]);
$st = [$a['status'], $b['status']];
sort($st);
$counts = one($pdo, 'SELECT (SELECT COUNT(*) FROM quiz_results WHERE attempt_id = ?) r,
                            (SELECT COUNT(*) FROM reward_claims WHERE user_id = ?) c,
                            (SELECT COUNT(*) FROM coin_ledger WHERE user_id = ?) l,
                            (SELECT coin_balance FROM student_profiles WHERE user_id = ?) bal', [$q1, $s1, $s1, $s1]);
check('T2 two simultaneous submits -> one result, one reward, one ledger credit',
    $st === ['already_submitted', 'finalized'] && $counts['r'] == 1 && $counts['c'] == 1 && $counts['l'] == 1 && $counts['bal'] == 10,
    implode('/', $st) . "; results={$counts['r']} claims={$counts['c']} ledger={$counts['l']} balance={$counts['bal']}; " . timing($a, $b));

// T3 retry: unseen questions preferred, 4/5 passes, no second reward; derived status = passed
$q2 = startQuiz($pdo, $s1, 1, 'lesson_first');
$ov = one($pdo, 'SELECT COUNT(*) n FROM attempt_questions x JOIN attempt_questions y ON y.question_id = x.question_id WHERE x.attempt_id = ? AND y.attempt_id = ?', [$q1, $q2]);
answerPattern($pdo, $s1, $q2, 4);
$r = submitQuiz($pdo, $s1, $q2);
$bal = (int)one($pdo, 'SELECT coin_balance FROM student_profiles WHERE user_id = ?', [$s1])['coin_balance'];
$ts = one($pdo, 'SELECT display_status, completed_attempts, best_correct_count FROM v_student_topic_status WHERE user_id = ? AND topic_id = 1', [$s1]);
check('T3 retry: no repeated questions, B passed, no repeat reward, derived status passed',
    (int)$ov['n'] === 0 && $r['grade_letter'] === 'B' && $r['passed'] === 1 && $r['reward'] === null && $bal === 10
    && $ts['display_status'] === 'passed' && (int)$ts['completed_attempts'] === 2 && (int)$ts['best_correct_count'] === 4,
    "overlap={$ov['n']} grade={$r['grade_letter']} reward=" . var_export($r['reward'], true) . " balance=$bal status={$ts['display_status']} best={$ts['best_correct_count']}");
check('T3b placed D, 1 topic passed (earns E) -> no promotion, rank stays D',
    $r['promotion'] === null && one($pdo, 'SELECT r.code FROM student_profiles p JOIN ranks r ON r.id = p.current_rank_id WHERE user_id = ?', [$s1])['code'] === 'D'
    && (int)one($pdo, "SELECT COUNT(*) n FROM rank_history WHERE user_id = ? AND reason = 'promotion'", [$s1])['n'] === 0,
    'promotion=' . var_export($r['promotion'], true));

// T4 simultaneous quiz starts -> one in-progress attempt
[$a, $b] = race($cfg, ['start', ['user' => $s1, 'topic' => 2], 0, 0], ['start', ['user' => $s1, 'topic' => 2], 0, 0]);
$open = one($pdo, "SELECT COUNT(*) n FROM attempts WHERE user_id = ? AND topic_id = 2 AND status = 'in_progress'", [$s1]);
check('T4 two simultaneous quiz starts -> both get the same single attempt',
    $a['status'] === 'started' && $b['status'] === 'started' && $a['attempt'] === $b['attempt'] && (int)$open['n'] === 1,
    "attempts {$a['attempt']}/{$b['attempt']}; in-progress rows={$open['n']}; " . timing($a, $b));

// T5 incomplete submission refused; locked and not-ready topics refused; foreign choice refused
$q3 = $a['attempt'];
$aq1 = (int)one($pdo, 'SELECT id FROM attempt_questions WHERE attempt_id = ? AND position = 1', [$q3])['id'];
answer($pdo, $s1, $q3, 1, choiceFor($pdo, $aq1, true));
try {
    submitQuiz($pdo, $s1, $q3);
    check('T5a submit with 1 of 5 answers is refused', false, 'was accepted');
} catch (DomainException $e) {
    check('T5a submit with 1 of 5 answers is refused', $e->getMessage() === 'attempt_incomplete', $e->getMessage());
}
$s2 = placedStudent($pdo, 'S2', 0);                                          // 0/10 -> E, only topic 1 open
foreach ([[2, 'topic_locked'], [3, 'topic_content_not_available']] as [$tid, $expect]) {
    try {
        startQuiz($pdo, $s2, $tid, 'direct');
        check("T5b start topic $tid for a new E-rank student is refused", false, 'was accepted');
    } catch (DomainException $e) {
        check("T5b start topic $tid for a new E-rank student is refused", $e->getMessage() === $expect, $e->getMessage());
    }
}
try {
    $aq2 = (int)one($pdo, 'SELECT id FROM attempt_questions WHERE attempt_id = ? AND position = 2', [$q3])['id'];
    answer($pdo, $s1, $q3, 3, choiceFor($pdo, $aq2, true));
    check('T5c answer using a choice from another question is rejected', false, 'was accepted');
} catch (PDOException $e) {
    check('T5c answer using a choice from another question is rejected', ($e->errorInfo[1] ?? 0) === 1452, 'errno ' . ($e->errorInfo[1] ?? '?'));
}
$r = takeQuiz($pdo, $s2, 1, 3);
$t2 = one($pdo, 'SELECT unlock_source FROM student_topic_progress WHERE user_id = ? AND topic_id = 2', [$s2]);
$started = startQuiz($pdo, $s2, 2, 'direct') > 0;
check('T5d 3/5 = C passes; topic 2 unlocked as passed_previous and startable',
    $r['grade_letter'] === 'C' && $r['passed'] === 1 && ($t2['unlock_source'] ?? null) === 'passed_previous' && $started,
    "grade={$r['grade_letter']} unlock_source=" . ($t2['unlock_source'] ?? 'NULL') . ' reward=' . var_export($r['reward'], true));

// T6 answer save racing final submission (same attempt). Lock order student -> attempt in both.
//   (a) answer first (holds its locks), submit 100 ms later: submit must grade the NEW answer.
$qa = startQuiz($pdo, $s1, 2, 'direct');                                     // resumes T4's attempt
answerPattern($pdo, $s1, $qa, 5);
$aqa = (int)one($pdo, 'SELECT id FROM attempt_questions WHERE attempt_id = ? AND position = 1', [$qa])['id'];
$wrong = choiceFor($pdo, $aqa, false);
[$a, $b] = race($cfg, ['answer', ['user' => $s1, 'attempt' => $qa, 'position' => 1, 'choice' => $wrong], 0, 400],
                      ['submit', ['user' => $s1, 'attempt' => $qa], 100, 0]);
$sel = (int)one($pdo, 'SELECT selected_choice_id FROM attempt_questions WHERE id = ?', [$aqa])['selected_choice_id'];
check('T6a answer then submit (overlapping): submit waits and grades the new answer',
    $a['status'] === 'saved' && $b['status'] === 'finalized' && (int)$b['correct_count'] === 4 && $sel === $wrong && answersSealed($pdo, $qa),
    "answer={$a['status']} submit={$b['status']} graded={$b['correct_count']}/5 (was 5/5 before the change); " . timing($a, $b));
//   (b) submit first (holds its locks), answer 100 ms later: the answer must be refused and nothing changes.
$s3 = placedStudent($pdo, 'S3', 0);
$qb = startQuiz($pdo, $s3, 1, 'direct');
answerPattern($pdo, $s3, $qb, 5);
$aqb = (int)one($pdo, 'SELECT id FROM attempt_questions WHERE attempt_id = ? AND position = 1', [$qb])['id'];
$before = (int)one($pdo, 'SELECT selected_choice_id FROM attempt_questions WHERE id = ?', [$aqb])['selected_choice_id'];
[$a, $b] = race($cfg, ['submit', ['user' => $s3, 'attempt' => $qb], 0, 400],
                      ['answer', ['user' => $s3, 'attempt' => $qb, 'position' => 1, 'choice' => choiceFor($pdo, $aqb, false)], 100, 0]);
$after = (int)one($pdo, 'SELECT selected_choice_id FROM attempt_questions WHERE id = ?', [$aqb])['selected_choice_id'];
check('T6b submit then answer (overlapping): answer refused, answers unchanged after finalization',
    $a['status'] === 'finalized' && (int)$a['correct_count'] === 5 && $b['status'] === 'attempt_finalized' && $after === $before && answersSealed($pdo, $qb),
    "submit={$a['status']} graded={$a['correct_count']}/5 answer={$b['status']}; " . timing($a, $b));
//   (c) 6 unheld simultaneous rounds: whichever wins, the final state is consistent and sealed.
$outcomes = [];
$okRounds = 0;
for ($i = 1; $i <= 6; $i++) {
    $u = placedStudent($pdo, "AR$i", 0);
    $att = startQuiz($pdo, $u, 1, 'direct');
    answerPattern($pdo, $u, $att, 5);
    $aq = (int)one($pdo, 'SELECT id FROM attempt_questions WHERE attempt_id = ? AND position = 1', [$att])['id'];
    [$a, $b] = race($cfg, ['answer', ['user' => $u, 'attempt' => $att, 'position' => 1, 'choice' => choiceFor($pdo, $aq, false)], 0, 0],
                          ['submit', ['user' => $u, 'attempt' => $att], 0, 0]);
    $graded = (int)one($pdo, 'SELECT correct_count FROM quiz_results WHERE attempt_id = ?', [$att])['correct_count'];
    $consistent = $b['status'] === 'finalized'
        && (($a['status'] === 'saved' && $graded === 4) || ($a['status'] === 'attempt_finalized' && $graded === 5))
        && answersSealed($pdo, $att);
    $okRounds += (int)$consistent;
    $outcomes[] = $a['status'] === 'saved' ? 'answer-first' : 'submit-first';
}
$order = array_count_values($outcomes);
check('T6c 6 unheld answer/submit races: always consistent and sealed', $okRounds === 6,
    "$okRounds/6 consistent; order observed: " . implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($order), $order)));

// T7 simultaneous purchases of two different badges cannot overspend (AE-4: 50 coins, 40 + 30)
$base = (int)one($pdo, 'SELECT coin_balance FROM student_profiles WHERE user_id = ?', [$s1])['coin_balance'];
testCredit($pdo, $admin, $s1, 50 - $base);                                   // fund to exactly 50
[$a, $b] = race($cfg, ['purchase', ['user' => $s1, 'badge' => $badge['verify-badge-x']], 0, 400],
                      ['purchase', ['user' => $s1, 'badge' => $badge['verify-badge-y']], 0, 400]);
$st = [$a['status'], $b['status']];
sort($st);
$w = one($pdo, "SELECT (SELECT coin_balance FROM student_profiles WHERE user_id = ?) bal,
                       (SELECT COUNT(*) FROM coin_ledger WHERE user_id = ? AND direction = 'debit') debits,
                       (SELECT COUNT(*) FROM badge_ownership WHERE user_id = ?) owned", [$s1, $s1, $s1]);
check('T7 simultaneous X(40)+Y(30) with 50 coins -> one purchase, never negative',
    $st === ['insufficient_coins', 'purchased'] && in_array((int)$w['bal'], [10, 20], true) && $w['debits'] == 1 && $w['owned'] == 1,
    implode('/', $st) . "; balance={$w['bal']} debits={$w['debits']} owned={$w['owned']}; " . timing($a, $b));

// T8 simultaneous purchases of the same badge -> one ownership
testCredit($pdo, $admin, $s2, 50);
$before = (int)one($pdo, 'SELECT coin_balance FROM student_profiles WHERE user_id = ?', [$s2])['coin_balance'];
[$a, $b] = race($cfg, ['purchase', ['user' => $s2, 'badge' => $badge['verify-badge-x']], 0, 400],
                      ['purchase', ['user' => $s2, 'badge' => $badge['verify-badge-x']], 0, 400]);
$st = [$a['status'], $b['status']];
sort($st);
$w = one($pdo, 'SELECT (SELECT coin_balance FROM student_profiles WHERE user_id = ?) bal,
                       (SELECT COUNT(*) FROM badge_ownership WHERE user_id = ?) owned', [$s2, $s2]);
check('T8 simultaneous purchase of the same badge -> one owned, "already owned"',
    $st === ['already_owned', 'purchased'] && (int)$w['bal'] === $before - 40 && (int)$w['owned'] === 1,
    implode('/', $st) . "; balance={$w['bal']} owned={$w['owned']}; " . timing($a, $b));

// T9 5 rounds of unheld simultaneous duplicate submits on fresh students
$okRounds = 0;
for ($i = 1; $i <= 5; $i++) {
    $u = placedStudent($pdo, "R$i", 0);
    $att = startQuiz($pdo, $u, 1, 'direct');
    answerPattern($pdo, $u, $att, 3);
    [$a, $b] = race($cfg, ['submit', ['user' => $u, 'attempt' => $att], 0, 0], ['submit', ['user' => $u, 'attempt' => $att], 0, 0]);
    $c = one($pdo, 'SELECT (SELECT COUNT(*) FROM coin_ledger WHERE user_id = ?) l, (SELECT coin_balance FROM student_profiles WHERE user_id = ?) bal', [$u, $u]);
    $st = [$a['status'], $b['status']];
    sort($st);
    $okRounds += (int)($st === ['already_submitted', 'finalized'] && $c['l'] == 1 && $c['bal'] == 10);
}
check('T9 5 rounds of unheld simultaneous submits -> exactly one reward each', $okRounds === 5, "$okRounds/5 rounds correct");

// T10 showcase: owned only, limit from policy (3); badge retired later stays owned and showcased
foreach (['verify-badge-z1', 'verify-badge-z2', 'verify-badge-z3'] as $slug) {
    purchaseBadge($pdo, $s2, $badge[$slug]);
}
$sc = [showcase($pdo, $s2, $badge['verify-badge-x']), showcase($pdo, $s2, $badge['verify-badge-z1']),
       showcase($pdo, $s2, $badge['verify-badge-z2']), showcase($pdo, $s2, $badge['verify-badge-z3'])];
$notOwned = showcase($pdo, $s1, $badge['verify-badge-z3']);
run($pdo, "UPDATE badges SET status = 'retired', updated_at = UTC_TIMESTAMP(3) WHERE id = ?", [$badge['verify-badge-x']]);
$stillShown = (int)one($pdo, 'SELECT COUNT(*) n FROM badge_showcase WHERE user_id = ? AND badge_id = ?', [$s2, $badge['verify-badge-x']])['n'];
$buyRetired = purchaseBadge($pdo, $s1, $badge['verify-badge-x'])['status'];
check('T10 showcase 3 allowed, 4th and not-owned refused; retired badge stays shown, not buyable',
    $sc === ['showcased', 'showcased', 'showcased', 'limit_reached'] && $notOwned === 'not_owned' && $stillShown === 1 && $buyRetired === 'not_on_sale',
    implode(',', $sc) . "; other student's badge: $notOwned; retired still showcased=$stillShown; buy retired: $buyRetired");

// T11 rank progression through real submits. Test-only content makes topic 3 ready in this _verify database.
run($pdo, "INSERT INTO lessons (topic_id, title, body, status, published_at) VALUES (3, 'Verify lesson', 'test', 'published', UTC_TIMESTAMP(3))");
for ($n = 1; $n <= 10; $n++) {
    run($pdo, "INSERT INTO questions (topic_id, for_quiz, stem, status) VALUES (3, 1, ?, 'active')", ["transactions_check test question $n"]);
    $qid = (int)$pdo->lastInsertId();
    run($pdo, "INSERT INTO question_choices (question_id, label, choice_text, is_correct) VALUES (?, 'A', 'right', 1), (?, 'B', 'wrong', 0)", [$qid, $qid]);
}
run($pdo, "UPDATE topics SET status = 'available', updated_at = UTC_TIMESTAMP(3) WHERE id = 3");
//   E-placed student passes 1, 2, 3 (incl. topics "below" nothing) -> promoted to D on the third pass
$e = placedStudent($pdo, 'RankE', 0);
$p1 = takeQuiz($pdo, $e, 1, 3);
$p2 = takeQuiz($pdo, $e, 2, 3);
$p3 = takeQuiz($pdo, $e, 3, 5);
$rh = one($pdo, "SELECT from_rank_id, to_rank_id, evidence_attempt_id, rank_policy_id, topics_passed FROM rank_history WHERE user_id = ? AND reason = 'promotion'", [$e]);
check('T11a E-placed student passing 3 topics is promoted to D with evidence + policy',
    $p1['promotion'] === null && $p2['promotion'] === null && ($p3['promotion']['to'] ?? 0) === 2
    && $rh && (int)$rh['from_rank_id'] === 1 && (int)$rh['to_rank_id'] === 2 && (int)$rh['evidence_attempt_id'] === $p3['attempt']
    && (int)$rh['rank_policy_id'] === 1 && (int)$rh['topics_passed'] === 3,
    'promotion=' . json_encode($p3['promotion']) . ' evidence attempt=' . ($rh['evidence_attempt_id'] ?? '-'));
//   Retaking a passed topic never promotes again or changes rank
$again = takeQuiz($pdo, $e, 3, 5);
check('T11b passing an already-passed topic again changes nothing',
    $again['promotion'] === null && (int)one($pdo, "SELECT COUNT(*) n FROM rank_history WHERE user_id = ? AND reason = 'promotion'", [$e])['n'] === 1,
    'promotion=' . var_export($again['promotion'], true));
//   C-placed student passes 3 topics (earns D < C) -> stays C, no promotion row
$c = placedStudent($pdo, 'RankC', 5);
$cr = one($pdo, 'SELECT r.code FROM student_profiles p JOIN ranks r ON r.id = p.current_rank_id WHERE user_id = ?', [$c]);
foreach ([1, 2, 3] as $tid) {
    run($pdo, "UPDATE student_topic_progress SET unlocked_at = COALESCE(unlocked_at, UTC_TIMESTAMP(3)), unlock_source = COALESCE(unlock_source, 'placement') WHERE user_id = ? AND topic_id = ?", [$c, $tid]);
    takeQuiz($pdo, $c, $tid, 4);
}
$after = one($pdo, 'SELECT r.code FROM student_profiles p JOIN ranks r ON r.id = p.current_rank_id WHERE user_id = ?', [$c]);
check('T11c C-placed student with 3 topics passed (earns D) keeps C; no promotion row',
    $cr['code'] === 'C' && $after['code'] === 'C' && (int)one($pdo, "SELECT COUNT(*) n FROM rank_history WHERE user_id = ? AND reason = 'promotion'", [$c])['n'] === 0,
    "placement {$cr['code']} -> current {$after['code']}");

// T12 reconciliation across everything this run created
$rec = one($pdo, 'SELECT COUNT(*) n, SUM(cached_balance <> ledger_sum OR cached_balance <> last_balance_after) bad FROM v_wallet_reconciliation');
check('T12 every wallet: cached balance = ledger sum = last running balance', (int)$rec['bad'] === 0, "wallets={$rec['n']} mismatches={$rec['bad']}");

$failed = count(array_filter($results, fn($r) => !$r[0]));
printf("\n%d checks, %d passed, %d failed\n", count($results), count($results) - $failed, $failed);
exit($failed === 0 ? 0 : 1);
