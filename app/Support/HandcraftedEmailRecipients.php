<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class HandcraftedEmailRecipients
{
    private const int MINIMUM_SEARCH_LENGTH = 3;

    private const string STUDENT_PREFIX = 'student:';

    private const string TEACHER_PREFIX = 'teacher:';

    /**
     * @param  array<int, Student|User|string>  $recipients
     * @return array<int, string>
     */
    public function defaultValues(array $recipients): array
    {
        $values = [];

        foreach ($recipients as $recipient) {
            $value = match (true) {
                $recipient instanceof Student => $this->studentToken($recipient->getKey()),
                $recipient instanceof User && $recipient->hasRole('teacher') => $this->teacherToken($recipient->getKey()),
                $recipient instanceof User => $recipient->email,
                default => $recipient,
            };

            if (! $this->isValidValue($value = mb_trim($value))) {
                continue;
            }

            $values[] = $value;
        }

        return $this->uniqueStrings($values);
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function search(string $search, ?User $sender = null, ?Student $permittedStudent = null): array
    {
        $search = (string) str($search)->squish();

        if (mb_strlen($search) < self::MINIMUM_SEARCH_LENGTH) {
            return [];
        }

        $students = $this->applyNameSearch($this->studentQuery($sender, $permittedStudent), $search)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(25)
            ->get()
            ->mapWithKeys(fn (Student $student): array => [
                $this->studentToken($student->getKey()) => $this->fullName($student),
            ])
            ->all();

        $teachers = $this->applyNameSearch(User::query()->role('teacher'), $search)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(25)
            ->get()
            ->mapWithKeys(fn (User $teacher): array => [
                $this->teacherToken($teacher->getKey()) => $this->fullName($teacher),
            ])
            ->all();

        $emailAddresses = filter_var($search, FILTER_VALIDATE_EMAIL)
            ? [$search => $search]
            : [];

        return array_filter([
            'Students' => $students,
            'Teachers' => $teachers,
            'Email address' => $emailAddresses,
        ]);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<string, string>
     */
    public function labels(array $values, ?User $sender = null, ?Student $permittedStudent = null): array
    {
        $values = $this->stringValues($values);
        $students = $this->studentQuery($sender, $permittedStudent)
            ->whereKey($this->studentIds($values))
            ->get()
            ->keyBy('id');
        $teachers = User::query()
            ->role('teacher')
            ->whereKey($this->teacherIds($values))
            ->get()
            ->keyBy('id');
        $labels = [];

        foreach ($values as $value) {
            if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $labels[$value] = $value;

                continue;
            }

            if (($studentId = $this->tokenId($value, self::STUDENT_PREFIX)) !== null && $students->has($studentId)) {
                $labels[$value] = $this->fullName($students->get($studentId));

                continue;
            }

            if (($teacherId = $this->tokenId($value, self::TEACHER_PREFIX)) !== null && $teachers->has($teacherId)) {
                $labels[$value] = $this->fullName($teachers->get($teacherId));
            }
        }

        return $labels;
    }

    /**
     * @return array<int, string>
     */
    public function resolve(mixed $values, ?User $sender = null, ?Student $permittedStudent = null): array
    {
        $values = $this->stringValues($values);
        $students = $this->studentQuery($sender, $permittedStudent)
            ->with('additionalEmails')
            ->whereKey($this->studentIds($values))
            ->get()
            ->keyBy('id');
        $studentUserEmails = User::query()
            ->whereKey($students->pluck('user_id')->filter()->all())
            ->pluck('email', 'id');
        $teacherEmails = User::query()
            ->role('teacher')
            ->whereKey($this->teacherIds($values))
            ->pluck('email', 'id');
        $emailAddresses = [];

        foreach ($values as $value) {
            if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $emailAddresses[] = $value;

                continue;
            }

            if (($studentId = $this->tokenId($value, self::STUDENT_PREFIX)) !== null && $students->has($studentId)) {
                $student = $students->get($studentId);

                if (is_string($studentUserEmail = $studentUserEmails->get($student->user_id))) {
                    $emailAddresses[] = $studentUserEmail;
                }

                $emailAddresses = [
                    ...$emailAddresses,
                    ...$student->additionalEmails->pluck('email')->all(),
                ];

                continue;
            }

            if (($teacherId = $this->tokenId($value, self::TEACHER_PREFIX)) !== null && is_string($teacherEmail = $teacherEmails->get($teacherId))) {
                $emailAddresses[] = $teacherEmail;
            }
        }

        return $this->uniqueStrings($emailAddresses, validateEmail: true);
    }

    /**
     * @return Builder<Student>
     */
    private function studentQuery(?User $sender, ?Student $permittedStudent = null): Builder
    {
        $query = Student::query();

        if (! $sender instanceof User) {
            return $query;
        }

        if (! $permittedStudent instanceof Student) {
            return Student::applyAdminAccessConstraint($query, $sender);
        }

        return $query->where(function (Builder $query) use ($permittedStudent, $sender): void {
            $query
                ->whereKey($permittedStudent->getKey())
                ->orWhere(fn (Builder $query): Builder => Student::applyAdminAccessConstraint($query, $sender));
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function applyNameSearch(Builder $query, string $search): Builder
    {
        $terms = str($search)
            ->explode(' ')
            ->filter();

        foreach ($terms as $term) {
            $query->where(function (Builder $query) use ($term): void {
                $query
                    ->whereLike('first_name', "%{$term}%")
                    ->orWhereLike('last_name', "%{$term}%");
            });
        }

        return $query;
    }

    private function fullName(Student|User $recipient): string
    {
        return $recipient->first_name.' '.$recipient->last_name;
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, int>
     */
    private function studentIds(array $values): array
    {
        return $this->tokenIds($values, self::STUDENT_PREFIX);
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, int>
     */
    private function teacherIds(array $values): array
    {
        return $this->tokenIds($values, self::TEACHER_PREFIX);
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, int>
     */
    private function tokenIds(array $values, string $prefix): array
    {
        return collect($values)
            ->map(fn (string $value): ?int => $this->tokenId($value, $prefix))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function tokenId(string $value, string $prefix): ?int
    {
        if (! str_starts_with($value, $prefix)) {
            return null;
        }

        $id = mb_substr($value, mb_strlen($prefix));

        if (! ctype_digit($id) || ((int) $id) < 1) {
            return null;
        }

        return (int) $id;
    }

    private function studentToken(mixed $id): string
    {
        return self::STUDENT_PREFIX.$id;
    }

    private function teacherToken(mixed $id): string
    {
        return self::TEACHER_PREFIX.$id;
    }

    private function isValidValue(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL)
            || $this->tokenId($value, self::STUDENT_PREFIX) !== null
            || $this->tokenId($value, self::TEACHER_PREFIX) !== null;
    }

    /**
     * @return array<int, string>
     */
    private function stringValues(mixed $values): array
    {
        if (is_string($values)) {
            $value = mb_trim($values);

            return filled($value) ? [$value] : [];
        }

        if (! is_array($values)) {
            return [];
        }

        $strings = [];

        foreach ($values as $value) {
            if (is_string($value) && filled($value = mb_trim($value))) {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function uniqueStrings(array $values, bool $validateEmail = false): array
    {
        $unique = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $value = mb_trim($value);

            if ($value === '' || ($validateEmail && ! filter_var($value, FILTER_VALIDATE_EMAIL))) {
                continue;
            }

            $unique[mb_strtolower($value)] ??= $value;
        }

        return array_values($unique);
    }
}
