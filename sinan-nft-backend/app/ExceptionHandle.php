<?php
namespace app;

use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

/**
 * 应用异常处理类
 */
class ExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
    ];

    /**
     * 记录异常信息（包括日志或者其它方式记录）
     *
     * @access public
     * @param  Throwable $exception
     * @return void
     */
    public function report(Throwable $exception): void
    {
        // 使用内置的方式记录异常日志
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * H3-D1 修复：本服务为纯 API（C 端 + 管理端），未捕获异常必须返回统一 JSON
     * 错误响应，禁止渲染框架调试页（堆栈/源码路径泄露，APP_DEBUG=true 时尤其严重）。
     * 业务错误仍由控制器 fail() 正常返回，不经过此路径；异常详情仍经 report() 落日志。
     */
    public function render($request, Throwable $e): Response
    {
        // 控制器/中间件已构造的响应（含业务 JSON）原样透出
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }

        $status = $e instanceof HttpException ? (int) ($e->getStatusCode() ?: 500) : 500;
        $code   = 5001;
        $msg    = '系统繁忙，请稍后再试';

        if ($e instanceof ValidateException) {
            $status = 400;
            $code   = 1002;
            $msg    = is_string($e->getError()) ? $e->getError() : '参数错误';
        } elseif ($status === 404) {
            $code = 4040;
            $msg  = '接口不存在';
        } elseif ($status === 405) {
            $code = 4050;
            $msg  = '请求方法不支持';
        } elseif ($status === 401) {
            $code = 4010;
            $msg  = '未授权';
        } elseif ($status === 403) {
            $code = 4030;
            $msg  = '禁止访问';
        }

        return json(['code' => $code, 'message' => $msg, 'data' => null], $status);
    }
}
