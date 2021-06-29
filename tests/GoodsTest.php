<?php


use Tests\TestCase;
use \Illuminate\Foundation\Testing\DatabaseTransactions;

class GoodsTest extends TestCase
{
    use DatabaseTransactions;

    public function testCount()
    {
        $this->assertLitemallApiGet('wx/goods/count');
    }

    public function testCategory()
    {
        $this->assertLitemallApiGet('wx/goods/category?id=1008009');
        $this->assertLitemallApiGet('wx/goods/category?id=1005000');
    }

    public function testList()
    {
        $this->assertLitemallApiGet('wx/goods/list');
        $this->assertLitemallApiGet('wx/goods/list?categoryId=1008009');
        $this->assertLitemallApiGet('wx/goods/list?brandId=1001000');
        $this->assertLitemallApiGet('wx/goods/list?keyword=四件套');
        $this->assertLitemallApiGet('wx/goods/list?isNew=1');
        $this->assertLitemallApiGet('wx/goods/list?isHot=1');
        $this->assertLitemallApiGet('wx/goods/list?page=2&limit=5');
    }

    public function testDetail()
    {
        $this->assertLitemallApiGet('wx/goods/detail?id=1009009');
        $this->assertLitemallApiGet('wx/goods/detail?id=1181000');
    }
}
