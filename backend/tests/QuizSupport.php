<?php
/**
 * Shared helpers for the milestone 3 quiz test suites (CLI only). Expects the
 * including script to define the globals $API, $TMP, $RUN, $PW, $PREFIX and
 * $FORBIDDEN_KEYS. The admin PDO is only used to read answer keys, hold row locks
 * and inspect rows.
 */

declare(strict_types=1);

use Hunter\Tests\Client;

use function Hunter\Tests\allKeys;

$results = [];
function check(string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = $ok;
    printf("%-5s %-88s %s\n", $ok ? 'PASS' : 'FAIL', $name, $detail);
}
function code(array $r): string
{
    return $r['status'] . ' ' . ($r['json']['error']['code'] ?? (isset($r['json']['data']) ? 'data' : substr($r['raw'], 0, 120)));
}
function q(PDO $db, string $sql, array $p = []): ?array
{
    $st = $db->prepare($sql);
    $st->execute($p);
    $r = $st->fetch();
    return $r === false ? null : $r;
}
function qa(PDO $db, string $sql, array $p = []): array
{
    $st = $db->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}
function newStudent(string $tag): Client
{
    global $API, $TMP, $RUN, $PW, $PREFIX;
    $c = new Client($API, $TMP, $tag);
    $r = $c->req('POST', '/auth/register', ['email' => "$PREFIX.$RUN.$tag@example.invalid", 'display_name' => strtoupper($PREFIX) . " $tag", 'password' => $PW]);
    if ($r['status'] !== 201) {
        throw new RuntimeException("register $tag failed: " . code($r));
    }
    return $c;
}
function userId(Client $c): int
{
    return (int)$c->req('GET', '/auth/me')['json']['data']['user']['id'];
}
/** Answer key from the snapshot: position => [right choice id, wrong choice id, alt wrong id, group]. */
function answerKey(PDO $db, int $attemptId): array
{
    $key = [];
    foreach (qa($db, 'SELECT aq.position, aq.skill_group_id, c.id, c.is_correct FROM attempt_questions aq
                       JOIN attempt_question_choices c ON c.attempt_question_id = aq.id
                      WHERE aq.attempt_id = ? ORDER BY aq.position, c.label', [$attemptId]) as $r) {
        $p = (int)$r['position'];
        $key[$p]['group'] = (int)$r['skill_group_id'];
        if ((int)$r['is_correct'] === 1) {
            $key[$p]['right'] = (int)$r['id'];
        } elseif (!isset($key[$p]['wrong'])) {
            $key[$p]['wrong'] = (int)$r['id'];
        } else {
            $key[$p]['wrong2'] ??= (int)$r['id'];
        }
    }
    return $key;
}
/**
 * Experience + placement through the API. Groups listed in $rightGroups are answered
 * correctly, all others wrongly: [] -> E, topic 1 only; [1,2,3] -> C, topics 1-10; all -> S.
 */
function place(Client $c, PDO $db, array $rightGroups): void
{
    $c->req('PUT', '/me/experience', ['experience_level_id' => 1]);
    $aid = (int)$c->req('POST', '/assessment')['json']['data']['attempt_id'];
    foreach (answerKey($db, $aid) as $pos => $k) {
        $c->req('PUT', "/assessment/$aid/answers/$pos", ['choice_id' => in_array($k['group'], $rightGroups, true) ? $k['right'] : $k['wrong']]);
    }
    $r = $c->req('POST', "/assessment/$aid/submit");
    if ($r['status'] !== 200) {
        throw new RuntimeException('placement failed: ' . code($r));
    }
}
function startQuiz(Client $c, int $topic, string $via = 'direct'): array
{
    return $c->req('POST', "/topics/$topic/quiz-attempts", ['started_via' => $via]);
}
/** Answer all 5; exactly $nCorrect of them (the first positions) correctly. */
function answerQuiz(Client $c, PDO $db, int $aid, int $nCorrect): array
{
    $key = answerKey($db, $aid);
    foreach ($key as $pos => $k) {
        $r = $c->req('PUT', "/quiz-attempts/$aid/answers/$pos", ['choice_id' => $pos <= $nCorrect ? $k['right'] : $k['wrong']]);
        if ($r['status'] !== 200) {
            throw new RuntimeException("quiz answer $pos failed: " . code($r));
        }
    }
    return $key;
}
/** Start (or resume), answer with $nCorrect right, submit. Returns [attemptId, submit response]. */
function takeQuiz(Client $c, PDO $db, int $topic, int $nCorrect, string $via = 'direct'): array
{
    $s = startQuiz($c, $topic, $via);
    if (!in_array($s['status'], [200, 201], true)) {
        throw new RuntimeException("start topic $topic failed: " . code($s));
    }
    $aid = (int)$s['json']['data']['attempt_id'];
    answerQuiz($c, $db, $aid, $nCorrect);
    return [$aid, $c->req('POST', "/quiz-attempts/$aid/submit")];
}
function holdProfileLock(PDO $db, int $userId): callable
{
    $db->beginTransaction();
    q($db, 'SELECT user_id FROM student_profiles WHERE user_id = ? FOR UPDATE', [$userId]);
    return static function () use ($db): void {
        $db->commit();
    };
}
function noReveal(array $r): bool
{
    global $FORBIDDEN_KEYS;
    return array_intersect(allKeys($r['json'] ?? null), $FORBIDDEN_KEYS) === [];
}
function topicRow(Client $c, int $topicId): array
{
    foreach ($c->req('GET', '/topics')['json']['data'] ?? [] as $t) {
        if ($t['id'] === $topicId) {
            return $t;
        }
    }
    return [];
}
function counts(PDO $db, int $uid): array
{
    return q($db, "SELECT (SELECT COUNT(*) FROM reward_claims WHERE user_id = ?) claims,
                          (SELECT COUNT(*) FROM coin_ledger WHERE user_id = ?) ledger,
                          (SELECT coin_balance FROM student_profiles WHERE user_id = ?) balance,
                          (SELECT COUNT(*) FROM rank_history WHERE user_id = ?) ranks,
                          (SELECT COUNT(*) FROM quiz_results r JOIN attempts a ON a.id = r.attempt_id WHERE a.user_id = ?) results",
        [$uid, $uid, $uid, $uid, $uid]);
}
