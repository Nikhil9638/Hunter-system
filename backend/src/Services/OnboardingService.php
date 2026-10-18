<?php

declare(strict_types=1);

namespace Hunter\Services;

use Hunter\Db\Database;
use Hunter\Http\ApiError;
use Hunter\Repositories\StudentRepository;

/** Experience-level selection (C-04, C-05): self-description only, never used for grading. */
final class OnboardingService
{
    public function __construct(private Database $db, private StudentRepository $students)
    {
    }

    /** @return list<array<string,mixed>> */
    public function levels(): array
    {
        return array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'code' => $r['code'], 'label' => $r['label']],
            $this->students->experienceLevels());
    }

    public function chooseExperience(int $userId, array $input): array
    {
        $id = $input['experience_level_id'] ?? null;
        if (!is_int($id) || $id < 1) {
            throw ApiError::validation(['experience_level_id' => $id === null ? 'required' : 'invalid']);
        }
        return $this->db->tx(function () use ($userId, $id): array {
            $profile = $this->students->lockProfile($userId) ?? throw ApiError::forbidden();
            if ($profile['onboarding_state'] !== 'not_started') {
                throw new ApiError(409, 'experience_already_chosen', 'The experience level has already been chosen.');
            }
            if (!$this->students->experienceLevelExists($id)) {
                throw ApiError::validation(['experience_level_id' => 'invalid']);
            }
            $this->students->setExperience($userId, $id);
            return ['onboarding_state' => 'experience_chosen'];
        });
    }
}
