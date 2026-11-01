<?php

namespace Tests\Feature\Web;

use App\Models\Project;
use App\Models\ProjectEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaseStudyStatusLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_case_studies_show_translated_statuses_and_working_filter_links(): void
    {
        $statuses = [
            'completed' => ['Completed', 'مكتمل'],
            'in_progress' => ['In progress', 'قيد التنفيذ'],
            'research' => ['Research', 'بحث'],
            'archived' => ['Archived', 'مؤرشف'],
        ];

        foreach ($statuses as $status => [$english, $arabic]) {
            $project = Project::create([
                'slug' => 'verified-case-'.str_replace('_', '-', $status),
                'title' => 'Evidence-backed case',
                'summary_en' => 'Published project with public proof',
                'summary_ar' => 'مشروع منشور بأدلة عامة',
                'role_en' => 'Engineer',
                'role_ar' => 'مهندس',
                'status' => $status,
                'visibility' => 'public',
                'published_at' => now()->subDay(),
            ]);
            ProjectEvidence::create([
                'project_id' => $project->id,
                'evidence_type' => 'document',
                'url' => 'https://example.org/public-proof',
                'visibility' => 'public',
                'verified_at' => now()->subDay(),
            ]);

            $en = $this->get('/projects/'.$project->slug.'?lang=en')->assertOk()
                ->assertSee('Status: '.$english)
                ->assertSee('View projects with this status')
                ->assertSee('https://example.org/public-proof')
                ->assertSee('href="'.e(route('public.projects', [
                    'lang' => 'en', 'status' => $status,
                ])).'"', false)
                ->assertDontSee('Status: '.$status);

            $ar = $this->get('/projects/'.$project->slug.'?lang=ar')->assertOk()
                ->assertSee('dir="rtl"', false)
                ->assertSee('الحالة: '.$arabic)
                ->assertSee('عرض مشاريع أخرى بالحالة نفسها')
                ->assertSee('href="'.e(route('public.projects', [
                    'lang' => 'ar', 'status' => $status,
                ])).'"', false)
                ->assertDontSee('الحالة: '.$status);
        }
    }

    public function test_unverified_project_cannot_be_accessed_via_localized_case_study(): void
    {
        $project = Project::create([
            'slug' => 'unverified-localized-case',
            'title' => 'Restricted case',
            'summary_en' => 'Not available publicly',
            'summary_ar' => 'غير متاح للعامة',
            'status' => 'completed',
            'visibility' => 'private',
            'published_at' => now()->subDay(),
        ]);
        ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'document',
            'url' => 'https://example.org/private-proof',
            'visibility' => 'public',
            'verified_at' => now()->subDay(),
        ]);

        $this->get('/projects/'.$project->slug.'?lang=ar')->assertNotFound();
        $this->get('/projects/'.$project->slug.'?lang=en')->assertNotFound();
    }
}
