<?php
/**
 * FecShop file.
 *
 * @link http://www.fecshop.com/
 * @copyright Copyright (c) 2016 FecShop Software LLC
 * @license http://www.fecshop.com/license/
 */

namespace fecshop\app\appserver\modules\Payment\block\paypal\express;

use Yii;

/**
 * @author Terry Zhao <2358269014@qq.com>
 * @since 1.0
 */
class Placeorder
{
    /**
     * 用户的账单地址信息，通过用户传递的信息计算而来。
     */
    public $_billing;

    public $_address_id;
    /**
     * 用户的货运方式.
     */
    public $_shipping_method;
    /**
     * 用户的支付方式.
     */
    public $_payment_method;

    public function getLastData()
    {
        $post = Yii::$app->request->post();
        $token = Yii::$app->request->post('token');
        if(!$token){
            $code = Yii::$service->helper->appserver->order_paypal_express_get_token_fail;
            $data = [];
            $reponseData = Yii::$service->helper->appserver->getReponseData($code, $data);
            
            return $reponseData;
        }
        if (is_array($post) && !empty($post)) {
            $post = \Yii::$service->helper->htmlEncode($post);
            // 设置paypal快捷支付
            $post['payment_method'] = Yii::$service->payment->paypal->express_payment_method;
            // 检查前台传递的数据的完整性
            // 检查前台传递的数据的完整
            $checkInfo = $this->checkOrderInfoAndInit($post);
            if ($checkInfo !== true) {
                return $checkInfo;
            }
            
            // 如果游客用户勾选了注册账号，则注册，登录，并把地址写入到用户的address中
            $save_address_status = $this->updateAddress($post);
           
            // 更新Cart信息
            //$this->updateCart();
            // 设置checkout type
            $serviceOrder = Yii::$service->order;
            $checkout_type = $serviceOrder::CHECKOUT_TYPE_EXPRESS;
            $serviceOrder->setCheckoutType($checkout_type);
            // 将购物车数据，生成订单,生成订单后，不清空购物车，不扣除库存，在支付成功后在清空购物车。
            $innerTransaction = Yii::$app->db->beginTransaction();
            try {
                $genarateStatus = Yii::$service->order->generateOrderByCart($this->_billing, $this->_shipping_method, $this->_payment_method, false,$token);
                if ($genarateStatus) {
                    $innerTransaction->commit();
                } else {
                    $innerTransaction->rollBack();
                }
            } catch (Exception $e) {
                $innerTransaction->rollBack();
            }
            //echo 22;
            if ($genarateStatus) {
                // 得到当前的订单信息
                $doExpressCheckoutReturn = $this->doExpressCheckoutPayment($token);
                //echo $doExpressCheckoutReturn;exit;
                //echo 333;
                if ($doExpressCheckoutReturn) {
                    $increment_id = Yii::$service->order->getSessionIncrementId();
                    $innerTransaction = Yii::$app->db->beginTransaction();
                    try {
                        // 插件这个订单是否被支付过，如果被支付过，则回滚
                        if(!Yii::$service->order->checkOrderVersion($increment_id)){    
                            $innerTransaction->rollBack();
                            
                            $code = Yii::$service->helper->appserver->order_has_been_paid;
                            $data = [
                                'error' => 'the order has been paid',
                            ];
                            $reponseData = Yii::$service->helper->appserver->getReponseData($code, $data);
                            
                            return $reponseData;
                        }
                        $ExpressOrderPayment = Yii::$service->payment->paypal->updateExpressOrderPayment($doExpressCheckoutReturn,$token);
                        // 如果支付成功，并把信息更新到了订单数据中，则进行下面的操作。
                        //echo 444;
                        if ($ExpressOrderPayment) {
                            // 查看订单是否被多次支付，如果被多次支付，则回滚
                            
                            // 支付成功后，在清空购物车数据。而不是在生成订单的时候。
                            Yii::$service->cart->clearCartProductAndCoupon();
                            // (删除)支付成功后，扣除库存。
                            // (删除)Yii::$service->product->stock->deduct();
                            // echo 555;
                            // 发送新订单邮件

                            // 扣除库存和优惠券
                            // 在生成订单的时候已经扣除了。参看order service GenerateOrderByCart() function

                            // 得到支付跳转前的准备页面。
                            //$paypal_express = Yii::$service->payment->paypal->express_payment_method;
                            //$successRedirectUrl = Yii::$service->payment->getExpressSuccessRedirectUrl($paypal_express);
                            //Yii::$service->url->redirect($successRedirectUrl);
                            $innerTransaction->commit();
                            
                            $code = Yii::$service->helper->appserver->status_success;
                            $data = [];
                            $reponseData = Yii::$service->helper->appserver->getReponseData($code, $data);
                            
                            return $reponseData;
                        }else{
                            
                            $innerTransaction->rollBack();
                            $code = Yii::$service->helper->appserver->order_paypal_express_payment_fail;
                            $data = [];
                            $reponseData = Yii::$service->helper->appserver->getReponseData($code, $data);
                            
                            return $reponseData;
                        }
                    } catch (Exception $e) {
                        $innerTransaction->rollBack();
                    }
                }
                // 如果订单支付过程中失败，将订单取消掉
                /* 2017-09-12修改，认为没有必要取消订单，如果取消掉，在支付页面就无法继续下单，因此注释掉下面的代码
                if (!$doExpressCheckoutReturn || !$ExpressOrderPayment) {
                    $innerTransaction = Yii::$app->db->beginTransaction();
                    try {
                        if(Yii::$service->order->cancel()){
                            $innerTransaction->commit();
                        }else{
                            $innerTransaction->rollBack();
                        }
                    } catch (Exception $e) {
                        $innerTransaction->rollBack();
                    }
                }
                */
                //return true;
            }else{
                $code = Yii::$service->helper->appserver->order_generate_fail;
                $data = [];
                $reponseData = Yii::$service->helper->appserver->getReponseData($code, $data);
                
                return $reponseData;
            }
            
        }
        //echo 'eeeeeeee';exit;
        Yii::$service->page->message->addByHelperErrors();

        return [];
    }
    /**
     * @property $token | String 
     * 通过paypal的api接口，进行支付下单
     */
    public function doExpressCheckoutPayment($token)
    {
        $methodName_ = 'DoExpressCheckoutPayment';
        $nvpStr_ = Yii::$service->payment->paypal->getExpressCheckoutPaymentNvpStr($token);
        //echo $nvpStr_;exit;
        $DoExpressCheckoutReturn = Yii::$service->payment->paypal->PPHttpPost5($methodName_, $nvpStr_);
        //var_dump($DoExpressCheckoutReturn);
        //exit;
        if (strstr(strtolower($DoExpressCheckoutReturn['ACK']), 'success')) {
            return $DoExpressCheckoutReturn;
        } else {
            if ($DoExpressCheckoutReturn['ACK'] == 'Failure') {
                $message = $DoExpressCheckoutReturn['L_LONGMESSAGE0'];
                // 添加报错信息。
                //Message::error($message);
                Yii::$service->helper->errors->add($message);
            } else {
                Yii::$service->helper->errors->add('paypal express payment error.');
            }

            return false;
        }
    }

    /**
     * @property $post | Array
     * 登录用户，保存货运地址到customer address ，然后把生成的
     * address_id 写入到cart中。
     * shipping method写入到cart中
     * payment method 写入到cart中 updateCart
     */
    public function updateAddress($post)
    {
        return Yii::$service->cart->updateGuestCart($this->_billing, $this->_shipping_method, $this->_payment_method);
    }

    /**
     * 如果是游客，那么保存货运地址到购物车表。
     */
    /*
    public function updateCart(){
        if(Yii::$app->user->isGuest){
            return Yii::$service->cart->updateGuestCart($this->_billing,$this->_shipping_method,$this->_payment_method);
        }else{
            return Yii::$service->cart->updateLoginCart($this->_address_id,$this->_shipping_method,$this->_payment_method);
        }
    }
    */

    /**
     * @property $post | Array
     * @return bool
     *              检查前台传递的信息是否正确。同时初始化一部分类变量
     */
    public function checkOrderInfoAndInit($post)
    {
        $address_one = '';
        $billing = isset($post['billing']) ? $post['billing'] : '';
        if (!Yii::$service->order->checkRequiredAddressAttr($billing)) {
            
            $code = Yii::$service->helper->appserver->order_generate_request_post_param_invaild;
            $data = [
                'error' => Yii::$service->helper->errors->get(','),
            ];
            $reponseData = Yii::$service->helper->appserver->getReponseData($code, $data);
            
            return $reponseData;
            
            
        }
        $this->_billing = $billing;

        $shipping_method = isset($post['shipping_method']) ? $post['shipping_method'] : '';
        $payment_method = isset($post['payment_method']) ? $post['payment_method'] : '';
        // 验证货运方式
        if (!$shipping_method) {
            $code = Yii::$service->helper->appserver->order_generate_request_post_param_invaild;
            $data = [
                'error' => 'shipping method can not empty',
            ];
            $reponseData = Yii::$service->helper->appserver->getReponseData($code, $data);
            
            return $reponseData;
        } else {
            if (!Yii::$service->shipping->ifIsCorrect($shipping_method)) {
                $code = Yii::$service->helper->appserver->order_generate_request_post_param_invaild;
                $data = [
                    'error' => 'shipping method is not correct',
                ];
                $reponseData = Yii::$service->helper->appserver->getReponseData($code, $data);
                
                return $reponseData;
            }
            
        }

        $this->_shipping_method = $shipping_method;
        $this->_payment_method = $payment_method;
        Yii::$service->payment->setPaymentMethod($this->_payment_method);

        return true;
    }
}