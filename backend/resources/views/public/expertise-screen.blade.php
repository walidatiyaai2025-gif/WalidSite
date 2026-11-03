{{-- WEB-03 final-design preview: new 4-tab showcase. Its skills catalog retains
     exact public/owner/verified filtering from the existing controller. --}}
@php($categoryLabelsAr = [
    'Database & Reliability' => 'قواعد البيانات والموثوقية',
    'Software & Integration' => 'البرمجيات والتكامل',
    'Infrastructure & Security' => 'البنية التحتية والأمن',
    'Project Delivery' => 'إدارة وتسليم المشاريع',
])
@php($categoryTracks = [
    'Database & Reliability' => 'database',
    'Software & Integration' => 'software',
    'Infrastructure & Security' => 'software',
    'Project Delivery' => 'delivery',
])
@php($categoryFilters = [
    'Database & Reliability' => 'database',
    'Software & Integration' => 'integration',
    'Infrastructure & Security' => 'security',
    'Project Delivery' => 'delivery',
])
<div class="expertise-screen">
    @include('public.expertise-2026-showcase')
    <details class="expertise-proven-panel" id="expertise-catalog" @if($expertiseCategory !== '' || $skills->isNotEmpty()) open @endif>
        <summary>{{ $locale === 'ar' ? 'المهارات المنشورة التي تم التحقق من أدلتها' : 'Verified public skills & evidence' }}</summary>
    <section class="expertise-body" aria-labelledby="professional-section" tabindex="-1">
        <h2 class="expertise-section-heading" id="professional-section">{{ $locale === 'ar' ? 'المهارات المعتمدة' : 'Evidence-verified skills' }}</h2>
        <form class="expertise-filter" action="{{ route('public.expertise') }}" method="get" role="search"
              aria-label="{{ $locale === 'ar' ? 'تصفية المهارات الموثقة' : 'Filter verified skills' }}">
            <input type="hidden" name="lang" value="{{ $locale }}">
            <div>
                <label for="expertise-category">{{ $locale === 'ar' ? 'التخصص' : 'Specialty' }}</label>
                <select id="expertise-category" name="category" aria-describedby="expertise-filter-hint">
                    <option value="" @selected($expertiseCategory === '')>{{ $locale === 'ar' ? 'كل التخصصات' : 'All specialties' }}</option>
                    @foreach($expertiseCategories as $key => $category)
                        <option value="{{ $key }}" @selected($expertiseCategory === $key)>{{ $locale === 'ar' ? ($categoryLabelsAr[$category] ?? $category) : $category }}</option>
                    @endforeach
                </select>
                <p id="expertise-filter-hint" class="expertise-visually-hidden">{{ $locale === 'ar' ? 'تُعرض المهارات المعتمدة والموثقة للنشر فقط.' : 'Only approved, evidence-verified published skills are displayed.' }}</p>
            </div>
            <button type="submit">{{ $locale === 'ar' ? 'تطبيق التصفية' : 'Apply filter' }}</button>
            @if($expertiseCategory !== '')
                <a href="{{ route('public.expertise', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'إزالة التصفية' : 'Clear filter' }}</a>
            @endif
        </form>
        @if($expertiseCategory !== '')
            {{-- The active filter and actual verified count are trustworthy server-side values,
                 not a claim that an absent category has no skills anywhere. --}}
            <p class="expertise-filter-status" id="expertise-filter-status" role="status" aria-live="polite">
                <strong>{{ $locale === 'ar' ? 'التخصص المحدد:' : 'Selected specialty:' }}
                    {{ $locale === 'ar'
                        ? ($categoryLabelsAr[$expertiseCategories[$expertiseCategory]] ?? $expertiseCategories[$expertiseCategory])
                        : $expertiseCategories[$expertiseCategory] }}</strong>
                — {{ $locale === 'ar'
                    ? 'المهارات الموثقة المطابقة: '.$skills->count()
                    : 'Matching verified skills: '.$skills->count() }}
            </p>
        @endif
        @if($skills->isEmpty())
            <div class="expertise-empty">
                <p role="status">{{ $expertiseCategory !== ''
                    ? ($locale === 'ar'
                        ? 'لا توجد مهارات منشورة وموثقة في هذا التخصص. يمكن عرض جميع التخصصات.'
                        : 'No evidence-verified published skills match this specialty. View all specialties instead.')
                    : ($locale === 'ar' ? 'لم تُعتمد مهارات للنشر بعد.' : 'No skills have been approved with verified evidence yet.') }}</p>
                @if($expertiseCategory !== '')
                    <a href="{{ route('public.expertise', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'عرض جميع التخصصات' : 'View all specialties' }}</a>
                @endif
            </div>
        @else
            <div class="expertise-cards">
                @foreach($skills->groupBy('category') as $category => $group)
                    <article class="expertise-card">
                        <div class="expertise-symbol" aria-hidden="true">{{ $category === 'Database & Reliability' ? 'DB' : ($category === 'Software & Integration' ? '</>' : ($category === 'Infrastructure & Security' ? '◇' : '✓')) }}</div>
                        <h3>{{ $locale === 'ar' ? ($categoryLabelsAr[$category] ?? $category) : $category }}</h3>
                        <ul class="expertise-skills">
                            @foreach($group as $skill)
                                <li>{{ $locale === 'ar' ? $skill->name_ar : $skill->name_en }}</li>
                            @endforeach
                        </ul>
                        <div class="expertise-card-footer">
                            @if(isset($categoryTracks[$category]))
                                {{-- Retain the established recruiter journey as a separate action. --}}
                                <a class="professional-track-link" href="{{ route('public.hire', ['lang' => $locale, 'track' => $categoryTracks[$category]]) }}">{{ $locale === 'ar' ? 'تصفية الخبرات حسب ' : 'Filter expertise by ' }}{{ $locale === 'ar' ? ($categoryLabelsAr[$category] ?? $category) : $category }}</a>
                            @endif
                            @if(isset($categoryFilters[$category]))
                                {{-- Unlike recruiter tracks, this action filters exactly the clicked skill category. --}}
                                <a class="expertise-card-filter" href="{{ route('public.expertise', ['lang' => $locale, 'category' => $categoryFilters[$category]]) }}" @if($expertiseCategory === $categoryFilters[$category]) aria-current="page" aria-describedby="expertise-filter-status" @endif>{{ $locale === 'ar' ? 'تصفية المهارات حسب ' : 'Filter skills by ' }}{{ $locale === 'ar' ? ($categoryLabelsAr[$category] ?? $category) : $category }}</a>
                            @else
                                <span>{{ $locale === 'ar' ? 'المهارات المنشورة في هذا المجال' : 'Published verified skills' }}</span>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
        <nav class="expertise-proof-links" aria-label="{{ $locale === 'ar' ? 'أدلة الخبرات والاتصال' : 'Explore public evidence' }}">
            <a href="{{ route('public.projects', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'دراسات حالة موثقة' : 'Verified case studies' }}</a>
            <a href="{{ route('public.github', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'مشاريع GitHub' : 'GitHub projects' }}</a>
            <a href="{{ route('public.about', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'نبذة وخبرات' : 'About' }}</a>
            <a href="{{ route('public.hire', ['lang' => $locale, 'track' => 'all']) }}">{{ $locale === 'ar' ? 'التوظيف' : 'Recruiter' }}</a>
            <a href="{{ route('public.resume', ['lang' => $locale, 'track' => 'all']) }}">{{ $locale === 'ar' ? 'السيرة العامة حسب المجال' : 'View focused public resume' }}</a>
            <a href="{{ route('public.contact', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'التواصل بشأن المجال' : 'Contact about this focus' }}</a>
        </nav>
    </section>
    </details>
</div>
