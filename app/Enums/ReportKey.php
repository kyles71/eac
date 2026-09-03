<?php

declare(strict_types=1);

namespace App\Enums;

use App\Filament\Admin\Pages\Reports\ClassAttendanceReport;
use App\Filament\Admin\Pages\Reports\ClassRosters;
use App\Filament\Admin\Pages\Reports\ClassSafetyRoster;
use App\Filament\Admin\Pages\Reports\CompetitionAttendanceReport;
use App\Filament\Admin\Pages\Reports\CompetitionEmailList;
use App\Filament\Admin\Pages\Reports\CompetitionEnrollments;
use App\Filament\Admin\Pages\Reports\EmergencyTextsByCourse;
use App\Filament\Admin\Pages\Reports\EnrollmentsByTerm;
use App\Filament\Admin\Pages\Reports\InstructorClassAssignments;
use App\Filament\Admin\Pages\Reports\InstructorHoursSummary;
use App\Filament\Admin\Pages\Reports\InstructorSchedule;
use App\Filament\Admin\Pages\Reports\InstructorSubReport;
use App\Filament\Admin\Pages\Reports\InstructorTeachingSchedule;
use App\Filament\Admin\Pages\Reports\OverallAttendanceReport;
use App\Filament\Admin\Pages\Reports\ReportPage;
use App\Filament\Admin\Pages\Reports\SubstituteCoverage;
use App\Filament\Admin\Pages\Reports\TermEmailList;
use App\Filament\Admin\Pages\Reports\TotalEnrollmentsByClass;
use App\Models\Role;
use App\Models\User;

enum ReportKey: string
{
    case EnrollmentsByTerm = 'enrollments-by-term';
    case TotalEnrollmentsByClass = 'total-enrollments-by-class';
    case CompetitionEnrollments = 'competition-enrollments';
    case TermEmailList = 'term-email-list';
    case CompetitionEmailList = 'competition-email-list';
    case InstructorClassAssignments = 'instructor-class-assignments';
    case InstructorTeachingSchedule = 'instructor-teaching-schedule';
    case InstructorHoursSummary = 'instructor-hours-summary';
    case SubstituteCoverage = 'substitute-coverage';
    case ClassRosters = 'class-rosters';
    case InstructorSchedule = 'instructor-schedule';
    case ClassSafetyRoster = 'class-safety-roster';
    case EmergencyTextsByCourse = 'emergency-texts-by-course';
    case ClassAttendance = 'class-attendance';
    case CompetitionAttendance = 'competition-attendance';
    case OverallAttendance = 'overall-attendance';
    case InstructorSubReport = 'instructor-sub-report';

    /** @return array<string, string> */
    public static function permissionOptions(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $report): array => [
                $report->permission() => 'View '.$report->label(),
            ])
            ->all();
    }

    public function category(): ReportCategory
    {
        return match ($this) {
            self::EnrollmentsByTerm,
            self::TotalEnrollmentsByClass,
            self::CompetitionEnrollments,
            self::TermEmailList,
            self::CompetitionEmailList => ReportCategory::Enrollment,
            self::InstructorClassAssignments,
            self::InstructorTeachingSchedule,
            self::InstructorHoursSummary,
            self::SubstituteCoverage,
            self::ClassRosters,
            self::InstructorSchedule,
            self::ClassSafetyRoster,
            self::EmergencyTextsByCourse,
            self::ClassAttendance,
            self::CompetitionAttendance,
            self::OverallAttendance,
            self::InstructorSubReport => ReportCategory::Instructor,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::EnrollmentsByTerm => 'Enrollments by Term',
            self::TotalEnrollmentsByClass => 'Total Enrollments by Class',
            self::CompetitionEnrollments => 'Competition Enrollments',
            self::TermEmailList => 'Term Email List',
            self::CompetitionEmailList => 'Competition Email List',
            self::InstructorClassAssignments => 'Instructor Class Assignments',
            self::InstructorTeachingSchedule => 'Instructor Teaching Schedule',
            self::InstructorHoursSummary => 'Instructor Hours Summary',
            self::SubstituteCoverage => 'Substitute Coverage',
            self::ClassRosters => 'Class Rosters',
            self::InstructorSchedule => 'Instructor Schedule Report',
            self::ClassSafetyRoster => 'Class Safety Roster',
            self::EmergencyTextsByCourse => 'Emergency Texts by Course',
            self::ClassAttendance => 'Class Attendance Report',
            self::CompetitionAttendance => 'Competition Attendance Report',
            self::OverallAttendance => 'Overall Attendance Report',
            self::InstructorSubReport => 'Sub Report',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::EnrollmentsByTerm => 'A dancer-by-course enrollment matrix with waiver and media status.',
            self::TotalEnrollmentsByClass => 'Enrollment, capacity, availability, and utilization by class.',
            self::CompetitionEnrollments => 'Competition dancers and their enrolled classes for a season and term.',
            self::TermEmailList => 'Unique user-account and dancer-associated emails for a term.',
            self::CompetitionEmailList => 'Unique dancer-associated emails for a competition season.',
            self::InstructorClassAssignments => 'Assigned and guest instructors by class and academic term.',
            self::InstructorTeachingSchedule => 'Scheduled teaching events with effective instructor credit and duration.',
            self::InstructorHoursSummary => 'Scheduled, completed, upcoming, and confirmed substitute hours summarized by instructor.',
            self::SubstituteCoverage => 'Events requiring substitute coverage and their current coverage status.',
            self::ClassRosters => 'Enrolled dancers and media-release status for a selected class.',
            self::InstructorSchedule => 'Classes taught by an instructor, including schedule, enrollment, and co-instructors.',
            self::ClassSafetyRoster => 'Emergency contacts and medical safety details for dancers in a selected class.',
            self::EmergencyTextsByCourse => 'Emergency contacts who opted into text updates for dancers in a selected class.',
            self::ClassAttendance => 'Attendance counts by dancer for a selected class and date range.',
            self::CompetitionAttendance => 'To-date attendance rates and absences for competition dancers by class.',
            self::OverallAttendance => 'To-date attendance rates and absences summarized by class.',
            self::InstructorSubReport => 'Substitute dates, reasons, original instructors, and confirmed substitutes by term.',
        };
    }

    public function permission(): string
    {
        return $this->name.':Reports';
    }

    public function availableToTeachersByDefault(): bool
    {
        return $this === self::TotalEnrollmentsByClass
            || ($this->category() === ReportCategory::Instructor
                && ! in_array($this, [self::ClassSafetyRoster, self::InstructorSubReport], true));
    }

    /** @return class-string<ReportPage> */
    public function page(): string
    {
        return match ($this) {
            self::EnrollmentsByTerm => EnrollmentsByTerm::class,
            self::TotalEnrollmentsByClass => TotalEnrollmentsByClass::class,
            self::CompetitionEnrollments => CompetitionEnrollments::class,
            self::TermEmailList => TermEmailList::class,
            self::CompetitionEmailList => CompetitionEmailList::class,
            self::InstructorClassAssignments => InstructorClassAssignments::class,
            self::InstructorTeachingSchedule => InstructorTeachingSchedule::class,
            self::InstructorHoursSummary => InstructorHoursSummary::class,
            self::SubstituteCoverage => SubstituteCoverage::class,
            self::ClassRosters => ClassRosters::class,
            self::InstructorSchedule => InstructorSchedule::class,
            self::ClassSafetyRoster => ClassSafetyRoster::class,
            self::EmergencyTextsByCourse => EmergencyTextsByCourse::class,
            self::ClassAttendance => ClassAttendanceReport::class,
            self::CompetitionAttendance => CompetitionAttendanceReport::class,
            self::OverallAttendance => OverallAttendanceReport::class,
            self::InstructorSubReport => InstructorSubReport::class,
        };
    }

    public function canView(User $user): bool
    {
        if (! $user->can($this->permission())) {
            return false;
        }

        return $this !== self::CompetitionEnrollments
            || ! $user->hasCourseRestrictedAdminAccess()
            || $user->competitionTeams()->exists();
    }

    /** @return list<string> */
    public function allowedFilterNames(): array
    {
        return match ($this) {
            self::EnrollmentsByTerm => ['academic_term_id', 'course_id'],
            self::TermEmailList => ['academic_term_id'],
            self::TotalEnrollmentsByClass => ['academic_term_id', 'capacity_status', 'course_tag'],
            self::CompetitionEnrollments => ['academic_term_id', 'competition_season_id'],
            self::CompetitionEmailList => ['competition_season_id', 'competition_team_id'],
            self::InstructorClassAssignments => ['academic_term_id', 'instructor_id'],
            self::InstructorTeachingSchedule, self::InstructorHoursSummary => [
                'academic_term_id',
                'instructor_id',
                'date_range',
            ],
            self::SubstituteCoverage => [
                'academic_term_id',
                'instructor_id',
                'date_range',
                'coverage_status',
            ],
            self::ClassRosters, self::ClassSafetyRoster, self::EmergencyTextsByCourse => [
                'academic_term_id',
                'course_id',
            ],
            self::InstructorSchedule => ['academic_term_id', 'instructor_id'],
            self::ClassAttendance => ['academic_term_id', 'course_id', 'date_range'],
            self::CompetitionAttendance => ['academic_term_id', 'competition_season_id'],
            self::OverallAttendance, self::InstructorSubReport => ['academic_term_id'],
        };
    }
}
