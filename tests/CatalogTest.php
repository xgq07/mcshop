<?php


use Tests\TestCase;
use \Illuminate\Foundation\Testing\DatabaseTransactions;

class CatalogTest extends TestCase
{
    use DatabaseTransactions;

    public function testIndex()
    {
        $this->assertLitemallApiGet('wx/catalog/index');
        $this->assertLitemallApiGet('wx/catalog/index?id=1005000');
        $this->assertLitemallApiGet('wx/catalog/index?id=10050001');
    }

    public function testCurrent()
    {
        $this->assertLitemallApiGet('wx/catalog/current');
        $this->assertLitemallApiGet('wx/catalog/current?id=1005000');
        $this->assertLitemallApiGet('wx/catalog/current?id=10050001');
    }
}
