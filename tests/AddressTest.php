<?php

namespace Tests;

use App\Models\User\Address;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use DatabaseTransactions;

    public function testList()
    {
        $response = $this->get('wx/address/list', $this->getAuthHeader());
        //dd($response->getOriginalContent());
        $response->dump();
    }


    public function testDelete()
    {
        $address = Address::query()->first();
        $this->assertNotEmpty($address->toArray());
        $response = $this->post('wx/address/delete',
            ['id' => $address->id],
            $this->getAuthHeader()
        );
        $response->assertJson(['errno' => 0]);
        $address = Address::query()->find($address->id);
        $this->assertEmpty($address);

    }
}
