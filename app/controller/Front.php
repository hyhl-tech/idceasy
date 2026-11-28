<?php
/**
 * 前台控制器基类
 */
namespace app\controller;

use think\Controller;
use think\Db;
use think\Response;
use think\Session;

class Front extends Controller
{
    protected string $theme = 'default';

    protected function requireLogin(): void
    {
        if (!is_logged_in()) {
            Response::redirect(url('/login') . '?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'))->send();
            exit;
        }
    }

    protected function user(): array
    {
        $user = current_user();
        if (!$user) {
            Response::redirect(url('/login'))->send();
            exit;
        }
        return $user;
    }

    protected function notFound(string $message = '页面不存在'): Response
    {
        return $this->view('404', ['message' => $message])->header('X-Robots-Tag', 'noindex');
    }

    protected function minPrice(int $productId): array
    {
        $row = Db::table('pricing')->where(['product_id' => $productId, 'enabled' => 1])->order('price')->find();
        return ['price' => (float)($row['price'] ?? 0), 'cycle' => $row['cycle'] ?? 'monthly'];
    }
}
