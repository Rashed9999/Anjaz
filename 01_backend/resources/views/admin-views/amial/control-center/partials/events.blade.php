<section class="acc-panel" data-testid="control-center-audit-log">
    <div class="acc-panel-title">◷ سجل القرارات <small>أحداث حقيقية من قاعدة البيانات</small></div>
    @if($events->isEmpty())
        <p class="acc-mute">لا توجد قرارات مسجلة في مركز التحكم حتى الآن. لن تُعرض أحداث افتراضية.</p>
    @else
        <div class="acc-audit">
            @foreach($events->take(12) as $event)
                <div class="acc-audit-row">
                    <strong>{{ [
                        'REQUESTED' => 'طُلب تغيير تشغيلي',
                        'ENABLED' => 'تم تشغيل الخدمة',
                        'DISABLED' => 'تم إيقاف الخدمة',
                        'APPROVED' => 'اعتمد موظف ثانٍ التغيير',
                        'REJECTED' => 'رُفض التغيير',
                    ][$event->action] ?? $event->action }}</strong>
                    <p>{{ $event->feature_key }} · الموظف #{{ $event->actor_id }}
                        @if($event->request_id) · الطلب #{{ $event->request_id }} @endif
                    </p>
                    <p>{{ \Illuminate\Support\Str::limit($event->reason, 130) }}</p>
                    <small class="acc-mute">{{ $event->created_at }}</small>
                </div>
            @endforeach
        </div>
    @endif
</section>
