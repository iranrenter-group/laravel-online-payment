<?php

namespace Tartan\Larapay\Adapter;

use Tartan\Larapay\Adapter\Vandar\Exception;
use Illuminate\Support\Facades\Log;
use Tartan\Larapay\Pasargad\Helper;
use Tartan\Larapay\Transaction\TransactionInterface;

class Zibal extends AdapterAbstract implements AdapterInterface
{
    public $endPoint = 'https://gateway.zibal.ir/v1/request';
    public $endPointForm = 'https://gateway.zibal.ir/start/';
    public $endPointVerify = 'https://gateway.zibal.ir/v1/verify';

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
            'merchant',
            'amount',
            'redirect_url',
            'order_id',
        ]);


        $sendParams = [
            'merchant'    => $this->merchant,
            'amount'      => intval($this->amount),
            'orderId'     => ($this->order_id),
            'description' => $this->description ? $this->description : '',
            'mobile'      => $this->mobile ? $this->mobile : '',
            'callbackUrl' => $this->redirect_url,
        ];

        try {
            Log::debug('PaymentRequest call', $sendParams);

            $result = $this->postToZibal('request', $sendParams);


            $resultobj = json_decode($result);
            Log::info('PaymentRequest response', $this->obj2array($resultobj));


            if (isset($resultobj->result)) {
                if ($resultobj->result == 100) {
                    $this->getTransaction()->setReferenceId($resultobj->trackId); // update transaction reference id
                    return $resultobj->trackId;
                } else {
                    throw new Exception($resultobj->result);
                }
            } else {
                throw new Exception('larapay::larapay.invalid_response');
            }
        } catch (\Exception $e) {
            throw new Exception('zibal Fault: '.$e->getMessage().' #'.$e->getCode(), $e->getCode());
        }
    }


    /**
     * @return mixed
     */
    protected function generateForm()
    {
        $authority = $this->requestToken();
        return view('larapay::vandar-form', [
            'endPoint'    => $this->endPointForm.$authority,
            'submitLabel' => !empty($this->submit_label) ? $this->submit_label : trans("larapay::larapay.goto_gate"),
            'autoSubmit'  => true,
            'token'       => $authority,
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
            'merchant',
            'trackId',
        ]);

        $sendParams = [
            'merchant' => $this->merchant,
            'trackId'  => $this->trackId,
        ];

        try {
            Log::debug('PaymentVerification call', $sendParams);
            $result = $this->postToZibal('verify', $sendParams);

            $response = json_decode($result);
            Log::info('PaymentVerification response', $this->obj2array($response));

            if (isset($response->result)) {
                if ($response->result == 100) {
                    $this->getTransaction()->setVerified();
                    $this->getTransaction()->setReferenceId($response->refNumber); // update transaction reference id
                    return true;
                } else {
                    throw new Exception($response->status);
                }
            } else {
                throw new Exception('larapay::larapay.invalid_response');
            }
        } catch (\Exception $e) {
            throw new Exception('zibal: '.$e->getMessage().' #'.$e->getCode(), $e->getCode());
        }
    }

    /**
     * @return bool
     */
    public function canContinueWithCallbackParameters()
    {
        if (!empty($this->parameters['trackId'])) {
            return true;
        }
        return false;
    }

    public function getGatewayReferenceId()
    {
        $this->checkRequiredParameters([
            'trackId',
        ]);
        return $this->trackId;
    }

    public function postToZibal($path, $parameters)
    {
        $url = 'https://gateway.zibal.ir/v1/'.$path;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }
}
