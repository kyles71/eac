<?php

declare(strict_types=1);

use App\Enums\FormTypes;
use App\Models\Form;
use App\Models\FormUser;
use App\Models\Student;

it('shows dedicated waiver banners before the generic forms fallback', function (): void {
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $waiverForm = Form::factory()->create(['form_type' => FormTypes::StudentWaiver]);
    $genericForm = Form::factory()->create(['form_type' => FormTypes::ShowcaseParticipation]);

    FormUser::factory()->forStudent($student)->unsigned()->create([
        'form_id' => $waiverForm->id,
        'user_id' => auth()->id(),
    ]);
    FormUser::factory()->forStudent($student)->unsigned()->create([
        'form_id' => $genericForm->id,
        'user_id' => auth()->id(),
    ]);

    $this->get('/dancefam')
        ->assertOk()
        ->assertSeeText('Waivers Needed')
        ->assertSeeText('The following students need waivers signed: '.$student->first_name)
        ->assertSeeText('Forms Needed')
        ->assertSeeText('You have 1 form(s) that need to be completed.');
});
