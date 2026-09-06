<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Assignment;
use App\Models\CourseSection;
use App\Models\Exam;
use App\Models\Exercise;
use App\Models\LearningOutcome;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\Resource;
use App\Repositories\Contracts\CourseContentRepositoryInterface;

class CourseContentRepository implements CourseContentRepositoryInterface
{
    public function createSection(array $data): CourseSection
    {
        return CourseSection::query()->create($data);
    }

    public function updateSection(CourseSection $section, array $data): CourseSection
    {
        $section->update($data);

        return $section->fresh();
    }

    public function deleteSection(CourseSection $section): bool
    {
        return (bool) $section->delete();
    }

    public function createLesson(array $data): Lesson
    {
        return Lesson::query()->create($data);
    }

    public function updateLesson(Lesson $lesson, array $data): Lesson
    {
        $lesson->update($data);

        return $lesson->fresh();
    }

    public function deleteLesson(Lesson $lesson): bool
    {
        return (bool) $lesson->delete();
    }

    public function createOutcome(array $data): LearningOutcome
    {
        return LearningOutcome::query()->create($data);
    }

    public function updateOutcome(LearningOutcome $outcome, array $data): LearningOutcome
    {
        $outcome->update($data);

        return $outcome->fresh();
    }

    public function deleteOutcome(LearningOutcome $outcome): bool
    {
        return (bool) $outcome->delete();
    }

    public function createResource(array $data): Resource
    {
        return Resource::query()->create($data);
    }

    public function updateResource(Resource $resource, array $data): Resource
    {
        $resource->update($data);

        return $resource->fresh();
    }

    public function deleteResource(Resource $resource): bool
    {
        return (bool) $resource->delete();
    }

    public function createQuiz(array $data): Quiz
    {
        return Quiz::query()->create($data);
    }

    public function updateQuiz(Quiz $quiz, array $data): Quiz
    {
        $quiz->update($data);

        return $quiz->fresh();
    }

    public function deleteQuiz(Quiz $quiz): bool
    {
        return (bool) $quiz->delete();
    }

    public function createExercise(array $data): Exercise
    {
        return Exercise::query()->create($data);
    }

    public function updateExercise(Exercise $exercise, array $data): Exercise
    {
        $exercise->update($data);

        return $exercise->fresh();
    }

    public function deleteExercise(Exercise $exercise): bool
    {
        return (bool) $exercise->delete();
    }

    public function createExam(array $data): Exam
    {
        return Exam::query()->create($data);
    }

    public function updateExam(Exam $exam, array $data): Exam
    {
        $exam->update($data);

        return $exam->fresh();
    }

    public function deleteExam(Exam $exam): bool
    {
        return (bool) $exam->delete();
    }

    public function createAssignment(array $data): Assignment
    {
        return Assignment::query()->create($data);
    }

    public function updateAssignment(Assignment $assignment, array $data): Assignment
    {
        $assignment->update($data);

        return $assignment->fresh();
    }

    public function deleteAssignment(Assignment $assignment): bool
    {
        return (bool) $assignment->delete();
    }
}