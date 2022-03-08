<?php
namespace App\Packages\PaymentDriver;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\{Contracts\ReceiptInterface, Invoice, Receipt};

class Apsan extends Driver
{
    const TokenURL = 'Token';
    const PaymentURL = 'payment';
    const AcknowledgeURL = 'acknowledge';
    const RollbackURL = 'rollback';
    const RefundURL = 'refund';
    const NolimitrefundURL = 'nolimitrefund';
    const TransactionURL = 'transaction/status';

    protected $invoice; // Invoice.

    protected $settings; // Driver settings.

    protected $response; // response body.

    protected $status_code; // http status code.

    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice); // Set the invoice.
        $this->settings = (object) $settings; // Set settings.
    }

    // Purchase the invoice, save its transactionId and finaly return it.
    public function purchase() {
        // Request for a payment transaction id. (note: result is token)
        $this->getToken();
        $this->invoice->transactionId($this->response['result']);
        return $this->response['result'];
    }
    
    /**
     * Redirect into bank using transactionId, to complete the payment.
     *
     * @return RedirectionForm
     */
    public function pay() : RedirectionForm {
        // Redirect to the bank.
        return $this->redirectWithForm(
            $this->settings->bankApiUrl . self::PaymentURL, // payment url.
            ['token' => $this->invoice->getTransactionId()],  // token.
            'POST' // method. 
        );
    }
    
    /**
     * Verify the payment (we must verify to ensure that user has paid the invoice).
     *
     * @return ReceiptInterface
     */
    public function verify(): ReceiptInterface {
        $this->callApi('POST', self::AcknowledgeURL, [
            'token'                 => $this->invoice->getTransactionId()
        ]);
        
        /**
        	We create a receipt for this payment if everything goes normally.
        **/
        if($this->status_code == 200 && $this->response['result']['acknowledged']) {
            return new Receipt('apsan', $this->response['result']['grantId']);
        }
        /**
			Then we send a request to $verifyUrl and if payment is not valid we throw an InvalidPaymentException with a suitable message.
        **/
        else {
            // Save last status code to throw it as an exception.
            $verify_status_code = $this->status_code;

            // Rollback money to user.
            $this->rollback();

            // Throw an exception.
            $this->httpCodeTranslator($verify_status_code);
        }
    }

    /**
     * call create token request
     *
     * @return void
     */
    public function getToken() : void
    {
        if(!empty($this->invoice->getDetail('uuid'))) {
            $uuid = $this->invoice->getDetail('uuid');
        } else {
            // Set the uuid.
            $this->invoice->uuid(crc32($this->invoice->getUuid()));
            $uuid = $this->invoice->getUuid();
        }
        
        $this->callApi('POST', self::TokenURL, [
            'amount'            => $this->invoice->getAmount() * 10, // convert toman to rial,
            'redirectUri'       => $this->settings->redirectUri . '/' . $uuid,
            'terminalId'        => $this->settings->terminalId,
            'uniqueIdentifier'  => $uuid
        ]);
    }

    /**
     * send an API request
     *
     * @param $method
     * @param $url
     * @param array $data
     * @return void
     */
    protected function callApi($method, $route, $data = []): void
    {
        $client = new Client();
        $response = $client->request($method, $this->settings->bankApiUrl . $route, [
            "json" => $data,
            "headers" => [
                'Content-Type' => 'application/json',
                'Authorization'=> 'Basic '. base64_encode($this->settings->username . ':' . $this->settings->password),
            ],
            "http_errors" => false,
        ]);

        $this->status_code = $response->getStatusCode();
        $this->response = json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Rollback a transaction
     *
     * @return bool
     * @throws InvalidPaymentException
     */
    protected function rollback()
    {
        $this->callApi('POST', self::RollbackURL, [
            'token' => $this->invoice->getTransactionId()
        ]);
        
        return $this->httpCodeTranslator($this->response->status_code, true);
    }

    /**
     * Refund a transaction
     *
     * @return bool
     * @throws InvalidPaymentException
     */
    protected function refund()
    {
        $this->callApi('POST', self::RefundURL, [
            'amount'                => $this->invoice->getAmount() * 10, // convert to rial.
            'uniqueIdentitifier'    => $this->invoice->getDetail('uuid'),
            'resNum'                => ''
        ]);

        return $this->httpCodeTranslator($this->response->status_code, true);
    }

    /**
     * Refund a transaction without limitation
     *
     * @param int $refrence_id
     * @return bool
     * @throws InvalidPaymentException
     */
    protected function refundWithoutLimitation(int $refrence_id)
    {
        $this->callApi('POST', self::NolimitrefundURL, [
            'amount'    => $this->invoice->getAmount() * 10, // convert to rial.
            'grantId'   => $refrence_id,
            'resNum'    => ''
        ]);

        return $this->httpCodeTranslator($this->response->status_code, true);
    }

    /**
     * Trigger an exception
     *
     * @param int $status
     * @param bool $return
     *
     * @return bool
     * @throws InvalidPaymentException
     */
    protected function httpCodeTranslator(int $status, bool $return = false)
    {
        if($return && $status == 200) {
            return true;
        }

        $translations = [
            400 => "خطا در ورودی‌های درخواست یا انجام عملیات",
            401 => "خطا در اطلاعات کاربری یا رمز عبور",
            500 => "خطایی در سیستم رخ داده است"
        ];

        if (array_key_exists($status, $translations)) {
            throw new InvalidPaymentException($translations[$status]);
        } else {
            throw new InvalidPaymentException('یک خطای ناشناخته در سیستم رخ داده است.');
        }
    }
}