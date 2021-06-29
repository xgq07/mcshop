<?php
use Tests\TestCase;
use \Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Exceptions\BusinessException;
use App\CodeResponse;
use App\Services\Promotion\CouponServices;
class CouponTest extends TestCase
{
    use DatabaseTransactions;


    public function testList()
    {
        $this->assertLitemallApiGet('wx/coupon/list');
    }

    public function testMyList()
    {
//        $this->assertLitemallApiGet('wx/coupon/mylist');
        $this->assertLitemallApiGet('wx/coupon/mylist?status=0');
        $this->assertLitemallApiGet('wx/coupon/mylist?status=1');
        $this->assertLitemallApiGet('wx/coupon/mylist?status=2');
    }


    public function testReceive()
    {
        $this->expectExceptionObject(new BusinessException(CodeResponse::COUPON_EXCEED_LIMIT));
        CouponServices::getInstance()->receive(1,1);

    }
}
