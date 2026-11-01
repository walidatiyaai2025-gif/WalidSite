@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
@section('title', $locale === 'ar' ? 'التواصل — وليد عطية' : 'Contact — Waleed Atiya')
@push('styles')
<style>
.contact{max-width:840px;padding-block:48px}.contact h1{font-size:clamp(32px,5vw,50px);color:var(--wa-primary)}
.contact form,.contact-notice{background:#fff;border:1px solid var(--wa-soft-border);padding:24px;border-radius:18px}
.contact label{display:block;font-weight:700;margin-top:14px}.contact input,.contact textarea{display:block;width:100%;padding:10px;border:1px solid #97B4C8;border-radius:8px;font:inherit;min-height:44px}
.contact textarea{min-height:140px}.contact button{margin-top:18px;min-height:44px;background:var(--wa-cta);color:#fff;border:0;border-radius:10px;padding:10px 20px;font:inherit;font-weight:700;cursor:pointer}
.contact-trap{position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden}.contact-error{color:#9D2735}
.contact .contact-consent{display:flex;gap:12px;align-items:flex-start;line-height:1.55;cursor:pointer}
.contact .contact-consent input{display:inline-block;width:20px;height:20px;min-height:20px;flex:none;margin:3px 0 0;accent-color:var(--wa-cta)}
</style>
@endpush
@section('content')
<section class="site-wrap contact" aria-labelledby="contact-heading">
<h1 id="contact-heading">{{ $locale === 'ar' ? 'التواصل' : 'Contact' }}</h1>
<p>{{ $locale === 'ar' ? 'تُستخدم بيانات الرسالة فقط للرد على طلب التواصل، ولا تنشر على الموقع.' : 'Messages are used only to reply to the inquiry; they are not published.' }}</p>
@if(session('contact_delivered'))
<div class="contact-notice" role="status">{{ $locale === 'ar' ? 'تم تسليم الرسالة لخادم البريد المهيأ.' : 'The message was handed to the configured mail transport.' }}</div>
@elseif(!$available)
<div class="contact-notice" role="status">{{ $locale === 'ar' ? 'استقبال الرسائل غير مفعل حتى تهيئة البريد والتحقق منه.' : 'Contact delivery is unavailable until a verified mail transport and recipient are configured.' }}</div>
@else
<form method="post" action="{{ route('public.contact.submit', ['lang'=>$locale]) }}">
@csrf
<div class="contact-trap" aria-hidden="true"><label for="website">Leave blank</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
@foreach(['name','email','subject'] as $field)
<label for="contact-{{ $field }}">{{ match($field) {
'name' => $locale === 'ar' ? 'الاسم' : 'Name',
'email' => $locale === 'ar' ? 'البريد' : 'Email',
default => $locale === 'ar' ? 'الموضوع' : 'Subject' } }}</label>
<input id="contact-{{ $field }}" type="{{ $field === 'email' ? 'email' : 'text' }}" name="{{ $field }}" value="{{ old($field) }}" required maxlength="{{ $field === 'email' ? 254 : 140 }}" @error($field) aria-invalid="true" aria-describedby="contact-{{ $field }}-error" @enderror>
@error($field)<p id="contact-{{ $field }}-error" class="contact-error" role="alert">{{ $message }}</p>@enderror
@endforeach
<label for="contact-message">{{ $locale === 'ar' ? 'الرسالة' : 'Message' }}</label>
<textarea id="contact-message" name="message" required maxlength="3000" @error('message') aria-invalid="true" aria-describedby="contact-message-error" @enderror>{{ old('message') }}</textarea>
@error('message')<p id="contact-message-error" class="contact-error" role="alert">{{ $message }}</p>@enderror
<label class="contact-consent" for="contact-privacy-consent">
<input id="contact-privacy-consent" type="checkbox" name="privacy_consent" value="1" @checked(old('privacy_consent') == '1') @error('privacy_consent') aria-invalid="true" aria-describedby="contact-privacy-consent-error" @enderror>
<span>{{ $locale === 'ar' ? 'أوافق على استخدام اسمي وبريدي الإلكتروني ونص الرسالة للرد على استفساري فقط.' : 'I consent to using my name, email address and message solely to respond to my inquiry.' }}</span>
</label>
@error('privacy_consent')<p id="contact-privacy-consent-error" class="contact-error" role="alert">{{ $message }}</p>@enderror
<button type="submit">{{ $locale === 'ar' ? 'إرسال' : 'Send message' }}</button>
</form>
@endif
<p><a href="{{ route('public.github', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'مشاريع GitHub الموثقة' : 'Verified GitHub projects' }}</a></p>
</section>
@endsection
