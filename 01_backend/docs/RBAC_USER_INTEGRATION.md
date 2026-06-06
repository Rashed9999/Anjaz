# دمج HasRoles Trait في User Model

**AMIAL-RBAC-001 (v1.0-A)**

## الإضافة المطلوبة

في `app/Models/User.php`، أضف import:

```php
use App\Traits\HasRoles;
```

ثم في class declaration:

```php
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, HasRoles; // ← أضف HasRoles
    
    // ... باقي الكود كما هو
}
```

## التحقق

بعد الإضافة:

```bash
php artisan tinker
>>> $user = User::find(1);
>>> $user->hasRole('super_admin');
=> false
>>> $user->getCachedPermissionCodes();
=> []
```

## إنشاء super admin أول

```bash
php artisan tinker
>>> $user = User::where('phone', '+967700000001')->first();
>>> $role = \App\Models\Rbac\Role::where('code', 'super_admin')->first();
>>> $user->assignRole($role);
>>> $user->hasPermission('users.view');
=> true
```

## استخدام في Routes

```php
// قبل (بدون RBAC)
Route::get('/admin/users', [UserController::class, 'index']);

// بعد (مع RBAC)
Route::get('/admin/users', [UserController::class, 'index'])
    ->middleware('rbac:users.view');

// متعدد - OR
Route::post('/refund', [TxController::class, 'refund'])
    ->middleware('rbac:transactions.refund|transactions.reverse');

// متعدد - AND
Route::post('/sensitive-action', [Ctrl::class, 'sensitive'])
    ->middleware('rbac:users.edit,users.suspend');
```

## الترحيل التدريجي للـ Admin routes

في `routes/web.php` و `routes/admin/amial.php` و كل admin routes:
- أضف middleware `rbac:...` تدريجياً بدون إزالة `admin` middleware
- ابدأ بأهم الـ endpoints (refund, KYC approve, role assign)
- الـ super_admin يتجاوز كل فحص — يستمر بالعمل أثناء الترحيل
