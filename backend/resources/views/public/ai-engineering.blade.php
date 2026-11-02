@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')

@section('title', $locale === 'ar' ? 'هندسة الذكاء الاصطناعي — وليد عطية' : 'AI Engineering — Waleed Atiya')
@section('meta_description', $locale === 'ar'
    ? 'قدرات هندسة الذكاء الاصطناعي المتاحة بعد التحقق مع ملاحظات السلامة.'
    : 'Evidence-gated AI engineering capabilities with explicit safety boundaries.')

@section('content')
@include('public.t007-reference-stage', ['screen' => 'ai'])
<section class="site-wrap t007-functional" id="ai-evidence" aria-labelledby="ai-evidence-title">
    <h2 id="ai-evidence-title">{{ $locale === 'ar' ? 'القدرات المعتمدة للعرض' : 'Evidence-approved AI capabilities' }}</h2>
    @if(empty($aiData['skills']))
        <p role="status">{{ $locale === 'ar'
            ? 'لا توجد قدرات ذكاء اصطناعي اجتازت بوابة الأدلة للنشر حاليًا.'
            : 'No AI capabilities have cleared the public evidence gate yet.' }}</p>
    @else
        <ul>
            @foreach($aiData['skills'] as $skill)
                <li>{{ $skill['name'] }} <span>— {{ $skill['category'] }}</span></li>
            @endforeach
        </ul>
    @endif

    <h2>{{ $locale === 'ar' ? 'حدود السلامة' : 'Safety boundaries' }}</h2>
    <ul>
        @foreach($aiData['safety_notes'] as $note)
            <li>{{ $note }}</li>
        @endforeach
    </ul>

    @if(empty($aiData['verified_demos']) && empty($aiData['workflows']))
        <p>{{ $locale === 'ar'
            ? 'لا يتم عرض عروض أو تدفقات عمل على أنها موثقة دون ارتباط أدلة مستقل.'
            : 'No demo or workflow is presented as verified without an independently approved evidence association.' }}</p>
    @endif
    <p>
        <a href="{{ route('public.projects', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'دراسات الحالة الموثقة' : 'Verified case studies' }}</a>
        · <a href="{{ route('public.github', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'مشاريع GitHub العامة' : 'Public GitHub projects' }}</a>
    </p>
</section>
@endsection
