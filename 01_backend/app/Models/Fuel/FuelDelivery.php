<?php

namespace App\Models\Fuel;

use App\Models\FuelProduct;
use App\Models\FuelStation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * توريدُ وقود — **ثلاثةُ أحوالٍ والمخزونُ لا يرتفع إلّا بالثالث**.
 *
 *   received  ← وصلت الشاحنة وأُدخلت البيانات
 *   verified  ← قِيس الخزّان قبلُ وبعدُ وطابقت الكميّة
 *   posted    ← رُحِّل، **وهنا وحدَه يرتفع المخزون**
 *
 * ورفعُ المخزون عند الإدخال يجعل ورقةً مكتوبةً بالخطأ ترفع الرصيدَ
 * الدفتريَّ فوراً، فتظهر مصالحةُ الليلة عجزاً لا وجود له.
 */
class FuelDelivery extends Model
{
    protected $table = 'fuel_deliveries';

    protected $fillable = [
        'delivery_ulid', 'station_id', 'tank_id', 'fuel_product_id', 'supplier_id',
        'delivery_number', 'invoice_number',
        'quantity_liters', 'unit_cost', 'total_cost',
        'dip_before_liters', 'dip_after_liters',
        'status', 'received_by_user_id', 'verified_by_user_id', 'posted_by_user_id',
        'received_at', 'verified_at', 'posted_at', 'note', 'zone_code',
    ];

    protected $casts = [
        'station_id' => 'integer',
        'tank_id' => 'integer',
        'fuel_product_id' => 'integer',
        'supplier_id' => 'integer',
        'quantity_liters' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'dip_before_liters' => 'decimal:3',
        'dip_after_liters' => 'decimal:3',
        'received_at' => 'datetime',
        'verified_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public const STATUSES = ['received', 'verified', 'posted', 'rejected'];

    public function tank(): BelongsTo
    {
        return $this->belongsTo(FuelTank::class, 'tank_id');
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(FuelStation::class, 'station_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(FuelProduct::class, 'fuel_product_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FuelSupplier::class, 'supplier_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    /**
     * فرقُ ما كُتب عمّا قِيس في الخزّان.
     *
     * ويبقى `null` إن لم يُقَس — **«غير معروف» ليس صفراً**، وصفرٌ هنا
     * يُقرأ «قِيس فطابق».
     */
    public function measuredVariance(): ?string
    {
        if ($this->dip_before_liters === null || $this->dip_after_liters === null) {
            return null;
        }

        $measured = bcsub((string) $this->dip_after_liters,
            (string) $this->dip_before_liters, 3);

        return bcsub($measured, (string) $this->quantity_liters, 3);
    }
}
