<?php
/**
 * 路由定义
 * 前台：无前缀；后台：/admin；开放接口：/api
 */
use think\Router;

use app\controller\Home;
use app\controller\Auth;
use app\controller\Cart;
use app\controller\Domain;
use app\controller\Invoice;
use app\controller\Order;
use app\controller\Payment;
use app\controller\User;
use app\controller\api\Whmcs;
use app\controller\api\Cube;
use app\controller\api\V1;

use app\controller\admin\Login;
use app\controller\admin\Dashboard;
use app\controller\admin\Client;
use app\controller\admin\Product;
use app\controller\admin\Order as AdminOrder;
use app\controller\admin\Invoice as AdminInvoice;
use app\controller\admin\Domain as AdminDomain;
use app\controller\admin\Host;
use app\controller\admin\Server;
use app\controller\admin\Ticket;
use app\controller\admin\Data;
use app\controller\admin\PluginController;
use app\controller\admin\Template;
use app\controller\admin\Setting;
use app\controller\admin\Report;
use app\controller\admin\Log;
use app\controller\admin\Cron as AdminCron;

/* ------------------------------- 前台 ------------------------------- */
Router::get('/', [Home::class, 'index']);
Router::get('/products', [Home::class, 'products']);
Router::get('/product/{slug}', [Home::class, 'product']);
Router::get('/page/{slug}', [Home::class, 'page']);
Router::get('/announcements', [Home::class, 'announcements']);
Router::get('/announcement/{id}', [Home::class, 'announcement']);
Router::get('/kb', [Home::class, 'kb']);
Router::get('/kb/{id}', [Home::class, 'kbCategory']);
Router::get('/kb/article/{id}', [Home::class, 'kbArticle']);
Router::get('/downloads', [Home::class, 'downloads']);
Router::any('/contact', [Home::class, 'contact']);

// 域名注册商门户
Router::get('/domains', [Domain::class, 'portal']);
Router::any('/domains/search', [Domain::class, 'search']);
Router::post('/domains/register', [Domain::class, 'register']);
Router::any('/domains/transfer', [Domain::class, 'transfer']);
// 旧地址兼容
Router::any('/domain', [Domain::class, 'legacy']);
Router::any('/domain/search', [Domain::class, 'search']);
Router::post('/domain/add', [Domain::class, 'add']);

Router::get('/cart', [Cart::class, 'index']);
Router::post('/cart/add', [Cart::class, 'add']);
Router::post('/cart/update', [Cart::class, 'update']);
Router::post('/cart/remove', [Cart::class, 'remove']);
Router::post('/cart/clear', [Cart::class, 'clear']);
Router::post('/cart/coupon', [Cart::class, 'coupon']);
Router::any('/checkout', [Cart::class, 'checkout'], ['auth' => 'user']);

Router::any('/login', [Auth::class, 'login']);
Router::any('/register', [Auth::class, 'register']);
Router::get('/logout', [Auth::class, 'logout']);
Router::any('/forgot', [Auth::class, 'forgot']);
Router::any('/reset/{token}', [Auth::class, 'reset']);
Router::get('/verify/{token}', [Auth::class, 'verify']);

Router::any('/user/topup', [Invoice::class, 'topup'], ['auth' => 'user']);
Router::get('/invoice', [Invoice::class, 'index'], ['auth' => 'user']);
Router::get('/invoice/{id}', [Invoice::class, 'detail'], ['auth' => 'user']);
Router::get('/invoice/{id}/pay', [Invoice::class, 'pay'], ['auth' => 'user']);
Router::post('/invoice/{id}/pay/{gateway}', [Invoice::class, 'payGateway'], ['auth' => 'user']);
Router::post('/invoice/{id}/balance', [Invoice::class, 'balance'], ['auth' => 'user']);
Router::get('/invoice/{id}/print', [Invoice::class, 'printView'], ['auth' => 'user']);

Router::any('/pay/notify/{gateway}', [Payment::class, 'notify'], ['csrf' => false]);
Router::any('/pay/return/{gateway}', [Payment::class, 'return'], ['csrf' => false]);

Router::get('/order', [Order::class, 'index'], ['auth' => 'user']);
Router::get('/order/{id}', [Order::class, 'detail'], ['auth' => 'user']);

Router::get('/user', [User::class, 'index'], ['auth' => 'user']);
Router::get('/user/services', [User::class, 'services'], ['auth' => 'user']);
Router::get('/user/service/{id}', [User::class, 'service'], ['auth' => 'user']);
Router::post('/user/service/{id}/action', [User::class, 'serviceAction'], ['auth' => 'user']);
Router::post('/user/service/{id}/password', [User::class, 'servicePassword'], ['auth' => 'user']);
Router::post('/user/service/{id}/renew', [User::class, 'serviceRenew'], ['auth' => 'user']);
Router::any('/user/service/{id}/upgrade', [User::class, 'upgrade'], ['auth' => 'user']);
Router::get('/user/domains', [User::class, 'domains'], ['auth' => 'user']);
Router::get('/user/domain/{id}', [User::class, 'domain'], ['auth' => 'user']);
Router::post('/user/domain/{id}/ns', [User::class, 'domainNs'], ['auth' => 'user']);
Router::post('/user/domain/{id}/lock', [User::class, 'domainLock'], ['auth' => 'user']);
Router::post('/user/domain/{id}/privacy', [User::class, 'domainPrivacy'], ['auth' => 'user']);
Router::post('/user/domain/{id}/epp', [User::class, 'domainEpp'], ['auth' => 'user']);
Router::post('/user/domain/{id}/renew', [User::class, 'domainRenew'], ['auth' => 'user']);
Router::post('/user/domain/{id}/sync', [User::class, 'domainSync'], ['auth' => 'user']);
Router::get('/user/tickets', [User::class, 'tickets'], ['auth' => 'user']);
Router::any('/user/ticket/new', [User::class, 'ticketNew'], ['auth' => 'user']);
Router::get('/user/ticket/{id}', [User::class, 'ticket'], ['auth' => 'user']);
Router::post('/user/ticket/{id}/reply', [User::class, 'ticketReply'], ['auth' => 'user']);
Router::post('/user/ticket/{id}/close', [User::class, 'ticketClose'], ['auth' => 'user']);
Router::any('/user/profile', [User::class, 'profile'], ['auth' => 'user']);
Router::any('/user/password', [User::class, 'password'], ['auth' => 'user']);
Router::get('/user/security', [User::class, 'security'], ['auth' => 'user']);
Router::post('/user/api', [User::class, 'api'], ['auth' => 'user']);
Router::any('/user/affiliate', [User::class, 'affiliate'], ['auth' => 'user']);
Router::get('/user/transactions', [User::class, 'transactions'], ['auth' => 'user']);
Router::get('/user/addresses', [User::class, 'addresses'], ['auth' => 'user']);
Router::post('/user/address/save', [User::class, 'addressSave'], ['auth' => 'user']);
Router::post('/user/address/delete', [User::class, 'addressDelete'], ['auth' => 'user']);

/* ------------------------------- 后台 ------------------------------- */
Router::any('/admin/login', [Login::class, 'index']);
Router::get('/admin/logout', [Login::class, 'logout']);

Router::group('/admin', function () {
    Router::get('/', [Dashboard::class, 'index']);
    Router::get('', [Dashboard::class, 'index']);

    Router::get('/clients', [Client::class, 'index']);
    Router::get('/client/create', [Client::class, 'create']);
    Router::post('/client/create', [Client::class, 'create']);
    Router::get('/client/{id}', [Client::class, 'detail']);
    Router::post('/client/{id}/update', [Client::class, 'update']);
    Router::post('/client/{id}/balance', [Client::class, 'balance']);
    Router::post('/client/{id}/mail', [Client::class, 'mail']);
    Router::post('/client/{id}/login', [Client::class, 'loginAs']);

    Router::get('/products', [Product::class, 'index']);
    Router::any('/product/create', [Product::class, 'edit']);
    Router::any('/product/{id}/edit', [Product::class, 'edit']);
    Router::any('/product/{id}/pricing', [Product::class, 'pricing']);
    Router::any('/product/{id}/options', [Product::class, 'options']);
    Router::post('/product/{id}/delete', [Product::class, 'delete']);

    Router::get('/orders', [AdminOrder::class, 'index']);
    Router::get('/order/{id}', [AdminOrder::class, 'detail']);
    Router::post('/order/{id}/status', [AdminOrder::class, 'status']);
    Router::post('/order/{id}/activate', [AdminOrder::class, 'activate']);

    Router::get('/invoices', [AdminInvoice::class, 'index']);
    Router::any('/invoice/create', [AdminInvoice::class, 'create']);
    Router::get('/invoice/{id}', [AdminInvoice::class, 'detail']);
    Router::get('/invoice/{id}/print', [AdminInvoice::class, 'printView']);
    Router::post('/invoice/{id}/paid', [AdminInvoice::class, 'markPaid']);
    Router::post('/invoice/{id}/refund', [AdminInvoice::class, 'refund']);
    Router::post('/invoice/{id}/cancel', [AdminInvoice::class, 'cancel']);
    Router::post('/invoice/{id}/send', [AdminInvoice::class, 'send']);

    Router::get('/domains', [AdminDomain::class, 'index']);
    Router::any('/domain/create', [AdminDomain::class, 'create']);
    Router::get('/domain/{id}', [AdminDomain::class, 'detail']);
    Router::post('/domain/{id}/action', [AdminDomain::class, 'action']);

    Router::get('/hosts', [Host::class, 'index']);
    Router::any('/host/create', [Host::class, 'create']);
    Router::get('/host/{id}', [Host::class, 'detail']);
    Router::post('/host/{id}/action', [Host::class, 'action']);

    Router::get('/servers', [Server::class, 'index']);
    Router::any('/server/create', [Server::class, 'edit']);
    Router::any('/server/{id}/edit', [Server::class, 'edit']);
    Router::post('/server/{id}/test', [Server::class, 'test']);
    Router::post('/server/{id}/delete', [Server::class, 'delete']);

    Router::get('/tickets', [Ticket::class, 'index']);
    Router::get('/ticket/{id}', [Ticket::class, 'detail']);
    Router::post('/ticket/{id}/reply', [Ticket::class, 'reply']);
    Router::post('/ticket/{id}/status', [Ticket::class, 'status']);

    Router::get('/data/{entity}', [Data::class, 'index']);
    Router::any('/data/{entity}/create', [Data::class, 'create']);
    Router::any('/data/{entity}/edit/{id}', [Data::class, 'edit']);
    Router::post('/data/{entity}/delete/{id}', [Data::class, 'delete']);

    Router::get('/plugins', [PluginController::class, 'index']);
    Router::any('/plugin/{type}/{name}', [PluginController::class, 'config']);
    Router::post('/plugin/{type}/{name}/toggle', [PluginController::class, 'toggle']);
    Router::post('/plugin/{type}/{name}/test', [PluginController::class, 'test']);
    Router::post('/plugin/{type}/{name}/sync', [PluginController::class, 'sync']);

    Router::get('/templates', [Template::class, 'index']);
    Router::get('/template/edit', [Template::class, 'edit']);
    Router::post('/template/save', [Template::class, 'save']);
    Router::post('/template/create', [Template::class, 'create']);
    Router::post('/template/delete', [Template::class, 'delete']);
    Router::post('/template/switch', [Template::class, 'switch']);

    Router::get('/settings', [Setting::class, 'index']);
    Router::post('/settings', [Setting::class, 'index']);
    Router::get('/settings/{tab}', [Setting::class, 'index']);

    Router::get('/reports', [Report::class, 'index']);
    Router::get('/logs', [Log::class, 'index']);
    Router::get('/logs/{type}', [Log::class, 'index']);
    Router::get('/cron', [AdminCron::class, 'index']);
    Router::post('/cron/run', [AdminCron::class, 'run']);
}, ['auth' => 'admin']);

/* ------------------------------- 接口 ------------------------------- */
Router::any('/api/whmcs', [Whmcs::class, 'index'], ['csrf' => false]);
Router::any('/api/cube', [Cube::class, 'index'], ['csrf' => false]);
Router::any('/api/v1/{action}', [V1::class, 'index'], ['csrf' => false]);
