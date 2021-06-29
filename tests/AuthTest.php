<?php

namespace Tests;

use App\CodeResponse;
use App\Exceptions\BusinessException;
use http\Exception\BadMessageException;
use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Services\User\UserServices;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;


class AuthTest extends TestCase
{
    use DatabaseTransactions;

    public function testRegister()
    {
        $phone = '131111112';
        $code = UserServices::getInstance()->setCaptcha($phone);
        $response = $this->post('wx/auth/register', [
            'username' => 'qiu2',
            'password' => '123456',
            'mobile' => $phone,
            'code' => $code,
        ]);

        $response->assertStatus(200);
        $ret = $response->getOriginalContent();
        $this->assertEquals(0, $ret['errno']);
        $this->assertNotEmpty($ret['data']);

    }

    public function testRegisterMobile()
    {
        $response = $this->post('wx/auth/register', [
            'username' => 'tanfan2',
            'password' => '123456',
            'mobile' => '131292228678',
            'code' => '1234'
        ]);
        $response->assertStatus(200);
        $ret = $response->getOriginalContent();
        $this->assertEquals(CodeResponse::AUTH_INVALID_MOBILE, $ret['errno']);
    }


    //SMS
    public function testRegCaptcha()
    {
        $response = $this->post('wx/auth/regCaptcha', ['mobile' => '13129222861']);
        $response->assertJson(['errno' => 0, 'errmsg' => '成功']);

    }

    /*
     * 检查验证码每天发送的次数 10次
     */
    public function testcheckMobileSendCaptchaCount()
    {
        $mobile = '13129222867';
        $send_count = 10;
        foreach (range(0, 9) as $i) {
            $isPass = UserServices::getInstance()->checkMobileSendCaptchaCount($mobile, $send_count);
            $this->assertTrue($isPass);
        }
        $isPass = UserServices::getInstance()->checkMobileSendCaptchaCount($mobile, $send_count);
        $this->assertFalse($isPass);

        $countKey = 'register_captcha_count_' . $mobile;
        Cache::forget($countKey);
        $isPass = UserServices::getInstance()->checkMobileSendCaptchaCount($mobile, $send_count);
        $this->assertTrue($isPass);
    }

    //测试获取验证码
    public function testCheckCaptcha()
    {
        $mobile = '131292222867';
        $code = UserServices::getInstance()->setCaptcha($mobile);
        $isPass = UserServices::getInstance()->checkCaptcha($mobile, $code);
        $this->assertTrue($isPass);
        //第二次就会失败
        $this->expectExceptionObject(new BusinessException(
            CodeResponse::AUTH_CAPTCHA_UNMATCH
        ));
        $isPass = UserServices::getInstance()->checkCaptcha($mobile, $code);
    }


    public function testLogin()
    {
        $response = $this->post('wx/auth/login', ['username' => 'qiu', 'password' => '123456']);
        $response->assertJson([
            "errno" => 0,
            "data" => [
                "userInfo" => [
                    "nickName" => "qiu",
                    "avatarUrl" => "https://yanxuan.nosdn.127.net/80841d741d7fa3073e0ae27bf487339f.jpg?imageView&quality=90&thumbnail=64x64"
                ]
            ],
            "errmsg" => "成功",
        ]);
        echo $response->getOriginalContent()['data']['token'] ?? '';
        $this->assertNotEmpty($response->getOriginalContent()['data']['token'] ?? '');
    }

    public function testUser()
    {
        $response = $this->post('wx/auth/login', ['username' => 'qiu', 'password' => '123456']);
        $token = $response->getOriginalContent()['data']['token'] ?? '';
        $response2 = $this->get('wx/auth/user', ['Authorization' => "Bearer {$token}"]);
        $response2->assertJson(['username' => 'qiu']);
        echo $response2->getOriginalContent();
    }


    public function testInfo()
    {
        $response = $this->post('wx/auth/login', ['username' => 'qiu', 'password' => '123456']);
        $token = $response->getOriginalContent()['data']['token'] ?? '';
        $response2 = $this->get('wx/auth/info', ['Authorization' => "Bearer {$token}"]);
        $user = UserServices::getInstance()->getByUsername('qiu');
        $response2->assertJson([
            'data' => [
                'nickName' => $user->nickname,
                'avatar' => $user->avatar,
            ]
        ]);
    }


    public function testLogOut()
    {
        $response = $this->post('wx/auth/login', ['username' => 'qiu', 'password' => '123456']);
        $token = $response->getOriginalContent()['data']['token'] ?? '';
        $response2 = $this->get('wx/auth/info', ['Authorization' => "Bearer {$token}"]);
        $user = UserServices::getInstance()->getByUsername('qiu');
        $response2->assertJson([
            'data' => [
                'nickName' => $user->nickname,
                'avatar' => $user->avatar,
            ]
        ]);
        $response3 = $this->post('wx/auth/logout', [], ['Authorization' => "Bearer {$token}"]);
        $response3->assertJson(['errno' => 0]);
        $response4 = $this->get('wx/auth/info', ['Authorization' => "Bearer {$token}"]);
        $response4->assertJson(['errno' => 501]);

    }


    public function testReset()
    {
        $mobile = '13129222867';
        $code = UserServices::getInstance()->setCaptcha($mobile);
        $response = $this->post('wx/auth/reset',
            [
                'mobile' => $mobile,
                'password' => '456789a',
                'code' => $code,
            ]);
        $response->assertJson(['errno' => 0]);
        $user = UserServices::getInstance()->getByMobile($mobile);
        $isPass = Hash::check('456789a', $user->password);
        $this->assertTrue($isPass);
    }


    public function testProfile()
    {
        $response = $this->post('wx/auth/login', ['username' => 'qiu', 'password' => '123456']);
        $token = $response->getOriginalContent()['data']['token'] ?? '';
        $response2 = $this->post('wx/auth/profile',
            ['avatar' => '',
             'gender' => 1,
             'nickname' => 'qiuu'
            ],
            ['Authorization' => "Bearer {$token}"]);
        $response2->assertJson(['errno' => 0]);
        $user = UserServices::getInstance()->getByUsername('qiu');

        $this->assertEquals('qiuu', $user->nickname);
        $this->assertEquals(1, $user->gender);
    }
}
