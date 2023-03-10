<?php

namespace Saeeed\PHPMultipay\Drivers\Sepordeh;

use GuzzleHttp\Client;
use Saeeed\PHPMultipay\Abstracts\Driver;
use Saeeed\PHPMultipay\Contracts\ReceiptInterface;
use Saeeed\PHPMultipay\Exceptions\InvalidPaymentException;
use Saeeed\PHPMultipay\Exceptions\PurchaseFailedException;
use Saeeed\PHPMultipay\Invoice;
use Saeeed\PHPMultipay\Receipt;
use Saeeed\PHPMultipay\RedirectionForm;
use Saeeed\PHPMultipay\Request;

class Sepordeh extends Driver
{
    /**
     * Sepordeh Client.
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
     * Sepordeh constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object)$settings;
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
        $orderId = $this->extractDetails('orderId');
        $phone = $this->extractDetails('phone');
        $description = $this->extractDetails('description') ?: $this->settings->description;

        $data = [
            "merchant" => $this->settings->merchantId,
            "amount" => $this->invoice->getAmount(),
            "phone" => $phone,
            "orderId" => $orderId,
            "callback" => $this->settings->callbackUrl,
            "description" => $description,
        ];

        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiPurchaseUrl,
                [
                    "form_params" => $data,
                    "http_errors" => false,
                    'verify' => false,
                ]
            );

        $responseBody = mb_strtolower($response->getBody()->getContents());
        $body = @json_decode($responseBody, true);
        $statusCode = (int)$body['status'];

        if ($statusCode !== 200) {
            // some error has happened
            $message = $body['message'] ?? $this->convertStatusCodeToMessage($statusCode);

            throw new PurchaseFailedException($message);
        }

        $this->invoice->transactionId($body['information']['invoice_id']);

        return $this->invoice->getTransactionId();
    }

    /**
     * Retrieve data from details using its name.
     *
     * @return string
     */
    private function extractDetails($name)
    {
        return empty($this->invoice->getDetails()[$name]) ? null : $this->invoice->getDetails()[$name];
    }

    /**
     * Retrieve related message to given status code
     *
     * @param $statusCode
     *
     * @return string
     */
    private function convertStatusCodeToMessage(int $statusCode): string
    {
        $messages = [
            400 => '?????????? ???? ?????????? ?????????????? ???????? ????????',
            401 => '?????? ????????????',
            403 => '???????????? ?????? ????????',
            404 => '???????? ???????????????? ???????? ?????? ?????????? ?????? ????????',
            500 => '?????????? ???? ???????? ?????????? ???????????? ???? ???????? ??????',
            503 => '???????? ?????????? ???????????? ???? ?????? ???????? ???????? ???? ???????????????? ?????? ????????',
        ];

        $unknown = '???????? ???????????????? ???? ???? ?????????? ???????????? ???? ???????? ??????';

        return $messages[$statusCode] ?? $unknown;
    }

    /**
     * Pay the Invoice
     *
     * @return RedirectionForm
     */
    public function pay(): RedirectionForm
    {
        $basePayUrl = $this->settings->mode == 'normal' ? $this->settings->apiPaymentUrl
            : $this->settings->apiDirectPaymentUrl;
        $payUrl =  $basePayUrl . $this->invoice->getTransactionId();

        return $this->redirectWithForm($payUrl, [], 'GET');
    }

    /**
     * Verify payment
     *
     * @return ReceiptInterface
     *
     * @throws InvalidPaymentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function verify(): ReceiptInterface
    {
        $authority = $this->invoice->getTransactionId() ?? Request::input('authority');
        $data = [
            'merchant' => $this->settings->merchantId,
            'authority' => $authority,
        ];

        $response = $this->client->request(
            'POST',
            $this->settings->apiVerificationUrl,
            [
                'form_params' => $data,
                "headers" => [
                    "http_errors" => false,
                ],
                'verify' => false,
            ]
        );

        $responseBody = mb_strtolower($response->getBody()->getContents());
        $body = @json_decode($responseBody, true);
        $statusCode = (int)$body['status'];

        if ($statusCode !== 200) {
            $message = $body['message'] ?? $this->convertStatusCodeToMessage($statusCode);

            $this->notVerified($message);
        }

        $refId = $body['information']['invoice_id'];
        $detail = [
            'card' => $body['information']['card'],
            'orderId' => Request::input('orderId')
        ];

        return $this->createReceipt($refId, $detail);
    }

    /**
     * Trigger an exception
     *
     * @param $message
     *
     * @throws InvalidPaymentException
     */
    private function notVerified($message)
    {
        throw new InvalidPaymentException($message);
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     *
     * @return Receipt
     */
    protected function createReceipt($referenceId, $detail = [])
    {
        $receipt = new Receipt('sepordeh', $referenceId);
        $receipt->detail($detail);

        return $receipt;
    }
}
