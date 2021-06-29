<?php


use Tests\TestCase;
use \Illuminate\Foundation\Testing\DatabaseTransactions;

class BrandTest extends TestCase
{
    use DatabaseTransactions;

    public function testDetail()
    {
        $this->assertLitemallApiGet('wx/brand/detail');
        $this->assertLitemallApiGet('wx/brand/detail?id=1024000');
        $this->assertLitemallApiGet('wx/brand/detail?id=10240001');
    }

    public function testList()
    {
        $this->assertLitemallApiGet('wx/brand/list');
    }
}
