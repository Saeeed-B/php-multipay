<?php

namespace Saeeed\PHPMultipay\Drivers\Saman;

use Saeeed\PHPMultipay\Abstracts\Driver;
use Saeeed\PHPMultipay\Exceptions\InvalidPaymentException;
use Saeeed\PHPMultipay\Exceptions\PurchaseFailedException;
use Saeeed\PHPMultipay\Contracts\ReceiptInterface;
use Saeeed\PHPMultipay\Invoice;
use Saeeed\PHPMultipay\Receipt;
use Saeeed\PHPMultipay\RedirectionForm;
use Saeeed\PHPMultipay\Request;

class SamanOnlinePG extends Driver
{
    /**
     * Invoice
     *
     * @var Invoice
     */
    protected $invoice;
    protected $token;

    /**
     * Driver settings
     *
     * @var object
     */
    protected $settings;

    /**
     * Saman constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object)$settings;
    }

    /**
     * Purchase Invoice.
     *
     * @return string
     *
     * @throws PurchaseFailedException
     * @throws \SoapFault
     */
    public function purchase($order_id)
    {
        $data =[
            'action' => 'token',
            'TerminalId' => $this->settings->merchantId,
            'Amount' => (int)$this->invoice->getAmount() * 10,
            'ResNum' => $order_id,
            'RedirectUrl' => $this->settings->callbackUrl,
            'CellNumber' => '',
        ];

        $client = new \GuzzleHttp\Client();
        $response = $client->request('POST', $this->settings->apiPurchaseUrl, [
            'verify' => false,
            'form_params' => $data,
        ]);

        $response = json_decode($response->getBody(), true);

        if ($response['status'] < 0) { // if something has done in a wrong way
            $this->purchaseFailed($response['errorCode']);
        }
        $this->token = $response['token'];
        $this->invoice->transactionId($response['token']);
        return $this->invoice->getTransactionId();

    }

    /**
     * Pay the Invoice
     *
     * @return RedirectionForm
     */
    public function pay(): RedirectionForm
    {
        $payUrl = $this->settings->apiPaymentUrl;

        return $this->redirectWithForm(
            $payUrl,
            [
                'Token' => $this->token,
                'RedirectUrl' => $this->settings->callbackUrl,
            ],
            'POST'
        );
    }

    /**
     * Verify payment
     *
     * @return ReceiptInterface
     *
     * @throws InvalidPaymentException
     * @throws \SoapFault
     */
    public function verify(): ReceiptInterface
    {
        $data = array(
            'RefNum' => Request::input('RefNum'),
            'merchantId' => $this->settings->merchantId,
        );
        $soap = new \SoapClient(
            $this->settings->apiVerificationUrl,
            [
                'encoding'       => 'UTF-8',
                'cache_wsdl'     => WSDL_CACHE_NONE,
                'stream_context' => stream_context_create([
                    'ssl' => [
                        'ciphers' => 'DEFAULT:!DH',
                    ],
                ]),
            ]
        );
        $status = (int)$soap->VerifyTransaction($data['RefNum'], $data['merchantId']);
        if ($status < 0) {
            $this->notVerified($status);
        }
        $receipt =  $this->createReceipt(Request::input('TraceNo'));
        $receipt->detail([
            'traceNo' => Request::input('TraceNo'),
            'referenceNo' => Request::input('RRN'),
            'transactionId' => Request::input('RefNum'),
            'cardNo' => Request::input('SecurePan'),
        ]);

        return $receipt;
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     *
     * @return Receipt
     */
    protected function createReceipt($referenceId)
    {
        $receipt = new Receipt('saman', $referenceId);

        return $receipt;
    }

    /**
     * Trigger an exception
     *
     * @param $status
     *
     * @throws PurchaseFailedException
     */
    protected function purchaseFailed($status)
    {
        $translations = array(
            1 => 'کاربر انصراف داده است',
            2 => 'پرداخت با موفقیت انجام شد',
            3 => 'پرداخت انجام نشد.',
            4 => 'کاربر در بازه زمانی تعیین شده پاسخی ارسال نکرده است',
            5 => 'پارامترهای ارسالی نامعتبر است',
            8 => 'ip سرور پذیرنده نامعتبر است',
            10 => 'توکن ارسال شده یافت نشد',
            11 => 'با این شماره ترمینال فقط تراکنش های توکنی قابل پرداخت هستند.',
            12 => 'شماره ترمینال ارسال شده یافت نشد',
            -1 => 'خطای در پردازش اطلاعات ارسالی (مشکل در یکی از ورودی ها و ناموفق بودن فراخوانی متد برگشت تراکنش).',
            -3 => 'ورودیها حاوی کارکترهای غیرمجاز میباشند.',
            -4 => 'کلمه عبور یا کد فروشنده اشتباه است.',
            -6 => 'سند قبلا برگشت کامل یافته است. یا خارج از زمان 30 دقیقه ارسال شده است.',
            -7 => 'رسید دیجیتالی تهی است.',
            -8 => 'طول ورودیها بیشتر از حد مجاز است.',
            -9 => 'وجود کارکترهای غیرمجاز در مبلغ برگشتی.',
            -10 => 'رسید دیجیتالی به صورت Base64 نیست (حاوی کاراکترهای غیرمجاز است).',
            -11 => 'طول ورودیها ک تر از حد مجاز است.',
            -12 => 'مبلغ برگشتی منفی است.',
            -13 => 'مبلغ برگشتی برای برگشت جزئی بیش از مبلغ برگشت نخوردهی رسید دیجیتالی است.',
            -14 => 'چنین تراکنشی تعریف نشده است.',
            -15 => 'مبلغ برگشتی به صورت اعشاری داده شده است.',
            -16 => 'خطای داخلی سیستم',
            -17 => 'برگشت زدن جزیی تراکنش مجاز ن ی باشد.',
            -18 => 'آیپی فروشنده نامعتبر است و یا رمز تابع بازگشتی (reverseTransaction) اشتباه است.',
            0 => 'تراکنش با موفقیت پذیرفته شده است',
            -1 => 'کاربر از انجام تراکنش صرف نظر کرد',
            14 => 'شماره کارت ارسالی نامعتبر است (وجود ندارد)',
            15 => 'چنین صادر کننده کارتی وجود ندارد',
            16 => 'تراکنش مورد تأیید است و اطلاعات شیار سوم کارت به روز رسانی شود',
            19 => 'تراکنش مجدداً ارسال شود',
            23 => 'کارمزد ارسالی پذیرنده غیر قابل قبول است',
            30 => 'قالب پیام دارای اشکال است',
            31 => 'پذیرنده توسط سوییچ پشتیبانی نمی شود',
            33 => 'از تاریخ انقضای کارت گذشته است و کارت دیگر معتبر نیست.',
            34 => 'خریدار یا فیلد CVV2 و یا فیلد ExpDate را اشتباه وارد کرده است (یا دارنده کارت مظنون به تقلب است(.',
            38 => 'تعداد دفعات ورود رمز غلط بیش از حد مجاز است',
            39 => 'کارت حساب اعتباری ندارد',
            40 => 'عملیات درخواستی پشتیبانی نمی گردد',
            41 => 'کارت مفقودی می باشد',
            42 => 'کارت حساب عمومی ندارد',
            43 => 'کارت مسروقه می باشد',
            44 => 'کارت حساب سرمایه گذاری ندارد',
            51 => 'موجودی کافی نیست',
            52 => 'کارت حساب جاری ندارد',
            53 => 'کارت حساب قرض الحسنه ندارد',
            54 => 'تاریخ انقضای کارت سپری شده است',
            55 => 'خریدار رمز کارت ) PIN ( را اشتباه وارد کرده است.',
            56 => 'کارت نامعتبر است',
            57 => 'انجام تراکنش مربوطه توسط دارند کارت مجاز نمی باشد',
            58 => 'انجام تراکنش مربوطه توسط پایانه انجام دهنده مجاز نمی باشد',
            61 => 'مبلغ تراکنش بیش از حد مجاز است',
            62 => 'کارت محدود شده است',
            63 => 'ت هیدات امنیتی نقض گردیده است',
            65 => 'تعداد درخواست تراکنش بیش از حد مجاز است',
            68 => 'تراکنش در شبکه بانکی Timeout خورده است.',
            75 => 'تعداد دفعات ورود رمز غلط بیش از حد مجاز است',
            79 => 'مبلغ سند برگشتی، از مبلغ تراکنش اصلی بیشتر است.',
            84 => 'سیستم بانک صادر کننده کارت خریدار، در وضعیت عملیاتی نیست.',
            90 => 'سامانه مقصد تراکنش در حال انجام عملیات پایان روز می باشد',
            93 => 'تراکنش Authorize شده است (شماره PIN و PAN درست هستند) ولی امکان سند خوردن وجود ندارد',
            96 => 'کلیه خطاهای دیگر بانکی باعث ایجاد چنین خطایی می گردد.',
        );

        if (array_key_exists($status, $translations)) {
            throw new PurchaseFailedException($translations[$status]);
        } else {
            throw new PurchaseFailedException('خطای ناشناخته ای رخ داده است.');
        }
    }

    /**
     * Trigger an exception
     *
     * @param $status
     *
     * @throws InvalidPaymentException
     */
    private function notVerified($status)
    {
        $translations = array(
            -1 => 'خطا در پردازش اطلاعات ارسالی (مشکل در یکی از ورودی ها و ناموفق بودن فراخوانی متد برگشت تراکنش)',
            -3 => 'ورودیها حاوی کارکترهای غیرمجاز میباشند.',
            -4 => 'کلمه عبور یا کد فروشنده اشتباه است (Merchant Authentication Failed)',
            -6 => 'سند قبال برگشت کامل یافته است. یا خارج از زمان 30 دقیقه ارسال شده است.',
            -7 => 'رسید دیجیتالی تهی است.',
            -8 => 'طول ورودیها بیشتر از حد مجاز است.',
            -9 => 'وجود کارکترهای غیرمجاز در مبلغ برگشتی.',
            -10 => 'رسید دیجیتالی به صورت Base64 نیست (حاوی کاراکترهای غیرمجاز است)',
            -11 => 'طول ورودیها ک تر از حد مجاز است.',
            -12 => 'مبلغ برگشتی منفی است.',
            -13 => 'مبلغ برگشتی برای برگشت جزئی بیش از مبلغ برگشت نخوردهی رسید دیجیتالی است.',
            -14 => 'چنین تراکنشی تعریف نشده است.',
            -15 => 'مبلغ برگشتی به صورت اعشاری داده شده است.',
            -16 => 'خطای داخلی سیستم',
            -17 => 'برگشت زدن جزیی تراکنش مجاز نمی باشد.',
            -18 => 'IP Address فروشنده نا معتبر است و یا رمز تابع بازگشتی (reverseTransaction) اشتباه است.',
        );

        if (array_key_exists($status, $translations)) {
            throw new InvalidPaymentException($translations[$status]);
        } else {
            throw new InvalidPaymentException('خطای ناشناخته ای رخ داده است.');
        }
    }
}
