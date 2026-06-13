<?php

declare(strict_types=1);

use App\Enums\DashboardAudience;
use App\Enums\InstallmentStatus;
use App\Enums\OrderStatus;
use App\Filament\Shared\Widgets\CalendarWidget;
use App\Filament\Shared\Widgets\MessagesFromEac;
use App\Filament\Shared\Widgets\QuickLinks;
use App\Filament\User\Pages\Dashboard;
use App\Filament\User\Pages\Store;
use App\Filament\User\Widgets\ComingUp;
use App\Filament\User\Widgets\NeedsAttention;
use App\Filament\User\Widgets\NextPayment;
use App\Models\Calendar;
use App\Models\CompetitionSeason;
use App\Models\CompetitionTeam;
use App\Models\Course;
use App\Models\DashboardMessage;
use App\Models\DashboardQuickLink;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\Holiday;
use App\Models\Installment;
use App\Models\Order;
use App\Models\PaymentPlan;
use App\Models\Student;
use App\Models\User;
use App\Services\DashboardAudienceService;
use App\Settings\DashboardAppearanceSettings;
use App\Support\MediaDisks;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Spatie\Tags\Tag;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('user');
});

it('keeps the full calendar on the dashboard', function (): void {
    expect((new Dashboard)->getWidgets())
        ->toContain(CalendarWidget::class)
        ->toContain(NextPayment::class)
        ->not->toContain('App\\Filament\\User\\Widgets\\AccountOverview');
});

it('resolves inherited dashboard audiences for families teachers and owners', function (): void {
    $family = User::factory()->create();
    $compFamily = User::factory()->create();
    $teacher = User::factory()->isTeacher()->create();
    $owner = User::factory()->isOwner()->create();
    $course = Course::factory()->create(['start_time' => now()->addDay()]);
    $compStudent = Student::factory()->create(['user_id' => $compFamily->id]);
    $compTeam = CompetitionTeam::factory()
        ->for(CompetitionSeason::factory()->current(), 'season')
        ->create();
    $compStudent->competitionTeams()->attach($compTeam);

    Enrollment::factory()->create([
        'user_id' => $family->id,
        'course_id' => $course->id,
        'student_id' => null,
    ]);

    $service = app(DashboardAudienceService::class);

    expect($service->audiencesFor($family))->toBe([
        DashboardAudience::Semester,
        DashboardAudience::Eac,
    ])->and($service->audiencesFor($compFamily))->toBe([
        DashboardAudience::CompTeam,
        DashboardAudience::Eac,
    ])->and($service->audiencesFor($teacher))->toBe([
        DashboardAudience::Teacher,
        DashboardAudience::Semester,
        DashboardAudience::Eac,
    ])->and($service->audiencesFor($owner))->toBe([
        DashboardAudience::Owner,
        DashboardAudience::Teacher,
        DashboardAudience::Semester,
        DashboardAudience::Eac,
    ]);
});

it('orders active messages by inherited audience then newest first', function (): void {
    $owner = User::factory()->isOwner()->create();
    $team = CompetitionTeam::factory()
        ->for(CompetitionSeason::factory()->current(), 'season')
        ->create();
    $owner->competitionTeams()->attach($team);
    $this->actingAs($owner);

    DashboardMessage::factory()->create(['message' => 'EAC message', 'audience' => DashboardAudience::Eac]);
    DashboardMessage::factory()->create(['message' => 'Semester older', 'audience' => DashboardAudience::Semester, 'created_at' => now()->subDay()]);
    DashboardMessage::factory()->create(['message' => 'Semester newer', 'audience' => DashboardAudience::Semester]);
    DashboardMessage::factory()->create(['message' => 'Comp Team message', 'audience' => DashboardAudience::CompTeam]);
    DashboardMessage::factory()->create(['message' => 'Teacher message', 'audience' => DashboardAudience::Teacher]);
    DashboardMessage::factory()->create(['message' => 'Owner message', 'audience' => DashboardAudience::Owner]);
    DashboardMessage::factory()->create(['message' => 'Expired message', 'expires_at' => now()->subMinute()]);
    DashboardMessage::factory()->create(['message' => 'Future message', 'published_at' => now()->addMinute()]);

    $messages = DashboardMessage::query()
        ->active()
        ->visibleTo($owner)
        ->audienceOrdered()
        ->pluck('message')
        ->all();

    expect($messages)->toBe([
        'Owner message',
        'Teacher message',
        'Comp Team message',
        'Semester newer',
        'Semester older',
        'EAC message',
    ]);
});

it('orders visible quick links by audience then manual order and resolves destinations', function (): void {
    $owner = User::factory()->isOwner()->create();
    $team = CompetitionTeam::factory()
        ->for(CompetitionSeason::factory()->current(), 'season')
        ->create();
    $owner->competitionTeams()->attach($team);
    $this->actingAs($owner);

    DashboardQuickLink::factory()->create([
        'label' => 'EAC',
        'audience' => DashboardAudience::Eac,
        'sort_order' => 1,
    ]);
    DashboardQuickLink::factory()->create([
        'label' => 'Owner second',
        'audience' => DashboardAudience::Owner,
        'sort_order' => 2,
    ]);
    DashboardQuickLink::factory()->create([
        'label' => 'Owner first',
        'audience' => DashboardAudience::Owner,
        'destination' => Store::class,
        'external_url' => null,
        'sort_order' => 1,
    ]);
    DashboardQuickLink::factory()->create([
        'label' => 'Comp Team',
        'audience' => DashboardAudience::CompTeam,
        'sort_order' => 1,
    ]);
    DashboardQuickLink::factory()->create(['label' => 'Inactive', 'is_active' => false]);

    $links = DashboardQuickLink::query()->active()->visibleTo($owner)->audienceOrdered()->get();

    expect($links->pluck('label')->all())->toBe(['Owner first', 'Owner second', 'Comp Team', 'EAC'])
        ->and($links->first()->resolvedUrl())->toContain('/dancefam/store')
        ->and($links->first()->opensInNewTab())->toBeFalse()
        ->and($links->last()->opensInNewTab())->toBeTrue();
});

it('renders dashboard communication and action widgets without exposing audience labels', function (): void {
    Storage::fake(MediaDisks::public());

    $settings = app(DashboardAppearanceSettings::class);
    $settings->messages_bullet_image = 'dashboard/bullets/messages.png';
    $settings->quick_links_bullet_image = 'dashboard/bullets/quick-links.svg';
    $settings->save();

    DashboardMessage::factory()->create([
        'message' => 'Enrollment closes Friday.',
        'audience' => DashboardAudience::Owner,
    ]);
    DashboardQuickLink::factory()->create([
        'label' => 'Studio Website',
        'audience' => DashboardAudience::Owner,
    ]);

    livewire(MessagesFromEac::class)
        ->assertSee('Enrollment closes Friday.')
        ->assertDontSee('Owner Audience')
        ->assertSeeHtml('src="'.$settings->messagesBulletImageUrl().'"')
        ->assertSeeHtml('class="max-h-72 overflow-y-auto pr-2"')
        ->assertDontSeeHtml('border border-gray-200');

    livewire(MessagesFromEac::class)
        ->mountAction('viewAll')
        ->assertActionMounted('viewAll')
        ->assertSee('Messages From EAC')
        ->assertSee('Enrollment closes Friday.')
        ->assertSeeHtml('src="'.$settings->messagesBulletImageUrl().'"');

    livewire(QuickLinks::class)
        ->assertSee('Studio Website')
        ->assertDontSee('Owner Audience')
        ->assertSeeHtml('src="'.$settings->quickLinksBulletImageUrl().'"')
        ->assertSeeHtml('class="max-h-72 overflow-y-auto pr-2"')
        ->assertDontSeeHtml('border border-gray-200')
        ->assertSeeHtml('target="_blank"')
        ->assertSeeHtml('class="fi-icon fi-size-md h-4 w-4 shrink-0"');

    livewire(NeedsAttention::class)
        ->assertSee('You are all caught up');

    livewire(Dashboard::class)
        ->assertSee('Hello,')
        ->assertActionExists('browseStore')
        ->assertActionExists('viewCalendar')
        ->assertActionExists('addStudent')
        ->assertActionExists('billing');
});

it('shows only my upcoming events and closures without calendar tabs', function (): void {
    $publicTag = Tag::findOrCreate(Calendar::AUDIENCE_TAG_PUBLIC, Calendar::AUDIENCE_TAG_TYPE);
    $myCalendar = Calendar::query()->where('slug', Calendar::SLUG_MY)->firstOrFail();
    $eacCalendar = Calendar::query()->where('slug', Calendar::SLUG_EAC)->firstOrFail();
    $myCalendar->attachTag($publicTag);
    $eacCalendar->attachTag($publicTag);

    Event::factory()->create([
        'name' => 'Public Open House',
        'calendar_id' => $eacCalendar->id,
        'course_id' => null,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    Holiday::factory()->create([
        'name' => 'Studio Closed',
        'starts_on' => now()->addDays(2),
        'ends_on' => now()->addDays(2),
    ]);

    livewire(ComingUp::class)
        ->assertSee('My Upcoming Events')
        ->assertSee('Studio Closed')
        ->assertDontSee('My Calendar')
        ->assertDontSee('Public Events')
        ->assertDontSee('Public Open House');
});

it('shows next payment only for active payment plans requiring another payment', function (): void {
    expect(NextPayment::canView())->toBeFalse()
        ->and((new ComingUp)->getColumnSpan())->toBe('full');

    $order = Order::factory()->create(['user_id' => auth()->id()]);
    $paymentPlan = PaymentPlan::factory()->create(['order_id' => $order->id]);
    $installment = Installment::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'amount' => 4500,
        'due_date' => now()->addWeek(),
    ]);

    expect(NextPayment::canView())->toBeFalse();

    $order->update(['status' => OrderStatus::Completed]);

    expect(NextPayment::canView())->toBeTrue()
        ->and((new ComingUp)->getColumnSpan())->toBe(1);

    livewire(NextPayment::class)
        ->assertSee('Next Payment')
        ->assertSee('$45.00')
        ->assertSee('Due '.$installment->due_date->format('M j, Y'));

    $installment->update(['status' => InstallmentStatus::Paid]);

    expect(NextPayment::canView())->toBeFalse()
        ->and((new ComingUp)->getColumnSpan())->toBe('full');
});
