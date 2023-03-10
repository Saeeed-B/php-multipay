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

class Saman extends Driver
{
    /**
     * Invoice
     *
     * @var Invoice
     */
    protected $invoice;

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
    public function purchase()
    {
        $data = array(
            'MID' => $this->settings->merchantId,
            'ResNum' => $this->invoice->getUuid(),
            'Amount' => $this->invoice->getAmount() * 10, // convert to rial
            'CellNumber' => ''
        );

        //set CellNumber for get user cards
        if (!empty($this->invoice->getDetails()['mobile'])) {
            $data['CellNumber'] = $this->invoice->getDetails()['mobile'];
        }

        $soap = new \SoapClient(
            $this->settings->apiPurchaseUrl,
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

        $response = $soap->RequestToken($data['MID'], $data['ResNum'], $data['Amount'], $data['CellNumber']);

        $status = (int)$response;

        if ($status < 0) { // if something has done in a wrong way
            $this->purchaseFailed($response);
        }

        // set transaction id
        $this->invoice->transactionId($response);

        // return the transaction's id
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
                'Token' => $this->invoice->getTransactionId(),
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

        $receipt =  $this->createReceipt($data['RefNum']);
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
            -1 => ' ???????????? ???????? ???????????? ???????? ?????? ??????.',
            -6 => '?????? ???????? ?????????? ???????? ?????????? ??????. ???? ???????? ???? ???????? 30 ?????????? ?????????? ?????? ??????.',
            -18 => 'IP Address ?????????????? ????????????????? ??????.',
            79 => '???????? ?????? ?????????????? ???? ???????? ???????????? ???????? ?????????? ??????.',
            12 => '?????????????? ?????????? ???? ???????????? ?????????? ???????? ???? ???????? ???? ???????????? ???????? ???????? ?????? ??????.',
            14 => '?????????? ???????? ?????????????? ??????.',
            15 => '???????? ???????? ?????????? ?????????? ???????? ??????????.',
            33 => '???? ?????????? ???????????? ???????? ?????????? ?????? ?? ???????? ???????? ?????????? ????????.',
            38 => '?????? ???????? 3 ?????????? ???????????? ???????? ?????? ?????? ???? ?????????? ???????? ?????? ???????? ?????????? ????.',
            55 => '???????????? ?????? ???????? ???? ???????????? ???????? ???????? ??????.',
            61 => '???????? ?????? ???? ?????? ???????????? ???? ????????.',
            93 => '???????????? Authorize ?????? ?????? (?????????? PIN ?? PAN ???????? ??????????) ?????? ?????????? ?????? ?????????? ???????? ??????????.',
            68 => '???????????? ???? ???????? ?????????? Timeout ?????????? ??????.',
            34 => '???????????? ???? ???????? CVV2 ?? ???? ???????? ExpDate ???? ???????????? ???????? ???????? ?????? (???? ???????? ???????? ?????????? ??????).',
            51 => '???????????? ???????? ?????????????? ???????? ????????.',
            84 => '?????????? ???????? ???????? ?????????? ???????? ?????????????? ???? ?????????? ?????????????? ????????.',
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
            -3 => '?????????? ???? ???????? ?????????????????? ?????????????? ??????????????.',
            -4 => '???????? ???????? ???? ???? ?????????????? ???????????? ?????? (Merchant Authentication Failed)',
            -6 => '?????? ???????? ?????????? ???????? ?????????? ??????. ???? ???????? ???? ???????? 30 ?????????? ?????????? ?????? ??????.',
            -7 => '???????? ???????????????? ?????? ??????.',
            -8 => '?????? ?????????? ???? ?????????? ???? ???? ???????? ??????.',
            -9 => '???????? ?????????????????? ?????????????? ???? ???????? ????????????.',
            -10 => '???????? ???????????????? ???? ???????? Base64 ???????? (???????? ???????????????????? ?????????????? ??????)',
            -11 => '?????? ?????????? ???? ???????? ???? ???? ???????? ??????.',
            -12 => '???????? ???????????? ???????? ??????.',
            -13 => '???????? ???????????? ???????? ?????????? ???????? ?????? ???? ???????? ?????????? ???????????? ?? ???????? ???????????????? ??????.',
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
