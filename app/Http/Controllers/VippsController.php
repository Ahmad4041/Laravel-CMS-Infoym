<?php


/**
 * File name: VippsContoller.php
 * Last modified: 2020.06.11 at 16:03:23
 * Author: Ahmad Naeem
 * Copyright (c) 2020
 */

namespace App\Http\Controllers;

use App\Models\DeliveryAddress;
use App\Models\Payment;
use App\Models\User;
use App\Repositories\DeliveryAddressRepository;
use Illuminate\Http\Request;
use Flash;
use Illuminate\Support\Facades\Log;
// use Razorpay\Api\Api;
use Vipps\Vipps;
use Vipps\Config;
use Vipps\Ecommerce\Payment as VippsPayment;
use Vipps\Ecommerce\PaymentDetails;

class VippsController extends ParentOrderController
{

    /**
     * @var Api
     */
    private $api;
    private $currency;
    private $endpoint;
    /** @var DeliveryAddressRepository
     *
     */
    private $deliveryAddressRepo;

    public function __init()
    {
        $this->currency = setting('default_currency_code', 'INR');


        $this->deliveryAddressRepo = new DeliveryAddressRepository(app());
    }


    public function index()
    {
        return view('welcome');
    }


    public function checkout(Request $request)
    {

        try {
            $user = $this->userRepository->findByField('api_token', $request->get('api_token'))->first();
            $coupon = $this->couponRepository->findByField('code', $request->get('coupon_code'))->first();
            $deliveryId = $request->get('delivery_address_id');
            $deliveryAddress = $this->deliveryAddressRepo->findWithoutFail($deliveryId);
            if (!empty($user)) {
                $live_mode_enable = setting('enable_live_mode_vipps', 0);
                $endpoint = 'https://apitest.vipps.no';
                if ($live_mode_enable == 1) {
                    $endpoint = 'https://api.vipps.no';
                }

                // Log::info('message'. $endpoint);
                // dd(url('/payments/failed'));

                $this->order->user = $user;
                $this->order->user_id = $user->id;
                $this->order->delivery_address_id = $deliveryId;
                $this->coupon = $coupon;
                $VippsPayCart = $this->getOrderData();
                // $VippsPayOrder = $this->api->order->create($VippsPayCart);
                // dd(url('/payments/vipps/pay-success/' . $this->order->user_id . '/' . $this->order->delivery_address_id . '/' .  $this->coupon . ''));
                Vipps::setConfig(Config::create([
                    'endpoint' => $endpoint,
                    'clientId' => setting('vipps_key'),
                    'clientSecret' =>  setting('vipps_secret'),
                    'merchantSerialNumber' =>  setting('vipps_merchant'),
                    'accessTokenSubscriptionKey' =>  setting('vipps_subscription_key_primary'),
                    'ecommerceSubscriptionKey' =>  setting('vipps_subscription_key_secondary'),
                    'callbackPrefix' => url('/payments/vipps/pay-success/' . $this->order->user_id . '/' . $this->order->delivery_address_id . '/' .  $this->coupon . ''),
                    'fallBack' => url('/payments/vipps/failed/' . $this->order->user_id . '/' . $this->order->delivery_address_id . '/' .  $this->coupon . ''),
                    'isApp' => true
                ]));
                $payment = VippsPayment::create([

                    'transaction' => [
                        'amount' => (float) $VippsPayCart['amount'], //1337.00 NOK
                        'transactionText' => 'Order Delivery' . $deliveryId
                    ]
                ]);
                $response = $payment->charge();
                // dd($response['url']);
                header('Location: ' . $response->url);
                die();
                // $razorPayCart = $this->getOrderData();

                // $razorPayOrder = $this->api->order->create($razorPayCart);
                // $fields = $this->getRazorPayFields($razorPayOrder, $user, $deliveryAddress);
                // //url-ify the data for the POST
                // $fields_string = http_build_query($fields);

                // //open connection
                // $ch = curl_init();

                // //set the url, number of POST vars, POST data
                // curl_setopt($ch, CURLOPT_URL, 'https://api.razorpay.com/v1/checkout/embedded');
                // curl_setopt($ch, CURLOPT_POST, 1);
                // curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
                // $result = curl_exec($ch);
                // if($result === true){
                //     die();
                // }
            } else {
                Flash::error("Error processing Vipps user not found");
                return redirect(route('payments.failed'));
            }
        } catch (\Exception $e) {
            Flash::error("Error processing Vipps payment for your order :" . $e->getMessage() . "at line " . $e->getLine() . "");
            return redirect(route('payments.failed'));
        }
    }

    public function checkstatus(int $userId, int $deliveryAddressId)
    {
        $live_mode_enable = setting('enable_live_mode_vipps', 0);
        $endpoint = 'https://apitest.vipps.no';
        if ($live_mode_enable == 1) {
            $endpoint = 'https://api.vipps.no';
        }
        $api = Vipps::setConfig(Config::create([
            'endpoint' => $endpoint,
            'clientId' => setting('vipps_key'),
            'clientSecret' =>  setting('vipps_secret'),
            'merchantSerialNumber' =>  setting('vipps_merchant'),
            'accessTokenSubscriptionKey' =>  setting('vipps_subscription_key_primary'),
            'ecommerceSubscriptionKey' =>  setting('vipps_subscription_key_secondary'),
            'isApp' => true
        ]));
        $status = PaymentDetails::create(
            [
                'orderId' => $deliveryAddressId
            ]
        );
        // $status.retrieve($deliveryAddressId);
        dd($status->retrieve($deliveryAddressId)); //
    }
    /**
     * @param int $userId
     * @param int $deliveryAddressId
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function paySuccess(int $userId, int $deliveryAddressId, string $couponCode, Request $request)
    {
        $data = $request->all();

        $description = $this->getPaymentDescription($data);

        $this->order->user_id = $userId;
        $this->order->user = $this->userRepository->findWithoutFail($userId);
        $this->coupon = $this->couponRepository->findByField('code', $couponCode)->first();
        $this->order->delivery_address_id = $deliveryAddressId;


        if ($request->hasAny(['razorpay_payment_id', 'razorpay_signature'])) {

            $this->order->payment = new Payment();
            $this->order->payment->status = trans('lang.order_paid');
            $this->order->payment->method = 'Vipps';
            $this->order->payment->description = $description;

            $this->createOrder();

            return redirect(url('payments/vipps'));
        } else {
            Flash::error("Error processing Vipps payment for your order");
            return redirect(route('payments.failed'));
        }
    }

    /**
     * Set cart data for processing payment on PayPal.
     *
     *
     * @return array
     */
    private function getOrderData()
    {
        $data = [];
        $this->calculateTotal();
        $amountINR = $this->total;
        if ($this->currency !== 'INR') {
            $url = "https://api.exchangeratesapi.io/latest?symbols=NOK&base=$this->currency";
            $exchange = json_decode(file_get_contents($url), true);
            $amountINR =  $this->total * $exchange['rates']['NOK'];
        }
        $order_id = $this->paymentRepository->all()->count() + 1;
        $data['amount'] = (float)($amountINR);
        $data['payment_capture'] = 1;
        $data['currency'] = 'NOK';
        $data['receipt'] = $order_id . '_' . date("Y_m_d_h_i_sa");

        return $data;
    }

    /**
     * @param $razorPayOrder
     * @param User $user
     * @param DeliveryAddress $deliveryAddress
     * @return array
     */
    private function getRazorPayFields($razorPayOrder, User $user, DeliveryAddress $deliveryAddress): array
    {
        $restaurant = $this->order->user->cart[0]->food->restaurant;

        $fields = array(
            'key_id' => config('services.razorpay.key', ''),
            'order_id' => $razorPayOrder['id'],
            'name' => $restaurant->name,
            'description' => count($this->order->user->cart) . " items",
            'image' => $this->order->user->cart[0]->food->restaurant->getFirstMedia('image')->getUrl('thumb'),
            'prefill' => [
                'name' => $user->name,
                'email' => $user->email,
                'contact' => $user->custom_fields['phone']['value'],
            ],
            'callback_url' => url('payments/razorpay/pay-success', ['user_id' => $user->id, 'delivery_address_id' => $deliveryAddress->id]),

        );

        if (isset($this->coupon)) {
            $fields['callback_url'] = url('payments/razorpay/pay-success', ['user_id' => $user->id, 'delivery_address_id' => $deliveryAddress->id, 'coupon_code' => $this->coupon->code]);
        }

        if (!empty($deliveryAddress)) {
            $fields['notes'] = [
                'delivery_address' => $deliveryAddress->address,
            ];
        }


        if ($this->currency !== 'INR') {
            $fields['display_amount'] = $this->total;
            $fields['display_currency'] = $this->currency;
        }
        return $fields;
    }

    /**
     * @param array $data
     * @return string
     */
    private function getPaymentDescription(array $data): string
    {
        $description = "Id: " . $data['razorpay_payment_id'] . "</br>";
        $description .= trans('lang.order') . ": " . $data['razorpay_order_id'];
        return $description;
    }
}
