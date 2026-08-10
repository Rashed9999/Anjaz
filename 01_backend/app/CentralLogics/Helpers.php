<?php

namespace App\CentralLogics;

use Carbon\Carbon;

use App\Models\User;
use App\Models\Bonus;
use App\Models\EMoney;
use App\Models\Currency;
use App\Models\Transaction;
use Carbon\CarbonInterval;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;
use App\Models\BusinessSetting;
use App\Models\TransactionLimit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use App\Exceptions\TransactionFailedException;
use Laravelpkg\Laravelchk\Http\Controllers\LaravelchkController;

class Helpers
{
    public static function sendNotificationToHttp(array $data): bool
    {
        try {
            $config = self::get_business_settings('push_notification_service_file_content');
            $key = (array)$config;
            $url = 'https://fcm.googleapis.com/v1/projects/'.$key['project_id'].'/messages:send';
            $headers = [
                'Authorization' => 'Bearer ' . self::getAccessToken($key),
                'Content-Type' => 'application/json',
            ];

            Http::withHeaders($headers)->post($url, $data);
            return true;
        }catch (\Exception $exception){
            return false;
        }
    }

    public static function getAccessToken(array $key): ?string
    {
        $jwtToken = [
            'iss' => $key['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => time() + 3600,
            'iat' => time(),
        ];
        $jwtHeader = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $jwtPayload = base64_encode(json_encode($jwtToken));
        $unsignedJwt = $jwtHeader . '.' . $jwtPayload;
        openssl_sign($unsignedJwt, $signature, $key['private_key'], OPENSSL_ALGO_SHA256);
        $jwt = $unsignedJwt . '.' . base64_encode($signature);

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        return $response->json('access_token');
    }

    public static function send_push_notif_to_device(?string $fcm_token, array $data): bool
    {
        $postData = [
            'message' => [
                "token" => $fcm_token,
                "data" => [
                    "title" => (string)$data['title'],
                    "body" => (string)$data['description'],
                    "image" => (string)$data['image'],
                    "type" => (string)$data['type']
                ],
                "notification" => [
                    'title' => (string)$data['title'],
                    'body' => (string)$data['description'],
                ],
                "apns" => [
                    "payload" => [
                        "aps" => [
                            "sound" => "notification.wav"
                        ]
                    ]
                ],
            ]
        ];

        return self::sendNotificationToHttp($postData);
    }

    public static function send_push_notif_to_topic(array $data): bool
    {
        $image = dynamicStorage(path: 'storage/app/public/notification') . '/' . $data['image'];
        $postData = [
            'message' => [
                "topic" => $data['receiver'],
                "data" => [
                    "title" => (string)$data['title'],
                    "body" => (string)$data['description'],
                    "image" => (string)$image,
                    "type" => (string)$data['type']
                ],
                "notification" => [
                    "title" => (string)$data['title'],
                    "body" => (string)$data['description'],
                    "image" => (string)$image,
                ],
                "apns" => [
                    "payload" => [
                        "aps" => [
                            "sound" => "notification.wav"
                        ]
                    ]
                ],
            ]
        ];

        return self::sendNotificationToHttp($postData);
    }

    public static function order_status_update_message(string $status): string
    {
        if ($status == 'money_transfer_message') {
            $data = self::get_business_settings('money_transfer_message');

        } elseif ($status == CASH_IN) {
            $data = self::get_business_settings(CASH_IN);

        } elseif ($status == CASH_OUT) {
            $data = self::get_business_settings(CASH_OUT);

        }  elseif ($status == SEND_MONEY) {
            $data = self::get_business_settings(SEND_MONEY);

        }  elseif ($status == 'request_money') {
            $data = self::get_business_settings('request_money');

        }  elseif ($status == 'denied_money') {
            $data = self::get_business_settings('denied_money');

        }  elseif ($status == 'approved_money') {
            $data = self::get_business_settings('approved_money');

        } elseif ($status == ADD_MONEY) {
            $data = self::get_business_settings(ADD_MONEY);

        } elseif ($status == ADD_MONEY_BONUS) {
            $data = self::get_business_settings(ADD_MONEY_BONUS);

        } elseif ($status == RECEIVED_MONEY) {
            $data = self::get_business_settings(RECEIVED_MONEY);

        } elseif ($status == PAYMENT) {
            $data = self::get_business_settings(PAYMENT);

        } else {
            $data['status'] = 0;
            $data['message'] = '';
        }

        if ($data == null || (array_key_exists('status', $data) && $data['status'] == 0)) {
            $data['status'] = 0;
            $data['message'] = '';
        }

        return $data['message'];
    }

    public static function upload(string $dir, string $format = APPLICATION_IMAGE_FORMAT, array|object|null $image = null) {
        if (!$image) {
            return null;
        }

        set_time_limit(300);

        $dir = rtrim($dir, '/') . '/';
        $sourcePath = $image instanceof UploadedFile
            ? $image->getRealPath()
            : $image;

        $info = @getimagesize($sourcePath);
        if (!$info || empty($info['mime'])) {
            return false;
        }

        $mime = strtolower($info['mime']);

        // Detect format safely
        $format = match ($mime) {
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => $format,
        };

        $imageName = Carbon::now()->format('Y-m-d') . '-' . uniqid() . '.' . $format;

        // Ensure directory exists
        if (!Storage::disk('public')->exists($dir)) {
            Storage::disk('public')->makeDirectory($dir);
        }

        $savePath = storage_path("app/public/{$dir}{$imageName}");

        /**
         * 🚨 IMPORTANT
         * Never process GIF with GD (animation will break)
         */
        if ($mime === 'image/gif') {
            return copy($sourcePath, $savePath) ? $imageName : false;
        }

        /**
         * WEBP copy-only if already webp
         */
        if ($mime === 'image/webp' && $format === 'webp') {
            return copy($sourcePath, $savePath) ? $imageName : false;
        }

        /**
         * Create GD image
         */
        $gdImage = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($sourcePath),
            'image/png'  => imagecreatefrompng($sourcePath),
            'image/webp' => imagecreatefromwebp($sourcePath),
            default      => false,
        };

        if (!$gdImage) {
            return false;
        }

        /**
         * Preserve transparency
         */
        if (in_array($mime, ['image/png', 'image/webp'])) {
            imagealphablending($gdImage, false);
            imagesavealpha($gdImage, true);
        }

        /**
         * Resize logic
         */
        $maxSize = 2500;
        $width   = imagesx($gdImage);
        $height  = imagesy($gdImage);

        if ($width > $maxSize || $height > $maxSize) {
            $ratio = min($maxSize / $width, $maxSize / $height);
            $newW  = (int)($width * $ratio);
            $newH  = (int)($height * $ratio);

            $temp = imagecreatetruecolor($newW, $newH);

            if (in_array($mime, ['image/png', 'image/webp'])) {
                imagealphablending($temp, false);
                imagesavealpha($temp, true);
            }

            imagecopyresampled(
                $temp,
                $gdImage,
                0,
                0,
                0,
                0,
                $newW,
                $newH,
                $width,
                $height
            );

            imagedestroy($gdImage);
            $gdImage = $temp;
        }

        /**
         * Save image
         */
        $saved = match ($format) {
            'jpg', 'jpeg' => imagejpeg($gdImage, $savePath, 85),
            'png'         => imagepng($gdImage, $savePath, -1),
            'webp'        => imagewebp($gdImage, $savePath, 78),
            default       => false,
        };

        imagedestroy($gdImage);

        return $saved ? $imageName : false;
    }

    public static function update(string $dir, ?string $old_image, string $format, ?object $image = null): string
    {
        if ($image == null) {
            return $old_image;
        }
        if (Storage::disk('public')->exists($dir . $old_image)) {
            Storage::disk('public')->delete($dir . $old_image);
        }

        return Helpers::upload($dir, $format, $image);
    }

    public static function error_processor(object $validator): array
    {
        $err_keeper = [];

        foreach ($validator->errors()->getMessages() as $field => $messages) {
            $err_keeper[] = [
                'code' => $field,
                'message' => $messages[0]
            ];
        }

        return $err_keeper;
    }

    public static function response_formatter(array $constant, mixed $content = null, array $errors = []): array
    {
        $constant['content'] = $content;
        $constant['errors'] = $errors;

        return $constant;
    }

    public static function file_uploader(string $dir, string $format, ?object $image = null, ?string $old_image = null): string
    {
        if ($image == null) return $old_image ?? 'def.png';

        if (isset($old_image)) Storage::disk('public')->delete($dir . $old_image);

        $imageName = Carbon::now()->toDateString() . "-" . uniqid() . "." . $format;
        if (!Storage::disk('public')->exists($dir)) {
            Storage::disk('public')->makeDirectory($dir);
        }
        Storage::disk('public')->put($dir . $imageName, file_get_contents($image));

        return $imageName;
    }

    public static function currency_code(): string
    {
        // AMIAL-FIX: افتراضي «ريال يمني» (كان USD/SAR)، وآمن ضدّ null (كان
        // ->first()->value ينهار إن غاب الإعداد).
        return optional(BusinessSetting::where(['key' => 'currency'])->first())->value ?: 'YER';
    }

    public static function currency_symbol(): string
    {
        return Currency::where(['currency_code' => Helpers::currency_code()])
            ->first()?->currency_symbol ?? '$';
    }

    public static function set_symbol(float $amount): string
    {
        $position = Helpers::get_business_settings('currency_symbol_position');
        if (!is_null($position) && $position == 'left') {
            $string = self::currency_symbol() . '' . number_format($amount, 2);
        } else {
            $string = number_format($amount, 2) . '' . self::currency_symbol();
        }
        return $string;
    }

    /**
     * AMIAL-MONEY-FMT-001: تنسيق مبلغ للعرض النصّي (إشعارات/رسائل).
     * الأعمدة المالية decimal:4، فإدراجها خاماً يُظهر «13500.0000» للمستخدم.
     * يُعيد فواصل آلاف بلا أصفار زائدة: 13,500 — و 13,500.5 عند وجود كسر.
     */
    public static function money(mixed $amount): string
    {
        $n = is_numeric($amount) ? (float) $amount : 0.0;
        $s = number_format($n, 2, '.', ',');
        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }
        return $s;
    }

    public static function get_business_settings(string $name): mixed
    {
        $config = null;
        $data = BusinessSetting::where(['key' => $name])->first();
        if (isset($data)) {
            $config = json_decode($data['value'], true);

            if (is_null($config)) {
                $config = $data['value'];
            }
        }
        return $config;
    }

    public static function remove_invalid_charcaters(string $str): string
    {
        return str_ireplace(['\'', '"', ';', '<', '>', '?'], ' ', $str);
    }

    public static function pagination_limit(): int
    {
        $limit = self::get_business_settings('pagination_limit');
        return isset($limit) && $limit > 0 ? $limit : 25;
    }

    public static function delete(string $full_path): array
    {
        if (Storage::disk('public')->exists($full_path)) {
            Storage::disk('public')->delete($full_path);
        }

        return [
            'success' => 1,
            'message' => 'Removed successfully !'
        ];
    }

    public static function pin_check(int $user_id, string $pin): bool
    {
        $user = User::find($user_id);
        if (!$user) {
            return false;
        }

        // AMIAL-FIX(PIN): أميال باي يفصل كلمة سرّ الدخول عن رمز المعاملات (4 أرقام).
        // نتحقّق أولاً من transaction_pin المخصّص (مُعمّى أو نصّاً)، ثم نُبقي
        // توافق 6cash (الرمز = كلمة السرّ) كخيار احتياطي حتى لا نكسر حسابات قائمة.
        if (!empty($user->transaction_pin)) {
            try {
                if (Hash::check($pin, $user->transaction_pin)) {
                    return true;
                }
            } catch (\Throwable $e) {
                // transaction_pin غير مُعمّى (نصّي) — نقارن نصّاً أدناه
            }
            if (hash_equals((string) $user->transaction_pin, (string) $pin)) {
                return true;
            }
        }

        return Hash::check($pin, $user->password);
    }

    public static function get_qrcode(array $data): string
    {
        return QrCode::size(70)->generate(json_encode([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'type' => $data['type'] != 0 ? ($data['type'] == 1 ? 'agent' : 'customer') : null,
            'image' => $data['image'] ?? ''
        ]));
    }

    public static function get_qrcode_by_phone(string $phone): string
    {
        $user = User::where('phone', $phone)->first();
        $qrcode = QrCode::size(70)->generate(json_encode([
            'name' => $user['f_name'] . ' ' . $user['l_name'],
            'phone' => $user['phone'],
            'type' => $user['type'] != 0 ? ($user['type'] == 1 ? 'agent' : 'customer') : null,
            'image' => $user['image'] ?? ''
        ]));
        return $qrcode;
    }

    public static function filter_phone(string $phone): string
    {
        return  str_replace([' ', '-'], '', $phone);
    }

    public static function get_language_name(string $key): string
    {
        $values = Helpers::get_business_settings('language');

        foreach ($values as $value) {
            if ($value['code'] == $key) {
                $key = $value['name'];
            }
        }

        return $key;
    }

    public static function language_load(): ?object
    {
        if (\session()->has('language_settings')) {
            $language = \session('language_settings');
        } else {
            $language = BusinessSetting::where('key', 'language')->first();
            \session()->put('language_settings', $language);
        }
        return $language;
    }

    public static function get_cashout_charge(float $amount): float
    {
        if ($amount <= 0) return $amount;
        $charge_in_percent = (float)self::get_business_settings('cashout_charge_percent');
        return ($amount * $charge_in_percent) / 100;
    }

    public static function get_sendmoney_charge(): float
    {
        $sendmoney_charge = (float) self::get_business_settings('sendmoney_charge_flat');
        return $sendmoney_charge;
    }

    public static function get_withdraw_charge(float $amount): float
    {
        $charge = self::get_business_settings('withdraw_charge_percent');

        if ($charge > 0) {
            return ($amount * $charge)/100;
        }
        return 0;
    }

    public static function get_add_money_bonus(float $amount, int $user_id, string $user_type): float
    {
        //date, user type, min amount check
        $currentDateTime = Carbon::now();
        $bonuses = Bonus::where('is_active', 1)
        ->where('start_date_time', '<=', $currentDateTime->format('Y-m-d H:i:s'))
        ->where('end_date_time', '>=', $currentDateTime->format('Y-m-d H:i:s'))
        ->whereIn('user_type', ['all', $user_type])
        ->where('min_add_money_amount', '<=', $amount)
        ->get();

        foreach ($bonuses as $key=>$item) {
            $used_count = Transaction::where('to_user_id', $user_id)->where('bonus_id', $item->id)->count();

            //limit check
            if ($used_count >= $item->limit_per_user) {
                $bonuses->forget($key);
            }
        }

        $bonuses = $bonuses->where('min_add_money_amount', $bonuses->max('min_add_money_amount'));

        foreach ($bonuses as $key=>$item) {
            $item->applied_bonus_amount = $item->bonus_type == 'percentage' ? ($amount*$item->bonus)/100 : $item->bonus;

            //max bonus check
            if($item->bonus_type == 'percentage' && $item->applied_bonus_amount > $item->max_bonus_amount) {
                $item->applied_bonus_amount = $item->max_bonus_amount;
            }
        }

        return $bonuses->max('applied_bonus_amount') ?? 0;
    }

    public static function get_applied_add_money_bonus(float $amount, int $user_id, string $user_type): ?Bonus
    {
        $currentDateTime = Carbon::now();
        $bonuses = Bonus::where('is_active', 1)
        ->where('start_date_time', '<=', $currentDateTime->format('Y-m-d H:i:s'))
        ->where('end_date_time', '>=', $currentDateTime->format('Y-m-d H:i:s'))
            ->whereIn('user_type', ['all', $user_type])
            ->where('min_add_money_amount', '<=', $amount)
            ->get();

        foreach ($bonuses as $key=>$item) {
            $used_count = Transaction::where('to_user_id', $user_id)->where('bonus_id', $item->id)->count();

            //limit check
            if ($used_count >= $item->limit_per_user) {
                $bonuses->forget($key);
            }
        }


        foreach ($bonuses as $key=>$item) {
            $item->applied_bonus_amount = $item->bonus_type == 'percentage' ? ($amount*$item->bonus)/100 : $item->bonus;

            //max bonus check
            if($item->bonus_type == 'percentage' && $item->applied_bonus_amount > $item->max_bonus_amount) {
                $item->applied_bonus_amount = $item->max_bonus_amount;
            }
        }

        $bonuses = $bonuses->where('min_add_money_amount', $bonuses->max('min_add_money_amount'));

        foreach ($bonuses as $key=>$item) {
            $item->applied_bonus_amount = $item->bonus_type == 'percentage' ? ($amount*$item->bonus)/100 : $item->bonus;

            //max bonus check
            $item->applied_bonus_amount = ($item->applied_bonus_amount <= $item->max_bonus_amount) ? $item->applied_bonus_amount : $item->max_bonus_amount;
        }

        return $bonuses->where('applied_bonus_amount', $bonuses->max('applied_bonus_amount'))->first() ?? null;
    }

    public static function get_agent_commission(float $amount): float
    {
        if ($amount <= 0) return $amount;
        $commission_in_percent = (float)(self::get_business_settings('agent_commission_percent') ?? 1);
        return ((float)$amount * $commission_in_percent) / 100;
    }

    public static function get_user_info(int $user_id): ?User
    {
        $user = User::find($user_id);

        if (isset($user)) {
            return $user;
        }

        return null;
    }

    public static function get_user_id(string $phone): int
    {
        // AMIAL-FIX: مطابقة صيغ الهاتف المكافئة (777… ↔ 967777…) وتفادي الانهيار
        // على null (كان ->first()->id يرمي إن اختلفت الصيغة أو غاب المستخدم).
        $user = User::whereIn('phone', \App\Support\Phone::variants($phone))->first();
        return (int) ($user?->id ?? 0);
    }

    public static function get_currency_symbol(): ?string
    {
        $currency_symbol = Currency::get()->first();

        return $currency_symbol?->currency_symbol;
    }

    public static function make_transaction(array $data): string
    {
        $user_id = $data['user_id'];
        $transaction_type = $data['transaction_type'];
        $amount = $data['amount'];
        $charge = isset($data['charge']) ? $data['charge'] : 0;
        $from_user_id = $data['from_user_id'];
        $note = isset($data['note']) ? $data['note'] : null;

        $ref_trans_id = $data['ref_trans_id'] ?? null;
        $debit = (strtolower($data['type']) == 'debit' ? $amount : 0);
        $credit = (strtolower($data['type']) == 'credit' ? $amount : 0);

        $to_user_id = $data['to_user_id'];

        $balance = self::updateEmoney($user_id, $amount, $data['type'], $transaction_type, $charge);

        try {
            $transfer = new Transaction();
            $transfer->user_id = $user_id;
            $transfer->ref_trans_id = $ref_trans_id;
            $transfer->transaction_type = $transaction_type;
            $transfer->debit = $debit;
            $transfer->credit = $credit;
            $transfer->balance = $balance;
            $transfer->from_user_id = $from_user_id;
            $transfer->to_user_id = strtolower($transaction_type) == ADMIN_CHARGE ? self::get_admin_id() : $to_user_id;
            $transfer->note = $note;
            $transfer->transaction_id = Str::random(5) . Carbon::now()->timestamp;
            $transfer->save();
            return $transfer->transaction_id;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public static function updateEmoney(int $user_id, float $amount, string $type, string $transaction_type, float $charge): float
    {
        $emoney_user_id = strtolower($transaction_type) === ADMIN_CHARGE ? 1 : $user_id;

        // ══════════════════════════════════════════════════════════════
        // AMIAL-LEGACY-WALLET-001 — **قراءةٌ ثمّ كتابةٌ بلا قفل.**
        //
        // كان السطر `EMoney::where(...)->firstOrFail()` ثمّ `+=` ثمّ
        // `save()`: ثلاثُ خطواتٍ تقرأ الرصيدَ وتحسبه وتكتبه. وعمليّتان
        // متزامنتان على المحفظة نفسها تقرآن الرقمَ نفسَه، فتكتب الثانيةُ
        // فوق الأولى — **ويختفي مبلغُ إحداهما** وسجلُّ الحركات يُظهر
        // العمليّتين ناجحتين.
        //
        // وتُنادى هذه الدالّة من عشرة مواضع، منها توليدُ الرصيد
        // الإلكترونيّ وتحويلُ الإدارة ومركزُ التمويل.
        //
        // **ولا تظهر في أيّ اختبار**: المجموعةُ كلُّها في عمليّةٍ واحدةٍ
        // متتابعة، فلا أحدَ يتحرّك بين القراءة والكتابة. وهي القاعدةُ
        // الأولى في `CLAUDE.md`: «فحصٌ يقع خارج القفل — لا يظهر إلّا
        // بالتوازي».
        //
        // والمسارُ الجديد `FinancialGuardService` سليمٌ منذ يومه:
        // `assertInTransaction()` ثمّ `lockWallet()`. وهذا يلحق به.
        if (DB::transactionLevel() < 1) {
            throw new \RuntimeException(
                'updateEmoney: حركةُ المحفظة تُنفَّذ داخل معاملة — '
                . 'القفلُ خارج المعاملة لا يعني شيئاً، ويُحرَّر فوراً.');
        }

        $emoney = EMoney::where('user_id', $emoney_user_id)->lockForUpdate()->firstOrFail();

        if (strtolower($transaction_type) === ADMIN_CHARGE) {
            $emoney->charge_earned += $charge;
        } else {
            if (strtolower($type) === 'debit') {
                $emoney->current_balance -= $amount;
            } elseif (strtolower($type) === 'credit') {
                $emoney->current_balance += $amount;
            } else {
                throw new InvalidArgumentException("Invalid transaction type: $type");
            }
            $emoney->charge_earned += $charge;
        }

        $emoney->save();

        return $emoney->current_balance;
    }

    public static function get_admin_id(): int
    {
        return  User::where('type', 0)->first()->id ?? 1;
    }

    public static function add_refer_commission(?string $unique_id): void
    {
        $user = User::where('unique_id', $unique_id)->first();
        $admin_id = self::get_admin_id();

        //START TRANSACTION
        DB::beginTransaction();
        $data = [];
        $data['from_user_id'] = $admin_id;
        $data['to_user_id'] = $user->id;

        try {
            //customer transaction
            $data['user_id'] = $data['to_user_id'];
            $data['type'] = 'credit';
            $data['transaction_type'] = 'refer_commission';
            $data['ref_trans_id'] = null;
            $data['amount'] = self::get_business_settings('refer_commission')??0;
            $customer_transaction = Helpers::make_transaction($data);

            if ($customer_transaction == null) {
                throw new TransactionFailedException('Transaction to sender is failed');
            }

            //admin transaction
            $data['user_id'] = $data['from_user_id'];
            $data['type'] = 'debit';
            $data['transaction_type'] = 'refer_commission';
            $data['ref_trans_id'] = $customer_transaction;
            $agent_transaction = Helpers::make_transaction($data);

            if ($agent_transaction == null) {
                throw new TransactionFailedException('Transaction from receiver is failed');
            }

            DB::commit();
        } catch (TransactionFailedException $e) {
            DB::rollBack();
            throw new TransactionFailedException('Refer commission failed');
        }
    }

    public static function send_transaction_notification(int $user_id, float $amount, string $transaction_type, ?string $notificationType = null): bool
    {
        $user = User::find($user_id);
        $value = Helpers::order_status_update_message($transaction_type);

        if(isset($user) && $user->fcm_token && $value)
        {
            $fcm_token = $user->fcm_token;

            $data = [
                'title' => '',
                'description' => self::set_symbol($amount) . ' ' . $value,
                'order_id' => '',
                'image' => '',
                'type' => $notificationType ?? $transaction_type,
            ];

            try {
                Helpers::send_push_notif_to_device($fcm_token, $data);
                return true;
            } catch (\Exception $exception) {
                return false;
            }
        }

        return false;
    }

    public static  function remove_dir(string $dir): void
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (filetype($dir."/".$object) == "dir") Helpers::remove_dir($dir."/".$object); else unlink($dir."/".$object);
                }
            }
            reset($objects);
            rmdir($dir);
        }
    }

    public static function file_remover(string $dir, ?string $image): bool
    {
        if (!isset($image)) return true;

        if (Storage::disk('public')->exists($dir . $image)) Storage::disk('public')->delete($dir . $image);

        return true;
    }

    public static function setEnvironmentValue(string $envKey, string $envValue): string
    {
        $envFile = app()->environmentFilePath();
        $str = file_get_contents($envFile);
        $oldValue = env($envKey);

        if (strpos($str, $envKey) !== false) {
            $str = str_replace("{$envKey}={$oldValue}", "{$envKey}={$envValue}", $str);
        } else {
            $str .= "{$envKey}={$envValue}\n";
        }

        $fp = fopen($envFile, 'w');
        fwrite($fp, $str);
        fclose($fp);

        return $envValue;
    }

    // AMIAL-CLEANUP: أُزيلت requestSender() — كانت تستدعي LaravelchkController (غير
    // موجود أصلاً، بلا مستدعين) للتحقّق من تفعيل 6amtech. كود ميت/مكسور.

    public static function check_customer_transaction_limit(object $user, float $amount, string $type, array $transaction_limits_config): array
    {
        // Check max amount per transaction
        if ($transaction_limits_config['max_amount_per_transaction'] > 0 && $amount > $transaction_limits_config['max_amount_per_transaction']) {
            return ['status' => false, 'message' => 'maximum amount per transaction exceeded'];
        }

        $transaction_limit = TransactionLimit::where(['user_id' => $user->id, 'type' => $type])->first();
        if (!$transaction_limit) {
            $transaction_limit = new TransactionLimit();
            $transaction_limit->user_id = $user->id;
            $transaction_limit->todays_count = 0;
            $transaction_limit->todays_amount = 0;
            $transaction_limit->this_months_count = 0;
            $transaction_limit->this_months_amount = 0;
            $transaction_limit->type = $type;
            $transaction_limit->created_at = now();
            $transaction_limit->updated_at = now();
            $transaction_limit->save();

            return ['status' => true];
        }

        $currentDay = now()->day;
        $currentMonth = now()->month;
        $currentYear = now()->year;

        if ($currentDay !== $transaction_limit->updated_at->day || $currentMonth !== $transaction_limit->updated_at->month) {
            $transaction_limit->todays_count = 0;
            $transaction_limit->todays_amount = 0;
        }

        if ($currentMonth !== $transaction_limit->updated_at->month || $currentYear !== $transaction_limit->updated_at->year) {
            $transaction_limit->this_months_count = 0;
            $transaction_limit->this_months_amount = 0;

        }
        $transaction_limit->save();

        if ($transaction_limits_config['transaction_limit_per_day'] > 0 && $transaction_limit->todays_count >= $transaction_limits_config['transaction_limit_per_day']) {
            return ['status' => false, 'message' => 'transaction limit per day exceeded'];
        }

        if ($transaction_limits_config['total_transaction_amount_per_day'] > 0 && ($transaction_limit->todays_amount + $amount) > $transaction_limits_config['total_transaction_amount_per_day']) {
            return ['status' => false, 'message' => 'total transaction amount per day exceeded'];
        }

        if ($transaction_limits_config['transaction_limit_per_month'] > 0 && $transaction_limit->this_months_count >= $transaction_limits_config['transaction_limit_per_month']) {
            return ['status' => false, 'message' => 'transaction limit per month exceeded'];
        }

        if ($transaction_limits_config['total_transaction_amount_per_month'] > 0 && ($transaction_limit->this_months_amount + $amount) > $transaction_limits_config['total_transaction_amount_per_month']) {
            return ['status' => false, 'message' => 'total transaction amount per month exceeded'];
        }

        // All checks passed
        return ['status' => true];
    }

    public static function add_money_transaction_limit_update(int $user_id, float $amount): void
    {
        $user = User::find($user_id);

        if (isset($user) && $user['type'] == 1){
            $customerAddMoneyLimit = Helpers::get_business_settings('customer_add_money_limit');

            if(isset($customerAddMoneyLimit) && $customerAddMoneyLimit['status'] == 1){
                $transactionLimit = TransactionLimit::where(['user_id' => $user_id, 'type' => 'add_money'])->first();
                $transactionLimit->user_id = $user_id;
                $transactionLimit->todays_count += 1;
                $transactionLimit->todays_amount += $amount;
                $transactionLimit->this_months_count += 1;
                $transactionLimit->this_months_amount += $amount;
                $transactionLimit->type = 'add_money';
                $transactionLimit->updated_at = now();
                $transactionLimit->update();
            }
        } elseif (isset($user) && $user['type'] == 2){
            $agentAddMoneyLimit = Helpers::get_business_settings('agent_add_money_limit');

            if(isset($agentAddMoneyLimit) && $agentAddMoneyLimit['status'] == 1){
                $transactionLimit = TransactionLimit::where(['user_id' => $user_id, 'type' => 'add_money'])->first();
                $transactionLimit->user_id = $user_id;
                $transactionLimit->todays_count += 1;
                $transactionLimit->todays_amount += $amount;
                $transactionLimit->this_months_count += 1;
                $transactionLimit->this_months_amount += $amount;
                $transactionLimit->type = 'add_money';
                $transactionLimit->updated_at = now();
                $transactionLimit->update();
            }
        }
    }

    public static function onErrorImage(?string $data, string $src, string $errorSource , string $path): string
    {
        if(isset($data) && strlen($data) >1 && Storage::disk('public')->exists($path.$data)){
            return $src;
        }

        return $errorSource;
    }

    public static function timeHumanReadableFormat(string $time): string
    {
        $interval = CarbonInterval::seconds($time)->cascade();
        $parts = [];

        if ($interval->hours > 0) {
            $parts[] = $interval->hours . ' hour' . ($interval->hours > 1 ? 's' : '');
        }
        if ($interval->minutes > 0) {
            $parts[] = $interval->minutes . ' minute' . ($interval->minutes > 1 ? 's' : '');
        }
        if ($interval->seconds > 0) {
            $parts[] = $interval->seconds . ' second' . ($interval->seconds > 1 ? 's' : '');
        }

        return implode(' ', $parts);
    }
}

function translate(string $key): string
{
    $local = session()->has('local') ? session('local') : 'en';
    App::setLocale($local);
    $path = base_path('resources/lang/' . $local . '/messages.php');
    $lang_array = file_exists($path) ? include($path) : [];
    if (!is_array($lang_array)) {
        $lang_array = [];
    }
    $processed_key = ucfirst(str_replace('_', ' ', Helpers::remove_invalid_charcaters($key)));

    if (!array_key_exists($key, $lang_array)) {
        // AMIAL-FIX: على تخزين الخادم (Railway) ملفات اللغة للقراءة فقط،
        // فمحاولة كتابة مفتاح مفقود كانت تُسقط الطلب بـ "Permission denied".
        // نكتب فقط إن أمكن، ونرجع النصّ المُعالَج دائماً بدل الانهيار.
        try {
            if (@is_writable($path)) {
                $lang_array[$key] = $processed_key;
                $str = "<?php return " . var_export($lang_array, true) . ";";
                @file_put_contents($path, $str);
            }
        } catch (\Throwable $e) {
            // نتجاهل أي فشل كتابة — الترجمة اختيارية للحفظ
        }
        $result = $processed_key;
    } else {
        $result = __('messages.' . $key);
    }

    return $result;
}


