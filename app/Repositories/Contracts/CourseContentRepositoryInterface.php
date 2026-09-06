<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Assignment;
use App\Models\CourseSection;
use App\Models\Exam;
use App\Models\Exercise;
use App\Models\LearningOutcome;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\Resource;

interface CourseContentRepositoryInterface
{
    public function createSection(array $data): CourseSection;

    public function updateSection(CourseSection $section, array $data): CourseSection;

    public function deleteSection(CourseSection $section): bool;

    public function createLesson(array $data): Lesson;

    public function updateLesson(Lesson $lesson, array $data): Lesson;

    public function deleteLesson(Lesson $lesson): bool;

    public function createOutcome(array $data): LearningOutcome;

    public function updateOutcome(LearningOutcome $outcome, array $data): LearningOutcome;

    public function deleteOutcome(LearningOutcome $outcome): bool;

    public function createResource(array $data): Resource;

    public function updateResource(Resource $resource, array $data): Resource;

    public function deleteResource(Resource $resource): bool;

    public function createQuiz(array $data): Quiz;

    public function updateQuiz(Quiz $quiz, array $data): Quiz;

    public function deleteQuiz(Quiz $quiz): bool;

    public function createExercise(array $data): Exercise;

    public function updateExercise(Exercise $exercise, array $data): Exercise;

    public function deleteExercise(Exercise $exercise): bool;

    public function createExam(array $data): Exam;

    public function updateExam(Exam $exam, array $data): Exam;

    public function deleteExam(Exam $exam): bool;

    public function createAssignment(array $data): Assignment;

    public function updateAssignment(Assignment $assignment, array $data): Assignment;

    public function deleteAssignment(Assignment $assignment): bool;
}