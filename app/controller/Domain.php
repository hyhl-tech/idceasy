<?php
/**
 * 前台：域名查询与加入购物车
 */
namespace app\controller;

use app\service\DomainService;
use think\Db;
use think\Response;

class Domain extends Front
{
    public function index(): Response
    {
        $tlds = Db::table('domain_tld')->where('status', 1)->order('sort')->select();
        $keyword = trim((string)$this->request->param('keyword', ''));
        $results = null;
        if ($keyword !== '') {
            $results = DomainService::search($keyword, (array)$this->request->param('tlds', []));
        }
        return $this->view('domain', compact('tlds', 'keyword', 'results'));
    }

    public function search(): Response
    {
        $keyword = trim((string)$this->request->param('keyword', ''));
        $tlds = (array)$this->request->param('tlds', []);
        $result = DomainService::search($keyword, $tlds);
        if ($this->request->isAjax()) return $this->json($result);
        $allTlds = Db::table('domain_tld')->where('status', 1)->order('sort')->select();
        return $this->view('domain', [
            'tlds' => $allTlds, 'keyword' => $keyword, 'results' => $result,
        ]);
    }

    public function add(): Response
    {
        if (!$this->request->isPost()) return $this->redirect(url('/domain'));
        $domain = strtolower(trim((string)$this->request->param('domain')));
        $years = max(1, min(10, $this->request->int('years', 1)));
        $result = DomainService::search($domain);
        if (!$result['ok'] || empty($result['items'])) return $this->error($result['error'] ?? '查询失败');
        $item = $result['items'][0];
        if ($item['available'] !== true) return $this->error('该域名当前不可注册');
        if (!empty($item['premium'])) return $this->error('溢价域名请联系客服报价');
        cart_add([
            'type' => 'domain', 'product_id' => 0, 'domain' => $item['domain'], 'tld' => $item['tld'],
            'cycle' => 'annual', 'qty' => $years, 'price' => $item['price'],
            'name' => $item['domain'], 'options' => [],
        ]);
        return $this->success('已加入购物车', url('/cart'));
    }
}
