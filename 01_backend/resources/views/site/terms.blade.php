@extends('site.layout')

@section('title', 'شروط الاستخدام')
@section('desc', 'شروط استخدام خدمات أميال باي.')

@section('body')

<section class="page-head">
    <div class="wrap">
        <h1>شروط الاستخدام</h1>
        <p>آخر تحديث: {{ date('Y/m/d') }}</p>
    </div>
</section>

<section class="section">
    <div class="wrap prose">
        @include('site._terms-content')
    </div>
</section>

@endsection
