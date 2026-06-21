@foreach ($staffMembers as $staffMember)
    @php
        $staffPhotoUrl = $staffMember->getStaffPhotoUrl();
        $staffBio = filled($staffMember->staff_bio) ? $staffMember->staff_bio : null;
        $hasTooltip = filled($staffPhotoUrl) || filled($staffBio);
        $tooltip = $hasTooltip
            ? view('filament.shared.staff-profile-tooltip', compact('staffPhotoUrl', 'staffBio'))->render()
            : null;
    @endphp

    <span
        @if ($hasTooltip)
            class="cursor-help underline decoration-dotted underline-offset-2"
            tabindex="0"
            x-tooltip="{ content: @js($tooltip), theme: $store.theme, allowHTML: true }"
        @endif
    >{{ $staffMember->fullName }}</span>@unless ($loop->last), @endunless
@endforeach
