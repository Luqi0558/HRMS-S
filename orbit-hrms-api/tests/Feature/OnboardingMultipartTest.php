<?php

namespace Tests\Feature;

use App\Services\InvitationService;
use Illuminate\Http\UploadedFile;
use Tests\TenantTestCase;

/**
 * The SPA submits onboarding as multipart/form-data (it carries files), not
 * JSON. Laravel parses `personal[phone]` and `technical[stacks][]` back into
 * nested arrays — these tests pin that the bracket notation the frontend
 * generates is actually what the FormRequest expects.
 */
class OnboardingMultipartTest extends TenantTestCase
{
    private function invite(): array
    {
        $result = app(InvitationService::class)->inviteEmployee([
            'name' => 'Hina Malik',
            'email' => 'hina@test.com',
            'designation' => 'QA Engineer',
        ], null);

        return [$result['employee'], $result['token']];
    }

    public function test_a_multipart_submission_is_parsed_into_nested_sections(): void
    {
        [$employee, $token] = $this->invite();

        $response = $this->post('/api/onboarding/complete', [
            'token' => $token,
            'password' => 'sup3rSecret!',
            'password_confirmation' => 'sup3rSecret!',
            'personal' => [
                'phone' => '0300-1234567',
                'cnic' => '42101-1234567-1',
                'emergencyContact' => 'Sara 0311',
            ],
            'technical' => [
                'designation' => 'QA Engineer',
                'experienceYears' => '2.5',
                'stacks' => ['QA Automation', 'MERN Stack'],
                'skills' => ['Selenium'],
            ],
        ], $this->tenantHeaders());

        $response->assertCreated();

        $employee->refresh();
        $this->assertSame('0300-1234567', $employee->profile->phone);
        $this->assertSame('Sara 0311', $employee->profile->emergency_contact);
        $this->assertEqualsCanonicalizing(
            ['QA Automation', 'MERN Stack'],
            $employee->stacks()->pluck('stack')->all()
        );
    }

    public function test_uploaded_documents_are_stored_against_their_slot(): void
    {
        [$employee, $token] = $this->invite();

        $this->post('/api/onboarding/complete', [
            'token' => $token,
            'password' => 'sup3rSecret!',
            'password_confirmation' => 'sup3rSecret!',
            'personal' => ['phone' => '0300-1234567'],
            'technical' => ['designation' => 'QA Engineer'],
            'documents' => [
                'cv' => UploadedFile::fake()->create('cv.pdf', 120, 'application/pdf'),
                'cnicFront' => UploadedFile::fake()->image('front.jpg'),
            ],
        ], $this->tenantHeaders())->assertCreated();

        // The frontend's camelCase key maps to the snake_case column value.
        $this->assertDatabaseHas('employee_documents', [
            'employee_id' => $employee->id,
            'type' => 'cnic_front',
        ]);
        $this->assertDatabaseHas('employee_documents', [
            'employee_id' => $employee->id,
            'type' => 'cv',
        ]);
    }

    public function test_an_oversized_or_wrong_type_upload_is_rejected(): void
    {
        [, $token] = $this->invite();

        $this->post('/api/onboarding/complete', [
            'token' => $token,
            'password' => 'sup3rSecret!',
            'password_confirmation' => 'sup3rSecret!',
            'personal' => ['phone' => '0300'],
            'technical' => ['designation' => 'QA'],
            'documents' => [
                // An executable is not in the mimes list for a CV.
                'cv' => UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload'),
            ],
        ], $this->tenantHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documents.cv']);
    }

    /**
     * The frontend sends `technical[stacks][]=''` when the user selected
     * nothing, so an empty string must not become a stack row.
     */
    public function test_an_empty_array_placeholder_does_not_create_a_row(): void
    {
        [$employee, $token] = $this->invite();

        $this->post('/api/onboarding/complete', [
            'token' => $token,
            'password' => 'sup3rSecret!',
            'password_confirmation' => 'sup3rSecret!',
            'personal' => ['phone' => '0300'],
            'technical' => ['designation' => 'QA', 'stacks' => ['']],
        ], $this->tenantHeaders())->assertCreated();

        $this->assertSame(0, $employee->stacks()->count());
    }
}
