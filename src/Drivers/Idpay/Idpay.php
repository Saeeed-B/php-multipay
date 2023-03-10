<?php

namespace Saeeed\PHPMultipay\Drivers\Idpay;

use GuzzleHttp\Client;
use Saeeed\PHPMultipay\Abstracts\Driver;
use Saeeed\PHPMultipay\Exceptions\InvalidPaymentException;
use Saeeed\PHPMultipay\Exceptions\PurchaseFailedException;
use Saeeed\PHPMultipay\Contracts\ReceiptInterface;
use Saeeed\PHPMultipay\Invoice;
use Saeeed\PHPMultipay\Receipt;
use Saeeed\PHPMultipay\RedirectionForm;
use Saeeed\PHPMultipay\Request;

class Idpay extends Driver
{
    /**
     * Idpay Client.
     *
     * @var object
     */
    protected $client;

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
     * Idpay constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
        $this->client = new Client();
    }

    /**
     * Purchase Invoice.
     *
     * @return string
     *
     * @throws PurchaseFailedException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function purchase()
    {
        $details = $this->invoice->getDetails();

        $phone = null;
        if (!empty($details['phone'])) {
            $phone = $details['phone'];
        } elseif (!empty($details['mobile'])) {
            $phone = $details['mobile'];
        }

        $mail = null;
        if (!empty($details['mail'])) {
            $mail = $details['mail'];
        } elseif (!empty($details['email'])) {
            $mail = $details['email'];
        }

        $desc = $this->settings->description;
        if (!empty($details['desc'])) {
            $desc = $details['desc'];
        } elseif (!empty($details['description'])) {
            $desc = $details['description'];
        }

        $data = array(
            'order_id' => $this->invoice->getUuid(),
            'amount' => $this->invoice->getAmount(),
            'name' => $details['name'] ?? null,
            'phone' => $phone,
            'mail' => $mail,
            'desc' => $desc,
            'callback' => $this->settings->callbackUrl,
            'reseller' => $details['reseller'] ?? null,
        );

        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiPurchaseUrl,
                [
                    "json" => $data,
                    "headers" => [
                        'X-API-KEY' => $this->settings->merchantId,
                        'Content-Type' => 'application/json',
                        'X-SANDBOX' => (int) $this->settings->sandbox,
                    ],
                    "http_errors" => false,
                ]
            );

        $body = json_decode($response->getBody()->getContents(), true);
        if (empty($body['id'])) {
            // error has happened
            $message = $body['error_message'] ?? '?????? ???? ?????????? ?????????????? ???????? ???????????? ???? ???????? ??????.';
            throw new PurchaseFailedException($message);
        }

        $this->invoice->transactionId($body['id']);

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
        $apiUrl = $this->settings->apiPaymentUrl;

        // use sandbox url if we are in sandbox mode
        if (!empty($this->settings->sandbox)) {
            $apiUrl = $this->settings->apiSandboxPaymentUrl;
        }

        $payUrl = $apiUrl.$this->invoice->getTransactionId();

        return $this->redirectWithForm($payUrl, [], 'GET');
    }

    /**
     * Verify payment
     *
     * @return mixed|void
     *
     * @throws InvalidPaymentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function verify() : ReceiptInterface
    {
        $data = [
            'id' => $this->invoice->getTransactionId() ?? Request::input('id'),
            'order_id' => Request::input('order_id'),
        ];

        $response = $this->client->request(
            'POST',
            $this->settings->apiVerificationUrl,
            [
                'json' => $data,
                "headers" => [
                    'X-API-KEY' => $this->settings->merchantId,
                    'Content-Type' => 'application/json',
                    'X-SANDBOX' => (int) $this->settings->sandbox,
                ],
                "http_errors" => false,
            ]
        );
        $body = json_decode($response->getBody()->getContents(), true);

        if (isset($body['error_code']) || $body['status'] != 100) {
            $errorCode = $body['status'] ?? $body['error_code'];

            $this->notVerified($errorCode);
        }

        return $this->createReceipt($body['track_id']);
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
        $receipt = new Receipt('idpay', $referenceId);

        return $receipt;
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
            "1" => "???????????? ?????????? ???????? ??????.",
            "2" => "???????????? ???????????? ???????? ??????.",
            "3" => "?????? ???? ???????? ??????.",
            "4" => "?????????? ??????.",
            "5" => "?????????? ???? ???????????? ??????????.",
            "6" => "?????????? ?????????? ????????????.",
            "10" => "???? ???????????? ?????????? ????????????.",
            "100" => "???????????? ?????????? ?????? ??????.",
            "101" => "???????????? ???????? ?????????? ?????? ??????.",
            "200" => "???? ???????????? ?????????? ?????????? ????.",
            "11" => "?????????? ?????????? ?????? ??????.",
            "12" => "API Key ???????? ??????.",
            "13" => "?????????????? ?????? ???? {ip} ?????????? ?????? ??????. ?????? IP ???? IP ?????? ?????? ?????? ???? ???? ?????????? ?????????????? ??????????.",
            "14" => "???? ?????????? ?????????? ???????? ??????.",
            "21" => "???????? ?????????? ???????? ???? ???? ?????????? ?????????? ???????? ??????.",
            "31" => "???? ???????????? id ?????????? ???????? ????????.",
            "32" => "?????????? ?????????? order_id ?????????? ???????? ????????.",
            "33" => "???????? amount ?????????? ???????? ????????.",
            "34" => "???????? amount ???????? ?????????? ???? {min-amount} ???????? ????????.",
            "35" => "???????? amount ???????? ???????? ???? {max-amount} ???????? ????????.",
            "36" => "???????? amount ?????????? ???? ???? ???????? ??????.",
            "37" => "???????? ???????????? callback ?????????? ???????? ????????.",
            "38" => "?????????????? ?????? ???? ???????? {domain} ?????????? ?????? ??????. ?????????? ???????? ???????????? callback ???? ???????? ?????? ?????? ???? ???? ?????????? ?????????????? ??????????.",
            "51" => "???????????? ?????????? ??????.",
            "52" => "?????????????? ?????????? ???? ??????????.",
            "53" => "?????????? ???????????? ?????????? ???????? ????????.",
            "54" => "?????? ???????? ?????????? ???????????? ???????? ?????? ??????.",
        );
        if (array_key_exists($status, $translations)) {
            throw new InvalidPaymentException($translations[$status]);
        } else {
            throw new InvalidPaymentException('???????? ???????????????? ???? ???? ???????? ??????.');
        }
    }
}
