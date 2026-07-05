@extends('layouts.admin.app')

@section('title', translate('New fee version') . ' — Amial Pay')

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="{{ route('admin.amial.fees.index') }}" class="btn btn-soft-secondary btn-sm"><i class="tio-back-ui"></i></a>
        <h2 class="page-header-title mb-0">{{ translate('New fee version') }}</h2>
        <span class="badge badge-soft-info ms-auto">AMIAL-FEE-ENGINE-001</span>
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            <strong>{{ translate('Validation errors:') }}</strong>
            @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
        </div>
    @endif

    @if($current)
        <div class="alert alert-soft-warning">
            {{ translate('Current active version') }}: <strong>v{{ $current->version }}</strong> —
            {{ rtrim(rtrim((string)$current->percent_rate,'0'),'.') }}% + {{ rtrim(rtrim((string)$current->fixed_amount,'0'),'.') }}.
            {{ translate('Saving will supersede it with a new version.') }}
        </div>
    @endif

    <div class="row">
        <div class="col-lg-7">
            <form action="{{ route('admin.amial.fees.store') }}" method="POST" id="feeForm">
                @csrf
                <div class="card">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="input-label">{{ translate('Operation') }}</label>
                                <select name="code" id="f_code" class="form-control" required>
                                    @foreach($codes as $c)
                                        <option value="{{ $c }}" @selected(old('code', $prefillCode) === $c)>{{ $c }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="input-label">{{ translate('Applies to') }}</label>
                                <select name="applies_to" id="f_applies" class="form-control" required>
                                    @foreach($appliesTo as $a)
                                        <option value="{{ $a }}" @selected(old('applies_to', $current?->applies_to ?? 'customer') === $a)>{{ translate($a) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="input-label">{{ translate('Label') }}</label>
                                <input type="text" name="label" class="form-control" value="{{ old('label', $current?->label) }}" maxlength="120">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="input-label">{{ translate('Zone') }}</label>
                                <input type="text" name="zone_code" class="form-control" value="{{ old('zone_code', $current?->zone_code ?? 'SOUTH') }}" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="input-label">{{ translate('Fee type') }}</label>
                                <select name="fee_type" id="f_type" class="form-control" required>
                                    @foreach($feeTypes as $t)
                                        <option value="{{ $t }}" @selected(old('fee_type', $current?->fee_type ?? 'percent') === $t)>{{ $t }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="input-label">{{ translate('Bearer (who pays)') }}</label>
                                <select name="bearer" id="f_bearer" class="form-control" required>
                                    @foreach($bearers as $b)
                                        <option value="{{ $b }}" @selected(old('bearer', $current?->bearer ?? 'sender') === $b)>{{ translate($b) }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="input-label">{{ translate('Percent rate (%)') }}</label>
                                <input type="number" step="0.0001" min="0" max="100" name="percent_rate" id="f_percent"
                                       class="form-control" value="{{ old('percent_rate', $current?->percent_rate ?? '0') }}" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="input-label">{{ translate('Fixed amount') }}</label>
                                <input type="number" step="0.0001" min="0" name="fixed_amount" id="f_fixed"
                                       class="form-control" value="{{ old('fixed_amount', $current?->fixed_amount ?? '0') }}" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="input-label">{{ translate('Min fee (optional)') }}</label>
                                <input type="number" step="0.0001" min="0" name="min_fee" id="f_min"
                                       class="form-control" value="{{ old('min_fee', $current?->min_fee) }}">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="input-label">{{ translate('Max fee / cap (optional)') }}</label>
                                <input type="number" step="0.0001" min="0" name="max_fee" id="f_max"
                                       class="form-control" value="{{ old('max_fee', $current?->max_fee) }}">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="input-label">{{ translate('Agent commission (%)') }}</label>
                                <input type="number" step="0.0001" min="0" max="100" name="agent_commission_percent" id="f_agentp"
                                       class="form-control" value="{{ old('agent_commission_percent', $current?->agent_commission_percent ?? '0') }}" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="input-label">{{ translate('Agent commission (fixed)') }}</label>
                                <input type="number" step="0.0001" min="0" name="agent_commission_fixed" id="f_agentf"
                                       class="form-control" value="{{ old('agent_commission_fixed', $current?->agent_commission_fixed ?? '0') }}" required>
                            </div>

                            <div class="col-12 mb-3">
                                <label class="input-label">{{ translate('Notes') }}</label>
                                <textarea name="notes" class="form-control" rows="2" maxlength="1000">{{ old('notes') }}</textarea>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer text-end">
                        <a href="{{ route('admin.amial.fees.index') }}" class="btn btn-soft-secondary">{{ translate('Cancel') }}</a>
                        <button type="submit" class="btn btn-primary" style="background:#0B435B;border-color:#0B435B">
                            <i class="tio-save"></i> {{ translate('Save new version') }}
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="col-lg-5">
            <div class="card" style="border-top:3px solid #F9C715">
                <div class="card-header"><h5 class="card-header-title">{{ translate('Live simulator') }}</h5></div>
                <div class="card-body">
                    <label class="input-label">{{ translate('Test amount') }}</label>
                    <input type="number" step="0.01" min="0" id="sim_amount" class="form-control mb-3" value="1000">
                    <button type="button" id="sim_btn" class="btn btn-block btn-soft-primary mb-3">
                        <i class="tio-calculator"></i> {{ translate('Calculate') }}
                    </button>
                    <div id="sim_error" class="alert alert-danger d-none"></div>
                    <table class="table table-sm table-borderless mb-0" id="sim_table" style="display:none">
                        <tbody>
                        <tr><td>{{ translate('Fee') }}</td><td class="text-end fw-bold" id="r_fee">—</td></tr>
                        <tr><td>{{ translate('Platform profit') }}</td><td class="text-end fw-bold" id="r_profit" style="color:#0B435B">—</td></tr>
                        <tr><td>{{ translate('Agent commission') }}</td><td class="text-end" id="r_agent">—</td></tr>
                        <tr class="border-top"><td>{{ translate('Total debited from payer') }}</td><td class="text-end" id="r_debit">—</td></tr>
                        <tr><td>{{ translate('Net credited to receiver') }}</td><td class="text-end" id="r_credit">—</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const btn = document.getElementById('sim_btn');
    const errBox = document.getElementById('sim_error');
    const table = document.getElementById('sim_table');

    function val(id) { return document.getElementById(id).value; }

    btn.addEventListener('click', function () {
        errBox.classList.add('d-none');
        const payload = {
            amount: val('sim_amount'),
            code: val('f_code'),
            zone_code: 'SOUTH',
            applies_to: val('f_applies'),
            fee_type: val('f_type'),
            percent_rate: val('f_percent'),
            fixed_amount: val('f_fixed'),
            min_fee: val('f_min'),
            max_fee: val('f_max'),
            agent_commission_percent: val('f_agentp'),
            agent_commission_fixed: val('f_agentf'),
            bearer: val('f_bearer'),
        };

        fetch('{{ route('admin.amial.fees.simulate') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
        })
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                errBox.textContent = d.message || 'Error';
                errBox.classList.remove('d-none');
                table.style.display = 'none';
                return;
            }
            const r = d.result;
            document.getElementById('r_fee').textContent = r.fee;
            document.getElementById('r_profit').textContent = r.platform_profit;
            document.getElementById('r_agent').textContent = r.agent_commission;
            document.getElementById('r_debit').textContent = r.total_debit;
            document.getElementById('r_credit').textContent = r.net_credit;
            table.style.display = 'table';
        })
        .catch(e => {
            errBox.textContent = e.message;
            errBox.classList.remove('d-none');
        });
    });
})();
</script>
@endsection
