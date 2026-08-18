<?php

declare(strict_types=1);

use App\Models\Calendar;
use App\Models\CartItem;
use App\Models\CompetitionSeason;
use App\Models\CompetitionTeam;
use App\Models\Course;
use App\Models\CreditGrant;
use App\Models\CreditTransaction;
use App\Models\DashboardMessage;
use App\Models\DashboardQuickLink;
use App\Models\DiscountCode;
use App\Models\EmergencyContact;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Form;
use App\Models\FormUser;
use App\Models\Gear;
use App\Models\GiftCard;
use App\Models\GiftCardType;
use App\Models\Holiday;
use App\Models\Installment;
use App\Models\LegalDocument;
use App\Models\LegalDocumentAcceptance;
use App\Models\LegalDocumentVersion;
use App\Models\ManagedBanner;
use App\Models\ManagedBannerDismissal;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanTemplate;
use App\Models\Product;
use App\Models\ProductEarlyAccessWindow;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionAnswer;
use App\Models\ShowcaseParticipation;
use App\Models\Student;
use App\Models\StudentEmail;
use App\Models\StudentWaiver;
use App\Models\User;
use App\Support\LegalDocuments\HealthSafetyPolicy;
use App\Support\LegalDocuments\PaymentPlanTerms;
use App\Support\LegalDocuments\PortalTerms;
use App\Support\LegalDocuments\TextMessageUpdatesPolicy;
use App\Support\MediaDisks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Tags\Tag;

it('seeds the development database with all models', function (): void {
    Storage::fake(MediaDisks::public());

    $this->seed();

    $seededProductImageCounts = Product::all()
        ->map(fn (Product $product): int => $product->getMedia('images')->count());

    expect(User::count())->toBeGreaterThanOrEqual(16)
        ->and(Student::count())->toBeGreaterThanOrEqual(15)
        ->and(CompetitionSeason::count())->toBe(2)
        ->and(CompetitionSeason::query()->current()->count())->toBe(1)
        ->and(CompetitionTeam::count())->toBe(4)
        ->and(CompetitionTeam::query()->current()->count())->toBe(3)
        ->and(DB::table('competition_team_student')->count())->toBe(20)
        ->and(DB::table('competition_team_user')->count())->toBe(8)
        ->and(Student::query()->whereHas('competitionTeams', fn ($query) => $query->current(), '>=', 2)->exists())->toBeTrue()
        ->and(User::query()->whereHas('competitionTeams')->whereDoesntHave('roles')->exists())->toBeFalse()
        ->and(Calendar::count())->toBe(5)
        ->and(Tag::query()->where('type', 'calendar-audience')->count())->toBe(0)
        ->and(Calendar::query()->whereIn('slug', Calendar::SYSTEM_SLUGS)->whereNotNull('access')->count())->toBe(0)
        ->and(Calendar::query()->whereIn('slug', Calendar::SYSTEM_SLUGS)->whereHas('audiences')->count())->toBe(0)
        ->and(Form::count())->toBe(2)
        ->and(LegalDocument::query()->whereIn('key', [
            HealthSafetyPolicy::KEY,
            PaymentPlanTerms::KEY,
            PortalTerms::KEY,
            TextMessageUpdatesPolicy::KEY,
        ])->count())->toBe(4)
        ->and(LegalDocumentVersion::count())->toBeGreaterThanOrEqual(4)
        ->and(LegalDocumentAcceptance::count())->toBe(2)
        ->and(Course::count())->toBeGreaterThanOrEqual(10)
        ->and(Product::count())->toBeGreaterThanOrEqual(20)
        ->and(Product::query()->whereNull('price')->exists())->toBeTrue()
        ->and($seededProductImageCounts->unique()->sort()->values()->all())->toBe([0, 1, 2, 3])
        ->and(Gear::count())->toBe(5)
        ->and(GiftCardType::count())->toBe(4)
        ->and(GiftCardType::query()->where('allows_custom_amount', true)->exists())->toBeTrue()
        ->and(PaymentPlanTemplate::count())->toBe(3)
        ->and(DiscountCode::count())->toBe(4)
        ->and(DashboardMessage::count())->toBe(3)
        ->and(DashboardQuickLink::count())->toBe(3)
        ->and(Holiday::count())->toBe(2)
        ->and(ManagedBanner::count())->toBe(3)
        ->and(ManagedBannerDismissal::count())->toBe(1)
        ->and(Event::count())->toBeGreaterThanOrEqual(20)
        ->and(ProductEarlyAccessWindow::count())->toBe(2)
        ->and(Enrollment::count())->toBeGreaterThanOrEqual(30)
        ->and(Enrollment::query()->whereNotNull('order_item_id')->exists())->toBeTrue()
        ->and(Order::count())->toBeGreaterThanOrEqual(16)
        ->and(OrderItem::count())->toBeGreaterThanOrEqual(15)
        ->and(OrderItem::query()->where('custom_gift_card_amount', '>', 0)->exists())->toBeTrue()
        ->and(CartItem::count())->toBe(6)
        ->and(CartItem::query()->where('custom_gift_card_amount', '>', 0)->exists())->toBeTrue()
        ->and(GiftCard::count())->toBe(7)
        ->and(PaymentPlan::count())->toBe(3)
        ->and(Installment::count())->toBeGreaterThanOrEqual(9)
        ->and(EventAttendee::count())->toBeGreaterThanOrEqual(20)
        ->and(CreditGrant::count())->toBe(11)
        ->and(ProductQuestion::count())->toBe(2)
        ->and(ProductQuestionAnswer::count())->toBe(2)
        ->and(StudentWaiver::count())->toBeGreaterThanOrEqual(1)
        ->and(ShowcaseParticipation::count())->toBeGreaterThanOrEqual(1)
        ->and(StudentEmail::count())->toBe(10)
        ->and(EmergencyContact::count())->toBeGreaterThanOrEqual(2)
        ->and(FormUser::count())->toBeGreaterThanOrEqual(2)
        ->and(CreditTransaction::count())->toBeGreaterThanOrEqual(11)
        ->and(Role::findByName('super_admin')->hasPermissionTo('ViewAny:Holiday'))->toBeTrue()
        ->and(Role::findByName('super_admin')->hasPermissionTo('Create:Holiday'))->toBeTrue()
        ->and(Role::findByName('super_admin')->hasPermissionTo('Cancel:Event'))->toBeTrue()
        ->and(Role::findByName('super_admin')->hasPermissionTo('Manage:DashboardAppearance'))->toBeTrue()
        ->and(Role::findByName('owner')->hasPermissionTo('Manage:DashboardAppearance'))->toBeTrue();
});
