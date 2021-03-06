<?php


namespace App\Services\Order;


use App\CodeResponse;
use App\Constant;
use App\Exceptions\BusinessException;
use App\Input\OrderGoodsSubmit;
use App\Input\PageInput;
use App\Jobs\OverTimeCancelOrder;
use App\Models\Cart\Cart;
use App\Models\Goods\GoodsProduct;
use App\Models\Order\Order;
use App\Models\Order\OrderGoods;
use App\Models\Order\OrderStatusTrait;
use App\Models\Promotion\Coupon;
use App\Notifications\NewPaidOrderEmailNotify;
use App\Services\BaseServices;
use App\Services\Goods\GoodsServices;
use App\Services\Promotion\CouponServices;
use App\Services\Promotion\GrouponServices;
use App\Services\SystemServices;
use App\Services\User\AddressServices;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

class OrderServices extends BaseServices
{
    use OrderStatusTrait;

    public function coverOrder(Order $order, $grouponOrders, $goodsList)
    {
        return [
            "id"              => $order->id,
            "orderSn"         => $order->order_sn,
            "actualPrice"     => $order->actual_price,
            "orderStatusText" => Constant::ORDER_STATUS_TEXT_MAP[$order->order_status] ?? '',
            "handleOption"    => $order->getCanHandleOptions(),
            "aftersaleStatus" => $order->aftersale_status,
            "isGroupin"       => in_array($order->id, $grouponOrders),
            "goodsList"       => $goodsList,
        ];
    }

    public function coverOrderGoods(OrderGoods $orderGoods)
    {
        return [
            "id"             => $orderGoods->id,
            "goodsName"      => $orderGoods->goods_name,
            "number"         => $orderGoods->number,
            "picUrl"         => $orderGoods->pic_url,
            "specifications" => $orderGoods->specifications,
            "price"          => $orderGoods->price
        ];
    }

    /**
     * @param $userId
     * @param  PageInput  $page
     * @param $status
     * @param  string[]  $column
     * @return LengthAwarePaginator
     * ????????????????????????
     */
    public function getOrderList($userId, PageInput $page, $status, $column = ['*'])
    {
        return Order::query()->where('user_id', $userId)
            ->when(!empty($status), function (Builder $builder) use ($status) {
                return $builder->whereIn('order_status', $status);
            })->orderBy($page->sort, $page->order)->paginate($page->limit, $column, 'page', $page->page);
    }

    /**
     * @param  array  $orderIds
     * @return OrderGoods[]|Builder[]|Collection|\Illuminate\Database\Query\Builder[]|\Illuminate\Support\Collection|\think\Collection
     * ????????????id???????????????????????????
     */
    public function getOrderGoodsListsByOrderIds(array $orderIds)
    {
        if (empty($orderIds)) {
            return collect([]);
        }
        return OrderGoods::query()->whereIn('order_id', $orderIds)->get();
    }

    /**
     * @param $userId
     * @param $orderId
     * @return array
     * @throws BusinessException
     * ????????????
     */
    public function detail($userId, $orderId)
    {
        $order = $this->getOrderByUserIdAndId($userId, $orderId);
        if (empty($order)) {
            $this->throwBusinessException(CodeResponse::ORDER_UNKNOWN);
        }

        $detail = Arr::only($order->toArray(), [
            "id",
            "orderSn",
            "message",
            "addTime",
            "consignee",
            "mobile",
            "address",
            "goodsPrice",
            "couponPrice",
            "freightPrice",
            'actualPrice',
            "aftersaleStatus"
        ]);

        $detail['orderStatusText'] = Constant::ORDER_STATUS_TEXT_MAP[$order->order_status];
        $detail['handleOption']    = $order->getCanHandleOptions();

        $goodsList         = $this->getOrderGoodList($orderId);
        $detail['expCode'] = $order->ship_channel;
        $detail['expNo']   = $order->ship_sn;
        $detail['expName'] = ExpressServices::getInstance()->getExpressName($order->ship_channel);
        $express           = []; //todo

        return [
            'orderInfo'   => $detail,
            'orderGoods'  => $goodsList,
            'expressInfo' => $express
        ];
    }

    /**
     * @param $userId
     * @param  OrderGoodsSubmit  $input
     * @return Order
     * @throws BusinessException
     */
    public function submit($userId, OrderGoodsSubmit $input)
    {
        // ??????????????????????????????
        if (!empty($input->grouponRulesId)) {
            GrouponServices::getInstance()->checkGrouponRulesValid($userId, $input->grouponRulesId);
        }
        // ??????????????????
        $address = AddressServices::getInstance()->getUserAddress($userId, $input->addressId);
        if (empty($address)) {
            $this->throwBadArgumentValue();
        }
        // ??????????????????????????????
        $checkedGoodList = CartServices::getInstance()->getCheckedGoodsList($userId, $input->cartId);
        // ????????????????????????????????????????????????????????????????????????????????????????????????
        $grouponPrice      = 0;
        $checkedGoodsPrice = CartServices::getInstance()->getCartPriceCutGroupon($checkedGoodList,
            $input->grouponRulesId, $grouponPrice);
        // ?????????????????????
        $couponPrice = 0;
        if ($input->couponId > 0) {
            /** @var Coupon $coupon */
            $coupon     = CouponServices::getInstance()->getCoupon($input->couponId);
            $couponUser = CouponServices::getInstance()->getCouponUser($input->userCouponId);
            $is         = CouponServices::getInstance()->checkCouponAndPrice($coupon, $couponUser, $checkedGoodsPrice);
            if ($is) {
                $couponPrice = $coupon->discount;
            }
        }
        // ??????
        $freightPrice = SystemServices::getInstance()->getFreightPrice($checkedGoodsPrice);
        // ??????????????????
        $orderTotalPrice = bcadd($checkedGoodsPrice, $freightPrice, 2);
        $orderTotalPrice = bcsub($orderTotalPrice, $couponPrice, 2);
        $orderTotalPrice = max(0, $orderTotalPrice);
        // ????????????
        $order                 = new Order();
        $order->user_id        = $userId;
        $order->order_sn       = $this->generateOrderSn();
        $order->order_status   = Constant::ORDER_STATUS_CREATE;
        $order->consignee      = $address->name;
        $order->address        = $address->province . $address->city . $address->county . " " . $address->address_detail;
        $order->message        = $input->message ?? " ";
        $order->goods_price    = $checkedGoodsPrice;
        $order->freight_price  = $freightPrice;
        $order->integral_price = 0;
        $order->mobile         = "";
        $order->coupon_price   = $couponPrice;
        $order->order_price    = $orderTotalPrice;
        $order->actual_price   = $orderTotalPrice;
        $order->groupon_price  = $grouponPrice;
        $order->save();
        // ????????????????????????????????????
        $this->saveOrderGoods($checkedGoodList, $order->id);
        // ???????????????????????????
        CartServices::getInstance()->clearCartGoods($userId, $input->cartId);
        // ?????????(??????????????????+??????????????????)
        $this->reduceProductsStock($checkedGoodList);
        // ????????????????????????
        // ??????????????????
        GrouponServices::getInstance()->saveGrouponData($input->grouponRulesId, $userId, $order->id,
            $input->grouponLinkId);
        // ??????????????????????????????????????????
        dispatch(new OverTimeCancelOrder($userId, $order->id));
        return $order;
    }

    /**
     * @param $userId
     * @param $orderId
     * @param $shipSn
     * @param $shipChannel
     * @return Order|Order[]|Builder|Builder[]|Collection|Model|null
     * @throws BusinessException
     * @throws Throwable
     * ????????????
     */
    public function ship($userId, $orderId, $shipSn, $shipChannel)
    {
        $order = $this->getOrderByUserIdAndId($userId, $orderId);

        if (empty($order)) {
            $this->throwBusinessException();
        }

        if (!$order->canShipHandle()) {
            $this->throwBusinessException(CodeResponse::ORDER_INVALID_OPERATION, '?????????????????????');
        }

        $order->order_status = Constant::ORDER_STATUS_SHIP;
        $order->ship_sn      = $shipSn;
        $order->ship_channel = $shipChannel;
        $order->ship_time    = now()->toDateTimeString();

        if ($order->cas() == 0) {
            $this->throwBusinessException(CodeResponse::UPDATED_FAIL);
        }
        //todo ??????????????????
        return $order;
    }

    /**
     * @param  Order  $order
     * @param $refundType
     * @param $refundContent
     * @return Order
     * @throws BusinessException
     * @throws Throwable
     * ?????????????????????
     */
    public function agreeRefund(Order $order, $refundType, $refundContent)
    {
        if (!$order->canAgreeRefundHandle()) {
            $this->throwBusinessException(CodeResponse::ORDER_INVALID_OPERATION, '???????????????????????????');
        }
        $now                   = now()->toDateTimeString();
        $order->order_status   = Constant::ORDER_STATUS_REFUND_CONFIRM;
        $order->end_time       = $now;
        $order->refund_amount  = $order->actual_price;
        $order->refund_type    = $refundType;
        $order->refund_content = $refundContent;
        $order->refund_time    = $now;

        if ($order->cas() == 0) {
            $this->throwBusinessException(CodeResponse::UPDATED_FAIL);
        }

        //????????????
        $this->addProductStock($order->id);
        return $order;
    }

    /**
     * @param $orderId
     * @return int
     * ??????????????????????????????
     */
    private function countOrderGoods($orderId)
    {
        return OrderGoods::whereOrderId($orderId)->count(['id']);
    }

    /**
     * @param $userId
     * @param $orderId
     * @param  false  $isAuto
     * @return Order|Order[]|Builder|Builder[]|Collection|Model|null
     * @throws BusinessException
     * @throws Throwable
     * ????????????
     */
    public function confirm($userId, $orderId, $isAuto = false)
    {
        $order = $this->getOrderByUserIdAndId($userId, $orderId);

        if (empty($order)) {
            $this->throwBusinessException();
        }

        if (!$order->canConfirmHandle()) {
            $this->throwBusinessException(CodeResponse::ORDER_INVALID_OPERATION, '??????????????????????????????');
        }

        $order->comments     = $this->countOrderGoods($orderId);
        $order->order_status = $isAuto ? Constant::ORDER_STATUS_AUTO_CONFIRM : Constant::ORDER_STATUS_CONFIRM;
        $order->confirm_time = now()->toDateTimeString();

        if ($order->cas() == 0) {
            $this->throwBusinessException(CodeResponse::UPDATED_FAIL);
        }

        return $order;
    }

    /**
     * @param $userId
     * @param $orderId
     * @return bool
     * @throws BusinessException
     */
    public function delete($userId, $orderId)
    {
        $order = $this->getOrderByUserIdAndId($userId, $orderId);

        if (empty($order)) {
            $this->throwBusinessException();
        }

        if (!$order->canDeleteHandle()) {
            $this->throwBusinessException(CodeResponse::ORDER_INVALID_OPERATION, '???????????????????????????');
        }

        $order->delete();

        //todo ???????????????????????????
        return true;
    }

    /**
     * @return Order[]|Builder[]|Collection
     * ??????????????????????????????
     */
    public function getTimeUnConfirmOrders()
    {
        $days = SystemServices::getInstance()->getUnConfirmOrderTime();
        return Order::query()->where('order_status', Constant::ORDER_STATUS_SHIP)
            ->where('ship_time', '<=', now()->subDays($days))
            ->where('ship_time', '>=', now()->subDays($days + 30))
            ->get();
    }

    /**
     * @throws BusinessException
     * @throws Throwable
     * ??????????????????
     */
    public function autoConfirm()
    {
        $orders = $this->getTimeUnConfirmOrders();
        foreach ($orders as $order) {
            $this->confirm($order->user_id, $order->id, true);
        }
    }

    /**
     * @param $userId
     * @param $orderId
     * @return Order|Order[]|Builder|Builder[]|Collection|Model|null
     * @throws BusinessException
     * @throws Throwable
     * ????????????
     */
    public function refund($userId, $orderId)
    {
        $order = $this->getOrderByUserIdAndId($userId, $orderId);

        if (empty($order)) {
            $this->throwBusinessException();
        }

        if (!$order->canRefundHandle()) {
            $this->throwBusinessException(CodeResponse::ORDER_INVALID_OPERATION, '??????????????????????????????');
        }

        $order->order_status = Constant::ORDER_STATUS_REFUND;

        if ($order->cas() == 0) {
            $this->throwBusinessException(CodeResponse::UPDATED_FAIL);
        }
        //todo ???????????????????????????????????????
        return $order;
    }

    /**
     * @param  Order  $order
     * @param $payId
     * @return Order
     * @throws BusinessException
     * @throws Throwable ???????????????????????????
     */
    public function payOrder(Order $order, $payId)
    {
        if (!$order->canPayHandle()) {
            $this->throwBusinessException(CodeResponse::ORDER_PAY_FAIL, '?????????????????????');
        }
        $order->pay_id       = $payId;
        $order->pay_time     = now()->toDateTimeString();
        $order->order_status = Constant::ORDER_STATUS_PAY;
        if ($order->cas() == 0) {
            $this->throwBusinessException(CodeResponse::UPDATED_FAIL);
        }

        //??????????????????
        GrouponServices::getInstance()->payGrouponOrder($order->id);

        //????????????????????????
        Notification::route('mail', env('MAIL_USERNAME'))->notify(new NewPaidOrderEmailNotify($order->id));

        //?????????????????????--??????????????????????????????
//        $code = random_int(100000, 999999);
//        $user = UserServices::getInstance()->getUserById($order->user_id);
//        $user->mobile = '18656275932';
//        $user->notify(new NewPaidOrderSmsNotify($code, 'SMS_117526525'));
        return $order;
    }

    /**
     * @param  Collection  $checkProductList
     * @throws BusinessException
     * ?????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????
     */
    public function reduceProductsStock(Collection $checkProductList)
    {
        $productIds = $checkProductList->pluck('product_id')->toArray();
        $products   = GoodsServices::getInstance()->getGoodsProductsByIds($productIds)->keyBy('id');
        foreach ($checkProductList as $cart) {
            /** @var GoodsProduct $product */
            $product = $products->get($cart->product_id);
            if (empty($product)) {
                $this->throwBusinessException();
            }
            if ($product->number < $cart->number) {
                $this->throwBusinessException(CodeResponse::GOODS_NO_STOCK);
            }
            $row = GoodsServices::getInstance()->reduceStock($product->id, $cart->number);
            if ($row == 0) {
                $this->throwBusinessException(CodeResponse::GOODS_NO_STOCK);
            }
        }
    }

    /**
     * @param $checkedGoodList
     * @param $orderId
     * ?????????????????????
     */
    public function saveOrderGoods($checkedGoodList, $orderId)
    {
        /** @var Cart $cart */
        foreach ($checkedGoodList as $cart) {
            $orderGoods                 = OrderGoods::new();
            $orderGoods->order_id       = $orderId;
            $orderGoods->goods_id       = $cart->goods_id;
            $orderGoods->goods_sn       = $cart->goods_sn;
            $orderGoods->product_id     = $cart->product_id;
            $orderGoods->goods_name     = $cart->goods_name;
            $orderGoods->pic_url        = $cart->pic_url;
            $orderGoods->price          = $cart->price;
            $orderGoods->number         = $cart->number;
            $orderGoods->specifications = $cart->specifications;
            $orderGoods->save();
        }
    }

    /**
     * @param $userId
     * @param $orderId
     * @return mixed
     * @throws Throwable
     * ??????????????????
     */
    public function userCancel($userId, $orderId)
    {
        DB::transaction(function () use ($userId, $orderId) {
            $this->cancel($userId, $orderId, 'user');
        });
        return true;
    }

    /**
     * @param $userId
     * @param $orderId
     * @return bool
     * @throws Throwable
     * ?????????????????????
     */
    public function adminCancel($userId, $orderId)
    {
        DB::transaction(function () use ($userId, $orderId) {
            $this->cancel($userId, $orderId, 'admin');
        });
        return true;
    }

    /**
     * @param $userId
     * @param $orderId
     * @return bool
     * @throws Throwable
     * ??????????????????
     */
    public function systemCancel($userId, $orderId)
    {
        DB::transaction(function () use ($userId, $orderId) {
            $this->cancel($userId, $orderId, 'system');
        });
        return true;
    }

    /**
     * @param $userId
     * @param $orderId
     * @param  string  $role  ?????? user / admin / system
     * @return bool
     * @throws BusinessException
     * ????????????
     */
    private function cancel($userId, $orderId, $role = 'user')
    {
        $order = $this->getOrderByUserIdAndId($userId, $orderId);

        if (is_null($orderId)) {
            $this->throwBusinessException();
        }

        if (!$order->canCancelHandle()) {
            $this->throwBusinessException(CodeResponse::ORDER_INVALID_OPERATION, '??????????????????');
        }

        switch ($role) {
            case 'system':
                $order->order_status = Constant::ORDER_STATUS_AUTO_CANCEL;
                break;
            case 'admin':
                $order->order_status = Constant::ORDER_STATUS_ADMIN_CANCEL;
                break;
            default:
                $order->order_status = Constant::ORDER_STATUS_CANCEL;
        }

        if ($order->cas() === 0) {
            $this->throwBusinessException(CodeResponse::UPDATED_FAIL);
        }

        $this->addProductStock($orderId);

        return true;
    }

    /**
     * @param $orderId
     * @throws BusinessException
     * ?????????????????????
     */
    public function addProductStock($orderId)
    {
        $orderGoods = $this->getOrderGoodList($orderId);
        /** @var OrderGoods $orderGood */
        foreach ($orderGoods as $orderGood) {
            $row = GoodsServices::getInstance()->addStock($orderGood->product_id, $orderGood->number);
            if ($row == 0) {
                $this->throwBusinessException(CodeResponse::UPDATED_FAIL);
            }
        }
    }

    /**
     * @param $orderId
     * @param  string[]  $column
     * @return OrderGoods[]|Builder[]|Collection
     * ???????????????????????????
     */
    public function getOrderGoodList($orderId, $column = ['*'])
    {
        return OrderGoods::query()->whereOrderId($orderId)->get($column);
    }


    /**
     * @param $userId
     * @param $orderId
     * @return Order|Order[]|Builder|Builder[]|Collection|Model|null
     * ?????????????????????
     */
    public function getOrderByUserIdAndId($userId, $orderId)
    {
        return Order::query()->where('user_id', $userId)->find($orderId);
    }

    /**
     * @param $userId
     * @param $orderId
     * @param $status
     * @return bool|int
     * ?????????????????????
     */
    public function updateOrderStatus($userId, $orderId, $status)
    {
        return Order::query()->where('user_id', $userId)->where('id', $orderId)->update(['order_status' => $status]);
    }

    /**
     * @return mixed
     * @throws BusinessException
     * ??????????????????
     */
    public function generateOrderSn()
    {
        return retry(5, function () {
            $date    = date('YmdHis');
            $orderSn = $date . Str::random(6);
            if ($this->checkOrderSnValid($orderSn)) {
                Log::warning("????????????????????????" . $orderSn);
                $this->throwBusinessException(CodeResponse::FAIL, '?????????????????????');
            }
            return $orderSn;
        });
    }

    /**
     * @param $userId
     * @param $orderId
     * @return array
     * @throws BusinessException
     * ?????????????????????????????????
     */
    public function getPayWxOrder($userId, $orderId)
    {
        $order = $this->getPayOrderInfo($userId, $orderId);
        return $order = [
            'out_trade_no' => $order->order_sn,
            'body'         => '?????????' . $order->order_sn,
            'total_fee'    => bcmul($order->actual_price, 100, 2),
        ];
    }

    /**
     * @param $userId
     * @param $orderId
     * @return Order|Order[]|Builder|Builder[]|Collection|Model|null
     * @throws BusinessException
     * ????????????????????????
     */
    public function getPayOrderInfo($userId, $orderId)
    {
        $order = $this->getOrderByUserIdAndId($userId, $orderId);
        if (empty($order)) {
            $this->throwBusinessException(CodeResponse::ORDER_UNKNOWN);
        }
        return $order;
    }

    /**
     * @param $userId
     * @param $orderId
     * @return array
     * @throws BusinessException
     * ?????????????????????????????????
     */
    public function getAlipayPayOrder($userId, $orderId)
    {
        $order = $this->getPayOrderInfo($userId, $orderId);
        return [
            'out_trade_no' => $order->order_sn,
            'total_amount' => $order->actual_price,
            'subject'      => 'test subject - ??????'
        ];
    }

    /**
     * @param $data
     * @return Order|Builder|Model|object|null
     * @throws BusinessException
     * @throws Throwable
     * ??????????????????
     */
    public function wxNotify($data)
    {
        //?????????????????????????????????????????????
        Log::debug('WxNotify data:' . var_export_inline($data));
        $orderSn = $data['out_trade_no'] ?? '';
        $payId   = $data['transaction_id'] ?? '';
        $price   = bcdiv($data['total_price'], 100, 2);
        return $this->notify($price, $orderSn, $payId);
    }

    /**
     * @param $price
     * @param $orderSn
     * @param $payId
     * @return Order|Builder|Model|object|null
     * @throws BusinessException
     * @throws Throwable
     * ??????????????????????????????????????????
     */
    public function notify($price, $orderSn, $payId)
    {
        $order = $this->getOrderByOrderSn($orderSn);
        if (is_null($order)) {
            $this->throwBusinessException(CodeResponse::ORDER_UNKNOWN);
        }
        if ($order->isHadPaid()) {
            return $order;
        }

        if (bccomp($order->actual_price, $price, 2) != 0) {
            Log::error("?????????????????????{$order->id}???????????????????????????????????????????????????{$price}??????????????????{$order->actual_price}");
            $this->throwBusinessException(CodeResponse::FAIL, '?????????????????????????????????');
        }
        return $this->payOrder($order, $payId);
    }

    /**
     * @param $data
     * @return Order|Builder|Model|object|null
     * @throws BusinessException
     * @throws Throwable
     * ?????????????????????
     */
    public function alipayNotify($data)
    {
        if (!in_array(($data['trade_status'] ?? ''), ['TRADE_SUCCESS', 'TRADE_FINISHED'])) {
            $this->throwBusinessException();
        }
        $orderSn = $data['out_trade_no'] ?? '';
        $payId   = $data['transaction_id'] ?? '';
        $price   = $data['total_amount'] ?? 0;
        return $this->notify($price, $orderSn, $payId);
    }

    /**
     * @param $orderSn
     * @return Order|Builder|Model|object|null
     * ????????????????????????????????????
     */
    public function getOrderByOrderSn($orderSn)
    {
        return Order::query()->whereOrderSn($orderSn)->first();
    }

    /**
     * @param $orderSn
     * @return bool
     * ???????????????????????????
     */
    private function checkOrderSnValid($orderSn)
    {
        return Order::query()->where('order_sn', $orderSn)->exists();
    }

    /**
     * @param $userId
     * @return Order[]|Builder[]|Collection
     * ???????????????????????????
     */
    public function getOrdersByUserId($userId)
    {
        return Order::query()->whereUserId($userId)->get();
    }


}
