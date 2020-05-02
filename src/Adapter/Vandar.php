<?php

namespace Tartan\Larapay\Adapter;

use Tartan\Larapay\Adapter\Vandar\Exception;
use Illuminate\Support\Facades\Log;
use Tartan\Larapay\Pasargad\Helper;
use Tartan\Larapay\Transaction\TransactionInterface;

class Vandar extends AdapterAbstract implements AdapterInterface
{
    public $endPoint = 'https://vandar.io/api/ipg/send';
    public $endPointForm = 'https://vandar.io/ipg/';
    public $endPointVerify = 'https://vandar.io/api/ipg/verify';

    public $reverseSupport = false;

    /**
     * @return array
     * @throws Exception
     */
    protected function requestToken()
    {


        if ($this->getTransaction()->checkForRequestToken() == false) {
            throw new Exception('larapay::larapay.could_not_request_payment');
        }

        $this->checkRequiredParameters([
            'api',
            'amount',
            'redirect_url',
            'order_id',
        ]);



        $sendParams = [
            'api'          => $this->api,
            'amount'       => intval($this->amount),
            'factorNumber' => ($this->order_id),
            'description'  => $this->order_id,
            'mobile'       => $this->mobile ? $this->mobile : '',
            'redirect'     => $this->redirect_url,
        ];

        try {


            Log::debug('PaymentRequest call', $sendParams);

            $result = $this->__send($this->api, $this->amount, $this->redirect_url, $sendParams['mobile'], $sendParams['factorNumber'], $sendParams['description']);


            $resultobj = json_decode($result);
            Log::info('PaymentRequest response', $this->obj2array($resultobj));


            if (isset($resultobj->status)) {


                if ($resultobj->status == 1) {
                    $this->getTransaction()->setReferenceId($resultobj->token); // update transaction reference id
                    return $resultobj->token;
                } else {
                    throw new Exception($resultobj->status);
                }
            } else {
                throw new Exception('larapay::larapay.invalid_response');
            }
        } catch (\Exception $e) {
            throw new Exception('PayIr Fault: ' . $e->getMessage() . ' #' . $e->getCode(), $e->getCode());
        }
    }


    /**
     * @return mixed
     */
    protected function generateForm()
    {
        $authority = $this->requestToken();
        return view('larapay::vandar-form', [
            'endPoint'    => $this->endPointForm . $authority,
            'submitLabel' => !empty($this->submit_label) ? $this->submit_label : trans("larapay::larapay.goto_gate"),
            'autoSubmit'  => true,
            'token' => $authority,
        ]);
    }

    /**
     * @return bool
     * @throws Exception
     */
    protected function verifyTransaction()
    {
        if ($this->getTransaction()->checkForVerify() == false) {
            throw new Exception('larapay::larapay.could_not_verify_payment');
        }

        $this->checkRequiredParameters([
            'api',
            'token',
        ]);

        $sendParams = [
            'api'     => $this->api,
            'token' => $this->token,
        ];

        try {

            Log::debug('PaymentVerification call', $sendParams);
            $result = $this->__verify($this->api, $this->token);
            $response = json_decode($result);
            Log::info('PaymentVerification response', $this->obj2array($response));


            if (isset($response->status)) {

                if ($response->status == 1) {
                    $this->getTransaction()->setVerified();
                    $this->getTransaction()->setReferenceId($this->token); // update transaction reference id
                    return true;
                } else {
                    throw new Exception($response->status);
                }
            } else {
                throw new Exception('larapay::larapay.invalid_response');
            }

        } catch (\Exception $e) {

            throw new Exception('vandar: ' . $e->getMessage() . ' #' . $e->getCode(), $e->getCode());
        }
    }

    /**
     * @return bool
     */
    public function canContinueWithCallbackParameters()
    {

        if (!empty($this->parameters['token'])) {
            return true;
        }
        return false;
    }

    public function getGatewayReferenceId()
    {
        $this->checkRequiredParameters([
            'token',
        ]);
        return $this->token;
    }

    private function __verify($api, $token)
    {
        return $this->curl_post(
            $this->endPointVerify,
            [
                'api_key' => $api,
                'token'   => $token,
            ]
        );
    }

    private function __send($api, $amount, $redirect,
                            $mobile = null, $factorNumber = null
        , $description = null)
    {
        return $this->curl_post(
            $this->endPoint,
            [
                'api_key'       => $api,
                'amount'        => $amount,
                'callback_url'  => $redirect,
                'mobile_number' => $mobile,
                'factorNumber'  => $factorNumber,
                'description'   => $description,
            ]
        );
    }

    private function curl_post($action, $params)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $action);
        curl_setopt($ch, CURLOPT_POSTFIELDS,
            json_encode($params));
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    }
}
