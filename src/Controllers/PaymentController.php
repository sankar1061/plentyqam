<?php
/**
 * This module is used for real time processing of
 * Novalnet payment module of customers.
 * Released under the GNU General Public License.
 * This free contribution made by request.
 * 
 * If you have found this script useful a small
 * recommendation as well as a comment on merchant form
 * would be greatly appreciated.
 *
 * @author       Novalnet AG
 * @copyright(C) Novalnet AG. 
 * All rights reserved. https://www.novalnet.de/payment-plugins/kostenpflichtig/lizenz
 */

namespace Novalnet\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Novalnet\Helper\PaymentHelper;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Novalnet\Services\PaymentService;
use Plenty\Plugin\Templates\Twig;
use Plenty\Plugin\ConfigRepository;

/**
 * Class PaymentController
 *
 * @package Novalnet\Controllers
 */
class PaymentController extends Controller
{
	/**
	 * @var Request
	 */
	private $request;

	/**
	 * @var Response
	 */
	private $response;

	/**
	 * @var PaymentHelper
	 */
	private $paymentHelper;

	/**
	 * @var SessionStorageService
	 */
	private $sessionStorage;

	/**
	 * @var basket
	 */
	private $basketRepository;

	/**
	 * @var PaymentService
	 */
	private $paymentService;

	/**
	 * @var Twig
	 */
	private $twig;
	
	/**
	 * @var ConfigRepository
	 */
	private $config;

	/**
	 * PaymentController constructor.
	 *
	 * @param Request $request
	 * @param Response $response
	 * @param ConfigRepository $config
	 * @param PaymentHelper $paymentHelper
	 * @param SessionStorageService $sessionStorage
	 * @param BasketRepositoryContract $basketRepository
	 * @param PaymentService $paymentService
	 * @param Twig $twig
	 */
	public function __construct(  Request $request,
								  Response $response,
								  ConfigRepository $config,
								  PaymentHelper $paymentHelper,
								  FrontendSessionStorageFactoryContract $sessionStorage,
								  BasketRepositoryContract $basketRepository,
								  PaymentService $paymentService,
								  Twig $twig
								)
	{

		$this->request         = $request;
		$this->response        = $response;
		$this->paymentHelper   = $paymentHelper;
		$this->sessionStorage  = $sessionStorage;
		$this->basketRepository  = $basketRepository;
		$this->paymentService  = $paymentService;
		$this->twig            = $twig;
		$this->config          = $config;
	}

	/**
	 * Novalnet redirects to this page if the payment was executed successfully
	 *
	 */
	public function paymentResponse() {
		$responseData = $this->request->all();
		$isPaymentSuccess = isset($responseData['status']) && in_array($responseData['status'], ['90','100']);
		$notificationType = $isPaymentSuccess ? 'success' : 'error';
		$this->paymentService->pushNotification($notificationType, $this->paymentHelper->getNovalnetStatusText($responseData));		
		$responseData['test_mode'] = $this->paymentHelper->decodeData($responseData['test_mode'], $responseData['uniqid']);
		$responseData['amount']    = $this->paymentHelper->decodeData($responseData['amount'], $responseData['uniqid']) / 100;
		$paymentRequestData = $this->sessionStorage->getPlugin()->getValue('nnPaymentData');
		$this->sessionStorage->getPlugin()->setValue('nnPaymentData', array_merge($paymentRequestData, $responseData));
		$this->paymentService->validateResponse();
		return $this->response->redirectTo('confirmation');
	}

	/**
	 * Process the Form payment
	 *
	 */
	public function processPayment()
	{
		$requestData = $this->request->all();		
		$serverRequestData = $this->paymentService->getRequestParameters($this->basketRepository->load(), $requestData['paymentKey']);
		$this->sessionStorage->getPlugin()->setValue('nnPaymentData', $serverRequestData['data']);
		$guarantee_payments = [ 'NOVALNET_SEPA', 'NOVALNET_INVOICE' ];        
		if($requestData['paymentKey'] == 'NOVALNET_CC') {
			$serverRequestData['data']['pan_hash'] = $requestData['nn_pan_hash'];
			$serverRequestData['data']['unique_id'] = $requestData['nn_unique_id'];
			if($this->config->get('Novalnet.novalnet_cc_3d') == 'true' || $this->config->get('Novalnet.novalnet_cc_3d_fraudcheck') == 'true' )
			{
				$this->sessionStorage->getPlugin()->setValue('nnPaymentData', $serverRequestData['data']);
				$this->sessionStorage->getPlugin()->setValue('nnPaymentUrl',$serverRequestData['url']);
				return $this->response->redirectTo('place-order');
			}
		}
		// Mandatory params for SEPA
		if ( $requestData['paymentKey'] == 'NOVALNET_SEPA' ) {
			$serverRequestData['data']['bank_account_holder'] = $requestData['nn_sepa_cardholder'];
			$serverRequestData['data']['iban'] = $requestData['nn_sepa_iban'];	
		}            
			
		$guaranteeStatus = $this->paymentService->getGuaranteeStatus($this->basketRepository->load(), $requestData['paymentKey']);                        
			
		if($guaranteeStatus['status'])
		{		   
			$birthday     = ( $requestData['paymentKey'] == 'NOVALNET_SEPA' ) ? $requestData['nn_sepa_birthday'] : $requestData['nn_invoice_birthday'];
			// Guarantee payment params 
			if( $requestData['paymentKey'] == 'NOVALNET_SEPA' ) {
				$serverRequestData['data']['payment_type'] = 'GUARANTEED_DIRECT_DEBIT_SEPA';
				$serverRequestData['data']['key']          = '40';
				$serverRequestData['data']['birth_date']   = $birthday;
			} else {						
				$serverRequestData['data']['payment_type'] = 'GUARANTEED_INVOICE';
				$serverRequestData['data']['key']          = '41';
				$serverRequestData['data']['birth_date']   = $birthday;							
			}
			// Proceed as non gurantee if condition for birthdate doesn't meet as well as force is enable
			if($this->config->get( 'Novalnet'.$requestData['paymentKey'].'_payment_guarantee_force_active' ) == 'true') {
				if( $requestData['paymentKey'] == 'NOVALNET_SEPA' ) {
					$serverRequestData['data']['payment_type'] = 'DIRECT_DEBIT_SEPA';
					$serverRequestData['data']['key']          = '37';
				} else {
					$serverRequestData['data']['payment_type'] = 'INVOICE_START';
					$serverRequestData['data']['key']          = '27';
				}
			} else {
				if(empty($birthday))
				{	
					$responseData['status'] = '101';
					$this->paymentService->pushNotification('error', $this->paymentHelper->getTranslatedText('doberror'));
					return $this->response->redirectTo('checkout');
				}
				else if(time() < strtotime('+18 years', strtotime($birthday)))
				{
					$this->paymentService->pushNotification('error', $this->paymentHelper->getTranslatedText('dobinvalid'));
					return $this->response->redirectTo('checkout');
				}
			}
		}

		$response = $this->paymentHelper->executeCurl($serverRequestData['data'], $serverRequestData['url']);
		$responseData = $this->paymentHelper->convertStringToArray($response['response'], '&');
		$responseData['payment_id'] = (!empty($responseData['payment_id'])) ? $responseData['payment_id'] : $responseData['key'];
		$isPaymentSuccess = isset($responseData['status']) && $responseData['status'] == '100';
		$notificationType = $isPaymentSuccess ? 'success' : 'error';
		$this->paymentService->pushNotification($notificationType, $this->paymentHelper->getNovalnetStatusText($responseData));
		if($isPaymentSuccess)
		{			
			if(isset($serverRequestData['data']['pan_hash']))
			{
				unset($serverRequestData['data']['pan_hash']);
			}

			$this->sessionStorage->getPlugin()->setValue('nnPaymentData', array_merge($serverRequestData['data'], $responseData));

			// Redirect to the success page.
			return $this->response->redirectTo('place-order');
		} else {
			// Redirects to the checkout page.
			return $this->response->redirectTo('checkout');
		}
	}

	/**
	 * Process the redirect payment
	 *
	 */
	public function redirectPayment()
	{
		$paymentRequestData = $this->sessionStorage->getPlugin()->getValue('nnPaymentData');
		$orderNo = $this->sessionStorage->getPlugin()->getValue('nnOrderNo');
		$paymentRequestData['order_no'] = $orderNo;
		$paymentUrl = $this->sessionStorage->getPlugin()->getValue('nnPaymentUrl');

		return $this->twig->render('Novalnet::NovalnetPaymentRedirectForm', [
															   'formData'     => $paymentRequestData,
																'nnPaymentUrl' => $paymentUrl
								   ]);
	}
}
