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
            1 => '?????????? ???????????? ???????? ??????',
            2 => '???????????? ???? ???????????? ?????????? ????',
            3 => '???????????? ?????????? ??????.',
            4 => '?????????? ???? ???????? ?????????? ?????????? ?????? ?????????? ?????????? ?????????? ??????',
            5 => '???????????????????? ???????????? ?????????????? ??????',
            8 => 'ip ???????? ?????????????? ?????????????? ??????',
            10 => '???????? ?????????? ?????? ???????? ??????',
            11 => '???? ?????? ?????????? ?????????????? ?????? ???????????? ?????? ?????????? ???????? ???????????? ??????????.',
            12 => '?????????? ?????????????? ?????????? ?????? ???????? ??????',
            -1 => '???????? ???? ???????????? ?????????????? ???????????? (???????? ???? ?????? ???? ?????????? ???? ?? ???????????? ???????? ???????????????? ?????? ?????????? ????????????).',
            -3 => '?????????????? ???????? ?????????????????? ?????????????? ??????????????.',
            -4 => '???????? ???????? ???? ???? ?????????????? ???????????? ??????.',
            -6 => '?????? ???????? ?????????? ???????? ?????????? ??????. ???? ???????? ???? ???????? 30 ?????????? ?????????? ?????? ??????.',
            -7 => '???????? ???????????????? ?????? ??????.',
            -8 => '?????? ?????????????? ?????????? ???? ???? ???????? ??????.',
            -9 => '???????? ?????????????????? ?????????????? ???? ???????? ????????????.',
            -10 => '???????? ???????????????? ???? ???????? Base64 ???????? (???????? ???????????????????? ?????????????? ??????).',
            -11 => '?????? ?????????????? ?? ???? ???? ???? ???????? ??????.',
            -12 => '???????? ???????????? ???????? ??????.',
            -13 => '???????? ???????????? ???????? ?????????? ???????? ?????? ???? ???????? ?????????? ?????????????? ???????? ???????????????? ??????.',
            -14 => '???????? ?????????????? ?????????? ???????? ??????.',
            -15 => '???????? ???????????? ???? ???????? ???????????? ???????? ?????? ??????.',
            -16 => '???????? ?????????? ??????????',
            -17 => '?????????? ?????? ???????? ???????????? ???????? ?? ?? ????????.',
            -18 => '???????? ?????????????? ?????????????? ?????? ?? ???? ?????? ???????? ?????????????? (reverseTransaction) ???????????? ??????.',
            0 => '???????????? ???? ???????????? ?????????????? ?????? ??????',
            -1 => '?????????? ???? ?????????? ???????????? ?????? ?????? ??????',
            14 => '?????????? ???????? ???????????? ?????????????? ?????? (???????? ??????????)',
            15 => '???????? ???????? ?????????? ?????????? ???????? ??????????',
            16 => '???????????? ???????? ?????????? ?????? ?? ?????????????? ???????? ?????? ???????? ???? ?????? ?????????? ??????',
            19 => '???????????? ???????????? ?????????? ??????',
            23 => '???????????? ???????????? ?????????????? ?????? ???????? ???????? ??????',
            30 => '???????? ???????? ?????????? ?????????? ??????',
            31 => '?????????????? ???????? ?????????? ???????????????? ?????? ??????',
            33 => '???? ?????????? ???????????? ???????? ?????????? ?????? ?? ???????? ???????? ?????????? ????????.',
            34 => '???????????? ???? ???????? CVV2 ?? ???? ???????? ExpDate ???? ???????????? ???????? ???????? ?????? (???? ???????????? ???????? ?????????? ???? ???????? ??????(.',
            38 => '?????????? ?????????? ???????? ?????? ?????? ?????? ???? ???? ???????? ??????',
            39 => '???????? ???????? ?????????????? ??????????',
            40 => '???????????? ???????????????? ???????????????? ?????? ????????',
            41 => '???????? ???????????? ???? ????????',
            42 => '???????? ???????? ?????????? ??????????',
            43 => '???????? ???????????? ???? ????????',
            44 => '???????? ???????? ???????????? ?????????? ??????????',
            51 => '???????????? ???????? ????????',
            52 => '???????? ???????? ???????? ??????????',
            53 => '???????? ???????? ?????? ???????????? ??????????',
            54 => '?????????? ???????????? ???????? ???????? ?????? ??????',
            55 => '???????????? ?????? ???????? ) PIN ( ???? ???????????? ???????? ???????? ??????.',
            56 => '???????? ?????????????? ??????',
            57 => '?????????? ???????????? ???????????? ???????? ?????????? ???????? ???????? ?????? ????????',
            58 => '?????????? ???????????? ???????????? ???????? ???????????? ?????????? ?????????? ???????? ?????? ????????',
            61 => '???????? ???????????? ?????? ???? ???? ???????? ??????',
            62 => '???????? ?????????? ?????? ??????',
            63 => '?? ?????????? ???????????? ?????? ???????????? ??????',
            65 => '?????????? ?????????????? ???????????? ?????? ???? ???? ???????? ??????',
            68 => '???????????? ???? ???????? ?????????? Timeout ?????????? ??????.',
            75 => '?????????? ?????????? ???????? ?????? ?????? ?????? ???? ???? ???????? ??????',
            79 => '???????? ?????? ?????????????? ???? ???????? ???????????? ???????? ?????????? ??????.',
            84 => '?????????? ???????? ???????? ?????????? ???????? ?????????????? ???? ?????????? ?????????????? ????????.',
            90 => '???????????? ???????? ???????????? ???? ?????? ?????????? ???????????? ?????????? ?????? ???? ????????',
            93 => '???????????? Authorize ?????? ?????? (?????????? PIN ?? PAN ???????? ??????????) ?????? ?????????? ?????? ?????????? ???????? ??????????',
            96 => '???????? ???????????? ???????? ?????????? ???????? ?????????? ???????? ?????????? ???? ????????.',
        );

        if (array_key_exists($status, $translations)) {
            throw new PurchaseFailedException($translations[$status]);
        } else {
            throw new PurchaseFailedException('???????? ???????????????? ???? ???? ???????? ??????.');
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
            -1 => '?????? ???? ???????????? ?????????????? ???????????? (???????? ???? ?????? ???? ?????????? ???? ?? ???????????? ???????? ???????????????? ?????? ?????????? ????????????)',
            -3 => '?????????????? ???????? ?????????????????? ?????????????? ??????????????.',
            -4 => '???????? ???????? ???? ???? ?????????????? ???????????? ?????? (Merchant Authentication Failed)',
            -6 => '?????? ???????? ?????????? ???????? ?????????? ??????. ???? ???????? ???? ???????? 30 ?????????? ?????????? ?????? ??????.',
            -7 => '???????? ???????????????? ?????? ??????.',
            -8 => '?????? ?????????????? ?????????? ???? ???? ???????? ??????.',
            -9 => '???????? ?????????????????? ?????????????? ???? ???????? ????????????.',
            -10 => '???????? ???????????????? ???? ???????? Base64 ???????? (???????? ???????????????????? ?????????????? ??????)',
            -11 => '?????? ?????????????? ?? ???? ???? ???? ???????? ??????.',
            -12 => '???????? ???????????? ???????? ??????.',
            -13 => '???????? ???????????? ???????? ?????????? ???????? ?????? ???? ???????? ?????????? ?????????????? ???????? ???????????????? ??????.',
            -14 => '???????? ?????????????? ?????????? ???????? ??????.',
            -15 => '???????? ???????????? ???? ???????? ???????????? ???????? ?????? ??????.',
            -16 => '???????? ?????????? ??????????',
            -17 => '?????????? ?????? ???????? ???????????? ???????? ?????? ????????.',
            -18 => 'IP Address ?????????????? ???? ?????????? ?????? ?? ???? ?????? ???????? ?????????????? (reverseTransaction) ???????????? ??????.',
        );

        if (array_key_exists($status, $translations)) {
            throw new InvalidPaymentException($translations[$status]);
        } else {
            throw new InvalidPaymentException('???????? ???????????????? ???? ???? ???????? ??????.');
        }
    }
}
