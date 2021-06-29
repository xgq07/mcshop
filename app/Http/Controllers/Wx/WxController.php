<?php


namespace App\Http\Controllers\Wx;


use App\CodeResponse;
use App\Http\Controllers\Controller;
use App\ValidateRequest;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;


class WxController extends Controller
{
    use ValidateRequest;

    protected $only;
    protected $except;

    public function __construct()
    {
        $option = [];
        if (!is_null($this->only)) {
            $option['only'] = $this->only;
        }

        if (!is_null($this->except)) {
            $option['except'] = $this->except;
        }

        $this->middleware('auth:wx', $option);
    }

    protected function paginate($page, $list = null)
    {
        if ($page instanceof LengthAwarePaginator) {
            return [
                'total' => $page->total(),
                'page'  => $page->total() == 0 ? 0 : $page->currentPage(),
                'limit' => $page->perPage(),
                'pages' => $page->total() == 0 ? 0 : $page->lastPage(),
                'list'  => $list ?? $page->items()
            ];
        }

        if ($page instanceof Collection) {
            $page = $page->toArray();
        }

        if (!is_array($page)) {
            return $page;
        }

        $total = count($page);
        return [
            'total' => $total,
            'page'  => 0,
            'limit' => $total,
            'pages' => 0,
            'list'  => $page
        ];
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     * 401
     */
    protected function badArgument()
    {
        return $this->fail(CodeResponse::PARAM_ILLEGAL);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     * 402
     */
    protected function badArgumentValue()
    {
        return $this->fail(CodeResponse::PARAM_NOT_EMPTY);
    }


    private function codeReturn($codeResponse, $data = null, $info = '')
    {
        list($errno, $errmsg) = $codeResponse;
        $res = ['errno' => $errno];
        if (is_array($data)) {
            $data        = array_filter($data, function ($item) {
                return $item !== null;
            });
            $res['data'] = $data;
        } elseif (!is_null($data)) {
            $res['data'] = $data;
        }
        $res['errmsg'] = $info ?: $errmsg;
        return response()->json($res);
    }

    public function successPaginate($page, $list = null)
    {
        return $this->success($this->paginate($page, $list));
    }

    public function success($data = null, $info = '')
    {
        return $this->codeReturn(CodeResponse::SUCCESS, $data, $info);
    }

    /**
     * @return Authenticatable|null
     * 返回用户数据
     */
    public function user()
    {
        return Auth::guard('wx')->user();
    }

    public function fail(array $codedResponse, $info = '', $data = null)
    {
        return $this->codeReturn($codedResponse, $data, $info);
    }

    public function failOrSuccess(
        $isSuccess,
        array $codeResponse = CodeResponse::FAIL,
        $data = null,
        $info =  ''
    ){
        if ($isSuccess){
            return $this->success($data);
        }
        return $this->fail($codeResponse, $info);
    }

    /**
     * @return bool
     * 判断是否登录
     */
    public function isLogin()
    {
        return !is_null($this->user());
    }

    /**
     * @return mixed
     * 获取登录用户ID
     */
    public function userId()
    {
        return $this->user()->getAuthIdentifier();
    }
}
