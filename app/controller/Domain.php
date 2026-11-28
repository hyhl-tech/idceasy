<?php
/**
 * 前台：域名注册商门户
 * 查询、比价、注册、转入，订单统一走购物车与账单。
 */
namespace app\controller;

use app\service\DomainService;
use think\Db;
use think\Response;

class Domain extends Front
{
    /** 注册商门户 */
    public function portal(): Response
    {
        return $this->renderPortal('');
    }

    /** 兼容旧入口 */
    public function legacy(): Response
    {
        return $this->redirect(url('/domains'));
    }

    /** 域名查询（GET 渲染页面，AJAX 返回 JSON） */
    public function search(): Response
    {
        $keyword = DomainService::normalize((string)$this->request->param('keyword', ''));
        if ($keyword === '') return $this->redirect(url('/domains'));
        $tlds = array_values(array_filter(array_map('strval', (array)$this->request->param('tlds', []))));
        $result = DomainService::search($keyword, $tlds);
        if ($this->request->isAjax()) return $this->json($result);
        return $this->renderPortal($keyword, $result);
    }

    /** 加入购物车（注册） */
    public function register(): Response
    {
        if (!$this->request->isPost()) return $this->redirect(url('/domains'));
        $domain = DomainService::normalize((string)$this->request->param('domain'));
        $years = max(1, min(10, $this->request->int('years', 1)));
        $registrar = trim((string)$this->request->param('registrar'));
        if (!DomainService::valid($domain)) return $this->error('请输入正确的域名');

        $quote = DomainService::quote($domain, $years, $registrar ?: null);
        if (empty($quote['best'])) {
            return $this->error($quote['error'] ?? '该域名当前不可注册');
        }
        $best = $quote['best'];
        if ($best['premium']) return $this->error('溢价域名请联系客服报价');

        cart_add([
            'type' => 'domain', 'product_id' => 0, 'domain' => $domain, 'tld' => DomainService::tldOf($domain),
            'cycle' => 'annual', 'qty' => $years, 'price' => (float)$best['price'],
            'registrar' => (string)$best['registrar'], 'name' => $domain, 'options' => [],
        ]);
        return $this->success('已加入购物车，请确认年限与联系人信息', url('/cart'));
    }

    /** 域名转入 */
    public function transfer(): Response
    {
        $tlds = Db::table('domain_tld')->where('status', 1)->order('sort')->select();
        if (!$this->request->isPost()) {
            return $this->view('domains_transfer', ['tlds' => $tlds, 'domain' => '', 'title' => '域名转入']);
        }
        $domain = DomainService::normalize((string)$this->request->param('domain'));
        $authCode = trim((string)$this->request->param('auth_code'));
        if (!DomainService::valid($domain)) return $this->error('请输入正确的域名');
        if (strlen($authCode) < 6) return $this->error('请填写域名转移密码（EPP Code）');
        $tld = DomainService::tldOf($domain);
        $row = Db::table('domain_tld')->where('tld', $tld)->where('status', 1)->find();
        if (!$row) return $this->error('暂不支持 .' . $tld . ' 后缀的转入');
        $exists = Db::table('domain')->where('domain', $domain)->find();
        if ($exists && $exists['status'] === 'active') return $this->error('该域名已在系统中，无需转入');

        cart_add([
            'type' => 'transfer', 'product_id' => 0, 'domain' => $domain, 'tld' => $tld,
            'cycle' => 'annual', 'qty' => 1,
            'price' => (float)($row['transfer_price'] ?: $row['register_price']),
            'registrar' => (string)$row['registrar'], 'auth_code' => $authCode,
            'name' => $domain, 'options' => [],
        ]);
        return $this->success('转入订单已加入购物车，支付后提交注册商', url('/cart'));
    }

    /** 旧入口：加域名到购物车 */
    public function add(): Response
    {
        return $this->register();
    }

    protected function renderPortal(string $keyword, ?array $results = null): Response
    {
        $tlds = Db::table('domain_tld')->where('status', 1)->order('sort')->select();
        $registrars = DomainService::enabledRegistrars();
        $tldCount = count($tlds);
        $registrarCount = count($registrars);
        $compare = DomainService::compareEnabled();
        $registrarTotal = count(\think\Plugin::discover('registrar'));
        $topTlds = array_slice($tlds, 0, 3);
        $heroTlds = array_slice($tlds, 0, 10);
        $mainTlds = array_slice($tlds, 0, 8);
        $myDomains = 0;
        $user = current_user();
        if ($user) {
            $myDomains = (int)Db::table('domain')->where('user_id', $user['id'])->where('status', 'active')->count();
        }
        if ($results && !empty($results['ok'])) {
            foreach ($results['items'] as &$item) {
                $parts = [];
                foreach ((array)($item['offers'] ?? []) as $o) {
                    if ($o['available'] !== true || $o['premium'] || $o['price'] <= 0) continue;
                    $parts[] = $o['title'] . ' ' . money((float)$o['price'], $item['currency']);
                    if (count($parts) >= 3) break;
                }
                $item['offers_text'] = implode(' · ', $parts);
            }
            unset($item);
        }
        $title = '域名注册';
        return $this->view('domains', compact(
            'tlds', 'keyword', 'results', 'registrars', 'tldCount', 'registrarCount',
            'compare', 'myDomains', 'title', 'registrarTotal', 'topTlds', 'heroTlds', 'mainTlds'
        ));
    }
}
