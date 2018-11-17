<?php
namespace Tartan\Larapay\Adapter;

use SoapClient;
use SoapFault;
use Tartan\Larapay\Adapter\Saman\Exception;
use Illuminate\Support\Facades\Log;

class Novinrenter extends AdapterAbstract implements AdapterInterface
{
	protected $WSDL         = 'https://novinrenter.com/payment/paymentService.php';
	protected $tokenWSDL    = 'https://novinrenter.com/payment/paymentService.php';

	protected $endPoint     = 'https://novinrenter.com/payment/pay.php';


	protected $reverseSupport = false;

	/**
	 * @return array
	 * @throws Exception
	 */
	protected function requestToken()
	{
		if($this->getTransaction()->checkForRequestToken() == false) {
			throw new Exception('larapay::larapay.could_not_request_payment');
		}

		$this->checkRequiredParameters([
			'api',
			'order_id',
			'amount',
			'redirect_url',
		]);


		$sendParams = [
			'api'      => $this->api,
			'amount'      => $this->amount,
			'redirectAddress'      => $this->redirect_url,
			'order_id'      => $this->order_id,
			'description' => $this->additional_data ? $this->additional_data : '',
		];

		try {
//            $soapClient = $this->getSoapClient();

            $soapClient = new SoapClient(null,['location' => $this->tokenWSDL,'uri' => $this->tokenWSDL]);
			Log::debug('RequestToken call', $sendParams);

			$response = $soapClient->__soapCall('pay', $sendParams);

			if (!empty($response))
			{

                $response = json_decode($response,true);

				Log::info('RequestToken response', ['response' => $response]);

				if (array_key_exists('code',$response) && $response['code'] == '0') { // got string token
					$this->getTransaction()->setReferenceId($response['authority']); // update transaction reference id
					return $response;
				} else {
					throw new Exception($response); // negative integer as error
				}
			}
			else {
				throw new Exception('larapay::larapay.invalid_response');
			}

		} catch (SoapFault $e) {
			throw new Exception('SoapFault: ' . $e->getMessage() . ' #' . $e->getCode(), $e->getCode());
		}
	}

	public function generateForm()
	{
			return $this->generateFormWithToken();
	}



	protected function generateFormWithToken()
	{
		Log::debug(__METHOD__, $this->getParameters());
		$this->checkRequiredParameters([
			'api',
			'order_id',
			'amount',
			'redirect_url',
		]);

		$token = $this->requestToken();

		Log::info(__METHOD__, ['fetchedToken' => $token]);

		return view('larapay::novinrenter-form', [
			'endPoint'    => $this->getEndPoint(),
			'token'    => $token['authority'],
			'autoSubmit'  => true









            ,
            'submitLabel' => !empty($this->submit_label) ? $this->submit_label : trans("larapay::larapay.goto_gate"),

        ]);
	}

	protected function verifyTransaction()
	{
		if($this->getTransaction()->checkForVerify() == false) {
			throw new Exception('larapay::larapay.could_not_verify_payment');
		}

		$this->checkRequiredParameters([
			'api',
			'iN',
			'tref',
		]);



		try {
            $soapClient = new SoapClient(null,['location' => $this->tokenWSDL,'uri' => $this->tokenWSDL]);

			Log::info('VerifyTransaction call', [$this->iN, $this->tref]);

            $sendParams = [
                'api'      => $this->api,
                'invoice_number'      => $this->iN,
                'bank_references'      => $this->tref,
            ];

            $response = $soapClient->__soapCall('verify', $sendParams);

			if (isset($response))
			{
				Log::info('VerifyTransaction response', ['response' => $response]);


                $response = json_decode($response,true);

                if ($response['status'] == 'success' && $response['code'] == '0') { // check by transaction amount
					$this->getTransaction()->setVerified();
					return true;
				} else {
					throw new Exception($response);
				}
			}
			else {
				throw new Exception('larapay::larapay.invalid_response');
			}

		} catch (SoapFault $e) {
			throw new Exception('SoapFault: ' . $e->getMessage() . ' #' . $e->getCode(), $e->getCode());
		}
	}


	/**
	 * @return bool
	 */
	public function canContinueWithCallbackParameters()
	{
		try {
			$this->checkRequiredParameters([
				'iN',
				'tref'
			]);
		} catch (\Exception $e) {
			return false;
		}

		if ($this->code == '0') {
			return true;
		}
		return false;
	}

	public function getGatewayReferenceId()
	{
		$this->checkRequiredParameters([
			'iN',
		]);
		return $this->iN;
	}

}
