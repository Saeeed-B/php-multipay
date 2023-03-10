<?php

namespace Saeeed\PHPMultipay\Drivers\Zarinpal\Strategies;

use Saeeed\PHPMultipay\Abstracts\Driver;
use Saeeed\PHPMultipay\Exceptions\InvalidPaymentException;
use Saeeed\PHPMultipay\Exceptions\PurchaseFailedException;
use Saeeed\PHPMultipay\Contracts\ReceiptInterface;
use Saeeed\PHPMultipay\Invoice;
use Saeeed\PHPMultipay\Receipt;
use Saeeed\PHPMultipay\RedirectionForm;
use Saeeed\PHPMultipay\Request;

class Sandbox extends Driver
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
     * Zarinpal constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
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
        if (!empty($this->invoice->getDetails()['description'])) {
            $description = $this->invoice->getDetails()['description'];
        } else {
            $description = $this->settings->description;
        }

        if (!empty($this->invoice->getDetails()['mobile'])) {
            $mobile = $this->invoice->getDetails()['mobile'];
        }

        if (!empty($this->invoice->getDetails()['email'])) {
            $email = $this->invoice->getDetails()['email'];
        }

        $data = array(
            'MerchantID' => $this->settings->merchantId,
            'Amount' => $this->invoice->getAmount(),
            'CallbackURL' => $this->settings->callbackUrl,
            'Description' => $description,
            'Mobile' => $mobile ?? '',
            'Email' => $email ?? '',
            'AdditionalData' => $this->invoice->getDetails()
        );

        $client = new \SoapClient($this->getPurchaseUrl(), ['encoding' => 'UTF-8']);
        $result = $client->PaymentRequest($data);

        $bodyResponse = $result->Status;
        if ($bodyResponse != 100 || empty($result->Authority)) {
            throw new PurchaseFailedException($this->translateStatus($bodyResponse), $bodyResponse);
        }

        $this->invoice->transactionId($result->Authority);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     *
     * @return RedirectionForm
     */
    public function pay() : RedirectionForm
    {
        $transactionId = $this->invoice->getTransactionId();
        $paymentUrl = $this->getPaymentUrl();

        $payUrl = $paymentUrl.$transactionId;

        return $this->redirectWithForm($payUrl, [], 'GET');
    }

    /**
     * Verify payment
     *
     * @return ReceiptInterface
     *
     * @throws InvalidPaymentException
     * @throws \SoapFault
     */
    public function verify() : ReceiptInterface
    {
        $authority = $this->invoice->getTransactionId() ?? Request::input('Authority');
        $data = [
            'MerchantID' => $this->settings->merchantId,
            'Authority' => $authority,
            'Amount' => $this->invoice->getAmount(),
        ];

        $client = new \SoapClient($this->getVerificationUrl(), ['encoding' => 'UTF-8']);
        $result = $client->PaymentVerification($data);

        $bodyResponse = $result->Status;
        if ($bodyResponse != 100) {
            throw new InvalidPaymentException($this->translateStatus($bodyResponse), $bodyResponse);
        }

        return $this->createReceipt($result->RefID);
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     *
     * @return Receipt
     */
    public function createReceipt($referenceId)
    {
        return new Receipt('zarinpal', $referenceId);
    }

    /**
     * Retrieve purchase url
     *
     * @return string
     */
    protected function getPurchaseUrl() : string
    {
        return $this->settings->sandboxApiPurchaseUrl;
    }

    /**
     * Retrieve Payment url
     *
     * @return string
     */
    protected function getPaymentUrl() : string
    {
        return $this->settings->sandboxApiPaymentUrl;
    }

    /**
     * Retrieve verification url
     *
     * @return string
     */
    protected function getVerificationUrl() : string
    {
        return $this->settings->sandboxApiVerificationUrl;
    }

    /**
     * Convert status to a readable message.
     *
     * @param $status
     *
     * @return mixed|string
     */
    private function translateStatus($status)
    {
        $translations = [
            '100' => '???????????? ???? ???????????? ?????????? ??????????',
            '101' => '???????????? ???????????? ???????? ???????? ?? ???????? ???????????? ???????????? ???????????? ?????????? ?????? ??????',
            '-9' => '???????? ???????????? ????????',
            '-10' => '???? ???? ?? ???? ?????????? ???? ?????????????? ???????? ?????? ????????',
            '-11' => '?????????? ???? ???????? ???????? ???????? ???? ?????? ???????????????? ???? ???????? ????????????',
            '-12' => '???????? ?????? ???? ???? ???? ???? ???????? ?????????? ??????????',
            '-15' => '?????????????? ?????? ???? ???????? ?????????? ???? ???????? ???? ?????? ???????????????? ???????? ????????????',
            '-16' => '?????? ?????????? ?????????????? ?????????? ???? ???? ?????? ???????? ???? ???? ????????',
            '-30' => '?????????? ???????????? ???? ?????????? ?????????????? ?????????? ????????????',
            '-31' => '???????? ?????????? ?????????? ???? ???? ?????? ?????????? ???????? ???????????? ???????? ?????? ???????? ?????????? ???????? ?????? ????????',
            '-32' => '???????????? ???????? ?????? ???????? ?????????? ???????? ?????? ????????',
            '-33' => '???????? ?????? ???????? ?????? ???????? ?????? ????????',
            '-34' => '???????? ???? ???? ???????????? ?????????? ??????',
            '-35' => '?????????? ?????????? ???????????? ?????????? ?????????? ?????? ???? ???? ???????? ??????',
            '-40' => '???????????????????? ?????????? ???????????????? expire_in ?????????? ????????',
            '-50' => '???????? ???????????? ?????? ???? ?????????? ???????? ???? ???????????? ???????????? ??????',
            '-51' => '???????????? ????????????',
            '-52' => '???????? ?????? ???????????? ???? ???????????????? ???????? ????????????',
            '-53' => '?????????????? ???????? ?????? ?????????? ???? ????????',
            '-54' => '?????????????? ?????????????? ??????',
        ];

        $unknownError = '???????? ???????????????? ???? ???????? ??????. ???? ???????? ?????? ???????? ???? ???????? ???????????? ???? ???? 72 ???????? ???? ?????????????? ????????????????';

        return array_key_exists($status, $translations) ? $translations[$status] : $unknownError;
    }
}
