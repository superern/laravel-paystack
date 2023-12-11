<?php

/*
 * This file is part of the Laravel Paystack package.
 *
 * (c) Superern <superern14@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Superern\Paystack;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Util\Json;
use Superern\Paystack\Exceptions\IsNullException;
use Superern\Paystack\Exceptions\PaymentVerificationFailedException;

class Paystack
{
    /**
     * Transaction Verification Successful
     */
    const VS = 'Verification successful';

    /**
     *  Invalid Transaction reference
     */
    const ITF = "Invalid transaction reference";

    /**
     * Issue Secret Key from your Paystack Dashboard
     * @var string
     */
    protected $secretKey;

    /**
     * Instance of Client
     * @var Client
     */
    protected $client;

    /**
     *  Response from requests made to Paystack
     * @var mixed
     */
    protected $response;

    /**
     * Paystack API base Url
     * @var string
     */
    protected $baseUrl;

    /**
     * Authorization Url - Paystack payment page
     * @var string
     */
    protected $authorizationUrl;

    public function __construct()
    {
        $this->setKey();
        $this->setBaseUrl();
        $this->setRequestOptions();
    }

    /**
     * Get Base Url from Paystack config file
     */
    public function setBaseUrl()
    {
        $this->baseUrl = Config::get('paystack.paymentUrl');
    }

    /**
     * Get secret key from Paystack config file
     */
    public function setKey()
    {
        $this->secretKey = Config::get('paystack.secretKey');
    }

    /**
     * Set options for making the Client request
     */
    private function setRequestOptions()
    {
        $authBearer = 'Bearer ' . $this->secretKey;

        $this->client = new Client(
            [
                'base_uri' => $this->baseUrl,
                'headers' => [
                    'Authorization' => $authBearer,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json'
                ]
            ]
        );
    }


    /**
     * Initiate a payment request to Paystack
     * Included the option to pass the payload to this method for situations
     * when the payload is built on the fly (not passed to the controller from a view)
     * @return Paystack
     * @throws IsNullException
     */

    public function makePaymentRequest($data = null): Paystack
    {
        if ($data == null) {

            $quantity = intval(request()->quantity ?? 1);

            $data = array_filter([
                "amount" => intval(request()->amount) * $quantity,
                "reference" => request()->reference,
                "email" => request()->email,
                "plan" => request()->plan,
                "first_name" => request()->first_name,
                "last_name" => request()->last_name,
                "callback_url" => request()->callback_url,
                "currency" => (request()->currency != ""  ? request()->currency : "NGN"),

                /*
                    Paystack allows for transactions to be split into a subaccount -
                    The following lines trap the subaccount ID - as well as the ammount to charge the subaccount (if overriden in the form)
                    both values need to be entered within hidden input fields
                */
                "subaccount" => request()->subaccount,
                "transaction_charge" => request()->transaction_charge,

                /**
                 * Paystack allows for transaction to be split into multi accounts(subaccounts)
                 * The following lines trap the split ID handling the split
                 * More details here: https://paystack.com/docs/payments/multi-split-payments/#using-transaction-splits-with-payments
                 */
                "split_code" => request()->split_code,

                /**
                 * Paystack allows transaction to be split into multi account(subaccounts) on the fly without predefined split
                 * form need an input field: <input type="hidden" name="split" value="{{ json_encode($split) }}" >
                 * array must be set up as:
                 *  $split = [
                 *    "type" => "percentage",
                 *     "currency" => "KES",
                 *     "subaccounts" => [
                 *       { "subaccount" => "ACCT_li4p6kte2dolodo", "share" => 10 },
                 *       { "subaccount" => "ACCT_li4p6kte2dolodo", "share" => 30 },
                 *     ],
                 *     "bearer_type" => "all",
                 *     "main_account_share" => 70,
                 * ]
                 * More details here: https://paystack.com/docs/payments/multi-split-payments/#dynamic-splits
                 */
                "split" => request()->split,
                /*
                * to allow use of metadata on Paystack dashboard and a means to return additional data back to redirect url
                * form need an input field: <input type="hidden" name="metadata" value="{{ json_encode($array) }}" >
                * array must be set up as:
                * $array = [ 'custom_fields' => [
                *                   ['display_name' => "Cart Id", "variable_name" => "cart_id", "value" => "2"],
                *                   ['display_name' => "Sex", "variable_name" => "sex", "value" => "female"],
                *                   .
                *                   .
                *                   .
                *                  ]
                *          ]
                */
                'metadata' => request()->metadata
            ]);
        }

        $this->setHttpResponse('/transaction/initialize', 'POST', $data);

        return $this;
    }


    /**
     * @param  string  $relativeUrl
     * @param  string  $method
     * @param  array  $body
     * @return Paystack
     * @throws IsNullException
     */
    private function setHttpResponse(string $relativeUrl, string $method, array $body = []): Paystack
    {
        $this->response = $this->client->{strtolower($method)}(
            $this->baseUrl . $relativeUrl,
            ["body" => json_encode($body)]
        );

        return $this;
    }

    /**
     * Get the authorization url from the callback response
     * @return Paystack
     * @throws IsNullException
     */
    public function getAuthorizationUrl($data = null): Paystack
    {
        $this->makePaymentRequest($data);

        $response = $this->getResponse();
        $this->url = $response->data->authorization_url;

        return $this;
    }

    /**
     * Get the authorization callback response
     * In situations where Laravel serves as an backend for a detached UI, the api cannot redirect
     * and might need to take different actions based on the success or not of the transaction
     * @return array
     * @throws IsNullException
     */
    public function getAuthorizationResponse($data): array
    {
        $this->makePaymentRequest($data);

        $response = $this->getResponse();

        $this->url = $response->data->authorization_url;

        return $response;
    }

    /**
     * Hit Paystack Gateway to Verify that the transaction is valid
     * @throws GuzzleException
     */
    private function verifyTransactionAtGateway($transaction_id = null)
    {
        $transactionRef = $transaction_id ?? request()->query('trxref');

        $relativeUrl = "/transaction/verify/{$transactionRef}";

        $this->response = $this->client->get($this->baseUrl . $relativeUrl, []);
    }

    /**
     * True or false condition whether the transaction is verified
     * @return boolean
     * @throws GuzzleException
     */
    public function isTransactionVerificationValid($transaction_id = null): bool
    {
        $this->verifyTransactionAtGateway($transaction_id);

        $result = $this->getResponse()['message'];

        switch ($result) {
            case self::VS:
                $validate = true;
                break;
            case self::ITF:
                $validate = false;
                break;
            default:
                $validate = false;
                break;
        }

        return $validate;
    }

    /**
     * Get Payment details if the transaction was verified successfully
     * @return json
     * @throws PaymentVerificationFailedException
     * @throws GuzzleException
     */
    public function getPaymentData(): json
    {
        if ($this->isTransactionVerificationValid()) {
            return $this->getResponse();
        } else {
            throw new PaymentVerificationFailedException("Invalid Transaction Reference");
        }
    }

    /**
     * Fluent method to redirect to Paystack Payment Page
     */
    public function redirectNow()
    {
        return redirect($this->url);
    }

    /**
     * Get Access code from transaction callback respose
     * @return string
     */
    public function getAccessCode(): string
    {
        return $this->getResponse()['data']['access_code'];
    }

    /**
     * Generate a Unique Transaction Reference
     * @return string
     */
    public function genTranxRef(): string
    {
        return TransRef::getHashedToken();
    }

    /**
     * Get all the customers that have made transactions on your platform
     * @return array
     * @throws IsNullException
     */
    public function getAllCustomers(): array
    {
        $this->setRequestOptions();

        return $this->setHttpResponse("/customer", 'GET', [])->getData();
    }

    /**
     * Get all the plans that you have on Paystack
     * @return array
     * @throws IsNullException
     */
    public function getAllPlans(): array
    {
        $this->setRequestOptions();

        return $this->setHttpResponse("/plan", 'GET', [])->getData();
    }

    /**
     * Get all the transactions that have happened overtime
     * @return array
     * @throws IsNullException
     */
    public function getAllTransactions(): array
    {
        $this->setRequestOptions();

        return $this->setHttpResponse("/transaction", 'GET', [])->getData();
    }

    /**
     * Get the whole response from a get operation
     */
    private function getResponse()
    {
        $body = json_decode($this->response->getBody(), true);
        return json_decode(json_encode($body));
    }

    /**
     * Get the data response from a get operation
     * @return array
     */
    private function getData(): array
    {
        return $this->getResponse()->data;
    }

    /**
     * Create a plan
     * @throws IsNullException
     */
    public function createPlan()
    {
        $data = [
            "name" => request()->name,
            "description" => request()->desc,
            "amount" => intval(request()->amount),
            "interval" => request()->interval,
            "send_invoices" => request()->send_invoices,
            "send_sms" => request()->send_sms,
            "currency" => request()->currency,
        ];

        $this->setRequestOptions();

        return $this->setHttpResponse("/plan", 'POST', $data)->getResponse();
    }

    /**
     * Fetch any plan based on its plan id or code
     * @param $plan_code
     * @return mixed
     * @throws IsNullException
     */
    public function fetchPlan($plan_code)
    {
        $this->setRequestOptions();
        return $this->setHttpResponse('/plan/' . $plan_code, 'GET', [])->getResponse();
    }

    /**
     * Update any plan's details based on its id or code
     * @param $plan_code
     * @return mixed
     * @throws IsNullException
     */
    public function updatePlan($plan_code)
    {
        $data = [
            "name" => request()->name,
            "description" => request()->desc,
            "amount" => intval(request()->amount),
            "interval" => request()->interval,
            "send_invoices" => request()->send_invoices,
            "send_sms" => request()->send_sms,
            "currency" => request()->currency,
        ];

        $this->setRequestOptions();
        return $this->setHttpResponse('/plan/' . $plan_code, 'PUT', $data)->getResponse();
    }

    /**
     * Create a customer
     * @throws IsNullException
     */
    public function createCustomer(array $data)
    {
        $this->setRequestOptions();
        return $this->setHttpResponse('/customer', 'POST', $data)->getResponse();
    }

    /**
     * Create a Recipient
     * @throws IsNullException
     */
    public function createRecipient(array $data)
    {
        $rules = [
            'type' => 'required',
            'name' => 'required',
            'account_number' => 'required',
            'bank_code' => 'required',
            'currency' => 'nullable',
        ];

        // Create a validator instance
        $validator = Validator::make($data, $rules);

        // If currency is not provided, set it to the default value 'NGN'
        $data['currency'] = $data['currency'] ?? 'NGN';

        // Check if validation fails
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $this->setRequestOptions();

        return $this
            ->setHttpResponse('/transferrecipient', 'POST', $data)
            ->getResponse();
    }

    /**
     * Get Recipient based on id or recipient_code
     * @param  string  $recipient_code
     * @return mixed
     * @throws IsNullException
     */
    public function getRecipient(string $recipient_code)
    {
        $this->setRequestOptions();
        return $this->setHttpResponse('/transferrecipient/' . $recipient_code, 'GET')->getResponse();
    }

    /**
     * Update Recipient's details based on id or recipient_code
     * @param  string  $recipient_id
     * @param  array  $data
     * @return mixed
     * @throws IsNullException
     */
    public function updateRecipient(string $recipient_id, array $data)
    {
        $this->setRequestOptions();
        return $this->setHttpResponse('/transferrecipient/' . $recipient_id, 'PUT', $data)->getResponse();
    }

    /**
     * Get all Recipients
     * @throws IsNullException
     */
    public function getAllRecipient()
    {
        return $this->setHttpResponse('/transferrecipient', 'GET')->getResponse();
    }


    /**
     * Fetch a customer based on id or code
     * @param $customer_id
     * @return mixed
     * @throws IsNullException
     */
    public function fetchCustomer($customer_id)
    {
        $this->setRequestOptions();
        return $this->setHttpResponse('/customer/' . $customer_id, 'GET', [])->getResponse();
    }

    /**
     * Update a customer's details based on their id or code
     * @param  string  $customer_id
     * @param  array  $data
     * @return mixed
     * @throws IsNullException
     */
    public function updateCustomer(string $customer_id, array $data)
    {
        $this->setRequestOptions();
        return $this->setHttpResponse('/customer/' . $customer_id, 'PUT', $data)->getResponse();
    }

    /**
     * Export transactions in .CSV
     * @throws IsNullException
     */
    public function exportTransactions()
    {
        $data = [
            "from" => request()->from,
            "to" => request()->to,
            'settled' => request()->settled
        ];

        $this->setRequestOptions();
        return $this->setHttpResponse('/transaction/export', 'GET', $data)->getResponse();
    }

    /**
     * Create a subscription to a plan from a customer.
     * @throws IsNullException
     */
    public function createSubscription()
    {
        $data = [
            "customer" => request()->customer, //Customer email or code
            "plan" => request()->plan,
            "authorization" => request()->authorization_code
        ];

        $this->setRequestOptions();
        return $this->setHttpResponse('/subscription', 'POST', $data)->getResponse();
    }

    /**
     * Get all the subscriptions made on Paystack.
     *
     * @throws IsNullException
     */
    public function getAllSubscriptions(): array
    {
        $this->setRequestOptions();

        return $this->setHttpResponse("/subscription", 'GET', [])->getData();
    }

    /**
     * Get customer subscriptions
     *
     * @param  integer  $customer_id
     * @return array
     * @throws IsNullException
     */
    public function getCustomerSubscriptions(int $customer_id): array
    {
        $this->setRequestOptions();

        return $this->setHttpResponse('/subscription?customer=' . $customer_id, 'GET', [])->getData();
    }

    /**
     * Get plan subscriptions
     *
     * @param  integer  $plan_id
     * @return array
     * @throws IsNullException
     */
    public function getPlanSubscriptions(int $plan_id): array
    {
        $this->setRequestOptions();

        return $this->setHttpResponse('/subscription?plan=' . $plan_id, 'GET', [])->getData();
    }

    /**
     * Enable a subscription using the subscription code and token
     * @throws IsNullException
     */
    public function enableSubscription()
    {
        $data = [
            "code" => request()->code,
            "token" => request()->token,
        ];

        $this->setRequestOptions();
        return $this->setHttpResponse('/subscription/enable', 'POST', $data)->getResponse();
    }

    /**
     * Disable a subscription using the subscription code and token
     * @throws IsNullException
     */
    public function disableSubscription()
    {
        $data = [
            "code" => request()->code,
            "token" => request()->token,
        ];

        $this->setRequestOptions();
        return $this->setHttpResponse('/subscription/disable', 'POST', $data)->getResponse();
    }

    /**
     * Fetch details about a certain subscription
     * @param  mixed  $subscription_id
     * @throws IsNullException
     */
    public function fetchSubscription($subscription_id)
    {
        $this->setRequestOptions();
        return $this->setHttpResponse('/subscription/' . $subscription_id, 'GET', [])->getResponse();
    }

    /**
     * Create pages you can share with users using the returned slug
     * @throws IsNullException
     */
    public function createPage()
    {
        $data = [
            "name" => request()->name,
            "description" => request()->description,
            "amount" => request()->amount
        ];

        $this->setRequestOptions();
        return $this->setHttpResponse('/page', 'POST', $data)->getResponse();
    }

    /**
     * Fetches all the pages the merchant has
     * @throws IsNullException
     */
    public function getAllPages()
    {
        $this->setRequestOptions();
        return $this->setHttpResponse('/page', 'GET', [])->getResponse();
    }

    /**
     * Fetch details about a certain page using its id or slug
     * @param  mixed  $page_id
     * @throws IsNullException
     */
    public function fetchPage($page_id)
    {
        $this->setRequestOptions();
        return $this->setHttpResponse('/page/' . $page_id, 'GET', [])->getResponse();
    }

    /**
     * Update the details about a particular page
     * @param $page_id
     * @return mixed
     * @throws IsNullException
     */
    public function updatePage($page_id)
    {
        $data = [
            "name" => request()->name,
            "description" => request()->description,
            "amount" => request()->amount
        ];

        $this->setRequestOptions();
        return $this->setHttpResponse('/page/' . $page_id, 'PUT', $data)->getResponse();
    }

    /**
     * Creates a SubAccount to be used for split payments . Required    params are business_name , settlement_bank ,
     * account_number ,   percentage_charge
     *
     * @throws IsNullException
     */

    public function createSubAccount()
    {
        $data = [
            "business_name" => request()->business_name,
            "settlement_bank" => request()->settlement_bank,
            "account_number" => request()->account_number,
            "percentage_charge" => request()->percentage_charge,
            "primary_contact_email" => request()->primary_contact_email,
            "primary_contact_name" => request()->primary_contact_name,
            "primary_contact_phone" => request()->primary_contact_phone,
            "metadata" => request()->metadata,
            'settlement_schedule' => request()->settlement_schedule
        ];

        $this->setRequestOptions();
        return $this->setHttpResponse('/subaccount', 'POST', array_filter($data))->getResponse();
    }

    /**
     * Fetches details of a SubAccount
     * @param  $subAccountCode
     * @return mixed
     * @throws IsNullException
     */
    public function fetchSubAccount($subAccountCode)
    {

        $this->setRequestOptions();
        return $this->setHttpResponse("/subaccount/{$subAccountCode}", "GET", [])->getResponse();
    }

    /**
     * Lists all the subaccounts associated with the account
     * @param $per_page  - Specifies how many records to retrieve per page , $page - SPecifies exactly what page to retrieve
     * @throws IsNullException
     */
    public function listSubAccounts($per_page, $page)
    {

        $this->setRequestOptions();
        return $this->setHttpResponse("/subaccount/?perPage=" . (int) $per_page . "&page=" . (int) $page, "GET")->getResponse();
    }


    /**
     * Updates a SubAccount to be used for split payments . Required params are business_name , settlement_bank ,
     * account_number , percentage_charge
     * @param  $subAccountCode
     * @return mixed
     * @throws IsNullException
     */

    public function updateSubAccount($subAccountCode)
    {
        $data = [
            "business_name" => request()->business_name,
            "settlement_bank" => request()->settlement_bank,
            "account_number" => request()->account_number,
            "percentage_charge" => request()->percentage_charge,
            "description" => request()->description,
            "primary_contact_email" => request()->primary_contact_email,
            "primary_contact_name" => request()->primary_contact_name,
            "primary_contact_phone" => request()->primary_contact_phone,
            "metadata" => request()->metadata,
            'settlement_schedule' => request()->settlement_schedule
        ];

        $this->setRequestOptions();
        return $this->setHttpResponse("/subaccount/{$subAccountCode}", "PUT", array_filter($data))->getResponse();
    }
}
