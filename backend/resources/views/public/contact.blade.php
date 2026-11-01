@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
{{-- Direct view-render tests and callers without article context remain valid. --}}
@php($reportArticle = $reportArticle ?? null)
@php($reportProject = $reportProject ?? null)
{{-- Recruiter track is an optional topic of inquiry, never verified CV proof.
     Do not mix it with authoritative article/case-study contexts or reflect
     array/unknown values into an editable subject or locale-switch URL. --}}
@php($contactTrackRaw = request()->query()['track'] ?? null)
@php($contactTrack = !$reportArticle && !$reportProject
    && !array_key_exists('article', request()->query())
    && !array_key_exists('project', request()->query())
    && is_string($contactTrackRaw)
    && in_array($contactTrackRaw, ['database', 'software', 'delivery'], true) ? $contactTrackRaw : null)
@php($contactTrackLabels = [
    'database' => $locale === 'ar' ? 'هندسة قواعد البيانات' : 'Database engineering',
    'software' => $locale === 'ar' ? 'هندسة البرمجيات والبنية التحتية' : 'Software and infrastructure engineering',
    'delivery' => $locale === 'ar' ? 'إدارة وتسليم المشاريع' : 'Project delivery',
])
@php($suggestedSubject = $suggestedSubject ?? '')
@php($suggestedSubject = $suggestedSubject !== '' ? $suggestedSubject
    : ($contactTrack ? ($locale === 'ar' ? 'استفسار عن مجال: ' : 'Inquiry about: ')
        .$contactTrackLabels[$contactTrack] : ''))
@section('title', $locale === 'ar' ? 'التواصل — وليد عطية' : 'Contact — Waleed Atiya')
@push('styles')
<style>
.contact{max-width:840px;padding-block:48px}.contact h1{font-size:clamp(32px,5vw,50px);color:var(--wa-primary)}
.contact form,.contact-notice{background:#fff;border:1px solid var(--wa-soft-border);padding:24px;border-radius:18px}
.contact label{display:block;font-weight:700;margin-top:14px}.contact input,.contact textarea{display:block;width:100%;padding:10px;border:1px solid #97B4C8;border-radius:8px;font:inherit;min-height:44px}
.contact textarea{min-height:140px}.contact button{margin-top:18px;min-height:44px;background:var(--wa-cta);color:#fff;border:0;border-radius:10px;padding:10px 20px;font:inherit;font-weight:700;cursor:pointer}
.contact .contact-error-summary{border:2px solid #9D2735;border-radius:10px;padding:14px 18px;margin-bottom:18px}.contact .contact-error-summary a{text-decoration:underline;text-underline-offset:3px}.contact .contact-error-summary a:focus-visible{outline:3px solid var(--wa-primary);outline-offset:3px}.contact .contact-again{display:inline-flex;align-items:center;min-height:44px;margin-top:12px;text-decoration:underline;text-underline-offset:3px;font-weight:700}.contact .contact-again:focus-visible{outline:3px solid var(--wa-primary);outline-offset:3px}.contact-trap{position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden}.contact-error{color:#9D2735}
.contact-project-context a{display:inline-flex;align-items:center;min-height:44px;color:var(--wa-cta);font-weight:700;overflow-wrap:anywhere}
.contact-project-context a:focus-visible{outline:3px solid var(--wa-cta);outline-offset:3px}
.contact-track-context a{display:inline-flex;align-items:center;min-height:44px;color:var(--wa-cta);font-weight:700;overflow-wrap:anywhere}
.contact-track-context a:focus-visible{outline:3px solid var(--wa-cta);outline-offset:3px}
.contact .contact-consent{display:flex;gap:12px;align-items:flex-start;line-height:1.55;cursor:pointer}
.contact .contact-consent input{display:inline-block;width:20px;height:20px;min-height:20px;flex:none;margin:3px 0 0;accent-color:var(--wa-cta)}
</style>
@endpush
@section('content')
<section class="site-wrap contact" aria-labelledby="contact-heading">
<h1 id="contact-heading">{{ $locale === 'ar' ? 'التواصل' : 'Contact' }}</h1>
<p>{{ $locale === 'ar' ? 'تُستخدم بيانات الرسالة فقط للرد على طلب التواصل، ولا تنشر على الموقع.' : 'Messages are used only to reply to the inquiry; they are not published.' }}</p>
@if($reportArticle)
<p class="contact-article-context">
    {{ $locale === 'ar' ? 'الإبلاغ عن مشكلة في المقال المنشور:' : 'Reporting an issue in the published article:' }}
    <a href="{{ route('public.article', ['slug' => $reportArticle->slug, 'lang' => $locale]) }}">{{ $locale === 'ar' ? $reportArticle->title_ar : $reportArticle->title_en }}</a>
</p>
@endif
@if($reportProject)
<p class="contact-project-context">
    {{ $locale === 'ar' ? 'استفسار عن دراسة الحالة المنشورة والموثقة:' : 'Inquiry about the published, verified case study:' }}
    <a href="{{ route('public.project', ['slug' => $reportProject['slug'], 'lang' => $locale]) }}">{{ $reportProject['name'] }}</a>
</p>
@endif
@if($contactTrack)
<p class="contact-track-context">
    {{ $locale === 'ar' ? 'موضوع الاستفسار المختار (لا يُعد توثيقًا للخبرة):' : 'Selected inquiry topic (not proof of professional expertise):' }}
    <a href="{{ route('public.hire', ['lang' => $locale, 'track' => $contactTrack]) }}">{{ $contactTrackLabels[$contactTrack] }}</a>
</p>
@endif
@if(session('contact_delivered'))
<div class="contact-notice" role="status">{{ $locale === 'ar' ? 'تم تسليم الرسالة لخادم البريد المهيأ.' : 'The message was handed to the configured mail transport.' }}</div>
<p><a class="contact-again" href="{{ route('public.contact', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'إرسال رسالة أخرى' : 'Send another message' }}</a></p>
@elseif(!$available)
<div class="contact-notice" role="status">{{ $locale === 'ar' ? 'استقبال الرسائل غير مفعل حتى تهيئة البريد والتحقق منه.' : 'Contact delivery is unavailable until a verified mail transport and recipient are configured.' }}</div>
@else
<form method="post" action="{{ route('public.contact.submit', ['lang'=>$locale]) }}">
@csrf
@if($errors->any())
<div class="contact-error-summary" role="alert" aria-labelledby="contact-error-summary-heading">
<h2 id="contact-error-summary-heading">{{ $locale === 'ar' ? 'يرجى تصحيح الحقول التالية' : 'Please correct the following fields' }}</h2>
<ul>
@foreach(['name', 'email', 'subject', 'message', 'privacy_consent'] as $field)
@if($errors->has($field))
<li><a href="#{{ $field === 'privacy_consent' ? 'contact-privacy-consent' : 'contact-'.$field }}">{{ match($field) {
'name' => $locale === 'ar' ? 'الاسم' : 'Name',
'email' => $locale === 'ar' ? 'البريد' : 'Email',
'subject' => $locale === 'ar' ? 'الموضوع' : 'Subject',
'message' => $locale === 'ar' ? 'الرسالة' : 'Message',
default => $locale === 'ar' ? 'الموافقة على الخصوصية' : 'Privacy consent'
} }}</a></li>
@endif
@endforeach
</ul>
</div>
@endif
<div class="contact-trap" aria-hidden="true"><label for="website">Leave blank</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
@foreach(['name','email','subject'] as $field)
<label for="contact-{{ $field }}">{{ match($field) {
'name' => $locale === 'ar' ? 'الاسم' : 'Name',
'email' => $locale === 'ar' ? 'البريد' : 'Email',
default => $locale === 'ar' ? 'الموضوع' : 'Subject' } }}</label>
@php($oldFieldValue = old($field, $field === 'subject' ? $suggestedSubject : ''))
<input id="contact-{{ $field }}" type="{{ $field === 'email' ? 'email' : 'text' }}" name="{{ $field }}" value="{{ is_string($oldFieldValue) ? $oldFieldValue : '' }}" required maxlength="{{ $field === 'email' ? 254 : 140 }}" @error($field) aria-invalid="true" aria-describedby="contact-{{ $field }}-error" @enderror>
@error($field)<p id="contact-{{ $field }}-error" class="contact-error" role="alert">{{ $message }}</p>@enderror
@endforeach
<label for="contact-message">{{ $locale === 'ar' ? 'الرسالة' : 'Message' }}</label>
<textarea id="contact-message" name="message" required maxlength="3000" @error('message') aria-invalid="true" aria-describedby="contact-message-error" @enderror>{{ is_string(old('message')) ? old('message') : '' }}</textarea>
@error('message')<p id="contact-message-error" class="contact-error" role="alert">{{ $message }}</p>@enderror
<label class="contact-consent" for="contact-privacy-consent">
<input id="contact-privacy-consent" type="checkbox" name="privacy_consent" value="1" @checked(old('privacy_consent') === '1') @error('privacy_consent') aria-invalid="true" aria-describedby="contact-privacy-consent-error" @enderror>
<span>{{ $locale === 'ar' ? 'أوافق على استخدام اسمي وبريدي الإلكتروني ونص الرسالة للرد على استفساري فقط.' : 'I consent to using my name, email address and message solely to respond to my inquiry.' }}</span>
</label>
@error('privacy_consent')<p id="contact-privacy-consent-error" class="contact-error" role="alert">{{ $message }}</p>@enderror
<button type="submit">{{ $locale === 'ar' ? 'إرسال' : 'Send message' }}</button>
</form>
@endif
<p><a href="{{ route('public.github', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'مشاريع GitHub الموثقة' : 'Verified GitHub projects' }}</a></p>
</section>
@endsection
