@extends('layouts.admin.app')

@section('title', translate('Publish new legal terms version'))

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="{{ route('admin.amial.legal.index') }}" class="btn btn-soft-secondary btn-sm">
            <i class="tio-back-ui"></i>
        </a>
        <h2 class="page-header-title mb-0">{{ translate('Publish new legal terms version') }}</h2>
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            <strong>{{ translate('Validation errors:') }}</strong>
            @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
        </div>
    @endif

    <div class="alert alert-warning">
        <i class="tio-warning"></i>
        <strong>{{ translate('Important:') }}</strong>
        {{ translate('Publishing a new version automatically supersedes the current version for the same locale. All existing users will be required to accept the new version before their next financial operation.') }}
    </div>

    <div class="card">
        <div class="card-body">
            <form action="{{ route('admin.amial.legal.store') }}" method="POST">
                @csrf

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label required-mark">{{ translate('Version') }}</label>
                        <input type="text" name="version" class="form-control" required
                               placeholder="1.0" pattern="^\d+(\.\d+)*$"
                               value="{{ old('version') }}">
                        <small class="form-text text-muted">{{ translate('Semver format e.g. 1.0, 2.1, 3.0.1') }}</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required-mark">{{ translate('Locale') }}</label>
                        <select name="locale" class="form-control" required>
                            <option value="ar" {{ old('locale', 'ar') == 'ar' ? 'selected' : '' }}>العربية (ar)</option>
                            <option value="en" {{ old('locale') == 'en' ? 'selected' : '' }}>English (en)</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label required-mark">{{ translate('Title') }}</label>
                    <input type="text" name="title" class="form-control" required maxlength="255"
                           placeholder="{{ translate('e.g., Amial Pay Terms of Use - v1.0') }}"
                           value="{{ old('title') }}">
                </div>

                <div class="mb-3">
                    <label class="form-label required-mark">{{ translate('Content (full policy text)') }}</label>
                    <textarea name="content" class="form-control" rows="18" required minlength="50">{{ old('content') }}</textarea>
                    <small class="form-text text-muted">
                        {{ translate('Plain text or Markdown. The mobile app renders it as text. HTML is not rendered for security.') }}
                    </small>
                </div>

                <div class="mb-3">
                    <label class="form-label">{{ translate('Changelog (what changed in this version)') }}</label>
                    <textarea name="changelog" class="form-control" rows="3" maxlength="2000"
                              placeholder="{{ translate('e.g., Updated fees section, added zone policy clause') }}">{{ old('changelog') }}</textarea>
                </div>

                <div class="d-flex gap-2 justify-content-end">
                    <a href="{{ route('admin.amial.legal.index') }}" class="btn btn-secondary">
                        {{ translate('Cancel') }}
                    </a>
                    <button type="submit" class="btn btn-primary"
                            onclick="return confirm('{{ translate('Confirm publishing? This will require all users to re-accept.') }}')">
                        <i class="tio-checkmark"></i> {{ translate('Publish version') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
