<?php
/**
 * 后台：产品与定价
 */
namespace app\controller\admin;

use think\Db;
use think\Plugin;
use think\Response;

class Product extends Base
{
    protected string $perm = 'product';

    public function index(): Response
    {
        $groups = Db::table('product_group')->order('sort')->select();
        $products = Db::table('product')->order('sort')->select();
        foreach ($products as &$p) {
            $p['group'] = Db::table('product_group')->where('id', $p['group_id'])->find();
            $p['pricing'] = Db::table('pricing')->where('product_id', $p['id'])->order('price')->select();
            $p['hosts'] = Db::table('host')->where('product_id', $p['id'])->count();
        }
        unset($p);
        return $this->view('product_list', compact('groups', 'products'));
    }

    public function edit(int $id = 0): Response
    {
        $product = $id ? Db::table('product')->where('id', $id)->find() : null;
        if ($id && !$product) return $this->error('产品不存在', admin_url('/products'));
        if ($this->request->isPost()) {
            $data = [
                'group_id' => $this->request->int('group_id'),
                'name' => trim((string)$this->request->param('name')),
                'slug' => trim((string)$this->request->param('slug')) ?: ('p' . random_str(6, 'abcdefghijklmnopqrstuvwxyz')),
                'type' => (string)$this->request->param('type', 'host'),
                'description' => (string)$this->request->param('description'),
                'module' => (string)$this->request->param('module', 'manual'),
                'server_group_id' => $this->request->int('server_group_id'),
                'stock' => $this->request->int('stock'),
                'auto_setup' => $this->request->bool('auto_setup') ? 1 : 0,
                'require_domain' => $this->request->bool('require_domain') ? 1 : 0,
                'status' => $this->request->bool('status') ? 1 : 0,
                'hidden' => $this->request->bool('hidden') ? 1 : 0,
                'sort' => $this->request->int('sort'),
            ];
            if ($data['name'] === '') return $this->error('请填写产品名称');
            if (!in_array($data['type'], ['host', 'vps', 'dedicated', 'ssl', 'domain', 'other'], true)) $data['type'] = 'host';
            $slugExists = Db::table('product')->where('slug', $data['slug'])->where('id', '<>', $id)->exists();
            if ($slugExists) $data['slug'] .= '-' . random_str(4, 'abcdefghijklmnopqrstuvwxyz');
            if ($id) {
                Db::table('product')->where('id', $id)->update($data);
                $this->logAction('编辑产品', ['id' => $id]);
            } else {
                $data['created_at'] = now();
                $id = Db::table('product')->insert($data);
                $this->logAction('创建产品', ['id' => $id]);
            }
            return $this->success('已保存', admin_url('/product/' . $id . '/pricing'));
        }
        $groups = Db::table('product_group')->order('sort')->select();
        $modules = Plugin::discover('server');
        $serverGroups = Db::table('server_group')->order('id')->select();
        $fields = [
            ['name' => 'name', 'label' => '产品名称', 'type' => 'text', 'required' => true],
            ['name' => 'slug', 'label' => 'URL 标识', 'type' => 'text', 'help' => '用于前台 /product/{slug}，留空自动生成'],
            ['name' => 'group_id', 'label' => '产品分组', 'type' => 'select',
                'options' => array_column($groups, 'name', 'id'), 'required' => true],
            ['name' => 'type', 'label' => '产品类型', 'type' => 'select', 'options' => [
                'host' => '虚拟主机', 'vps' => '云服务器', 'dedicated' => '独立服务器',
                'ssl' => 'SSL 证书', 'domain' => '域名产品', 'other' => '其他',
            ]],
            ['name' => 'module', 'label' => '开通模块', 'type' => 'select',
                'options' => array_column($modules, 'title', 'id'), 'help' => '服务器插件决定自动开通方式，manual 表示人工开通'],
            ['name' => 'server_group_id', 'label' => '服务器分组', 'type' => 'select',
                'options' => [0 => '自动选择'] + array_column($serverGroups, 'name', 'id')],
            ['name' => 'stock', 'label' => '库存', 'type' => 'number', 'help' => '0 表示不限库存'],
            ['name' => 'sort', 'label' => '排序', 'type' => 'number'],
            ['name' => 'description', 'label' => '产品描述', 'type' => 'textarea', 'help' => '支持 HTML'],
            ['name' => 'auto_setup', 'label' => '付款后自动开通', 'type' => 'checkbox'],
            ['name' => 'require_domain', 'label' => '下单必须填写域名', 'type' => 'checkbox'],
            ['name' => 'status', 'label' => '上架', 'type' => 'checkbox'],
            ['name' => 'hidden', 'label' => '前台隐藏', 'type' => 'checkbox'],
        ];
        return $this->view('form', [
            'title' => $id ? '编辑产品：' . $product['name'] : '新建产品',
            'fields' => $fields,
            'values' => $product ?: ['status' => 1, 'auto_setup' => 1, 'module' => 'manual', 'type' => 'host'],
            'action' => admin_url('/product/' . $id . '/edit'),
            'cancel' => admin_url('/products'),
            'note' => '保存后请继续配置计费周期与可配置选项。',
        ]);
    }

    public function pricing(int $id): Response
    {
        $product = Db::table('product')->where('id', $id)->find();
        if (!$product) return $this->error('产品不存在', admin_url('/products'));
        if ($this->request->isPost()) {
            Db::transaction(function () use ($id) {
                Db::table('pricing')->where('product_id', $id)->delete();
                $cycleList = (array)$this->request->param('cycle', []);
                foreach ($cycleList as $cycle => $row) {
                    if (!in_array($cycle, cycles(), true)) continue;
                    $price = (float)($row['price'] ?? 0);
                    $setup = (float)($row['setup_fee'] ?? 0);
                    if ($price <= 0 && $setup <= 0 && empty($row['enabled'])) continue;
                    Db::table('pricing')->insert([
                        'product_id' => $id, 'cycle' => $cycle, 'price' => $price, 'setup_fee' => $setup,
                        'currency' => (string)($row['currency'] ?? 'CNY'), 'enabled' => empty($row['enabled']) ? 0 : 1,
                    ]);
                }
            });
            $this->logAction('产品定价', ['product_id' => $id]);
            return $this->success('价格已保存');
        }
        $pricing = Db::table('pricing')->where('product_id', $id)->select();
        $map = [];
        foreach ($pricing as $p) $map[$p['cycle']] = $p;
        foreach (cycles() as $c) {
            if (!isset($map[$c])) {
                $map[$c] = ['cycle' => $c, 'price' => 0, 'setup_fee' => 0, 'currency' => 'CNY', 'enabled' => 0];
            }
        }
        return $this->view('product_pricing', compact('product', 'map'));
    }

    public function options(int $id): Response
    {
        $product = Db::table('product')->where('id', $id)->find();
        if (!$product) return $this->error('产品不存在', admin_url('/products'));
        if ($this->request->isPost()) {
            $rows = (array)$this->request->param('options', []);
            Db::transaction(function () use ($id, $rows) {
                $old = Db::table('product_option')->where('product_id', $id)->column('id');
                if ($old) Db::table('product_option_value')->whereIn('option_id', $old)->delete();
                Db::table('product_option')->where('product_id', $id)->delete();
                $sort = 0;
                foreach ($rows as $row) {
                    $name = trim((string)($row['name'] ?? ''));
                    if ($name === '') continue;
                    $vals = $row['values'] ?? [];
                    if (is_string($vals)) {
                        $parsed = [];
                        foreach (preg_split('/\r?\n/', $vals) as $line) {
                            $line = trim($line);
                            if ($line === '') continue;
                            $parts = array_map('trim', explode('|', $line));
                            if (($parts[0] ?? '') === '') continue;
                            $parsed[] = ['name' => $parts[0], 'price' => $parts[1] ?? 0, 'setup_fee' => $parts[2] ?? 0];
                        }
                        $vals = $parsed;
                    }
                    $optionId = Db::table('product_option')->insert([
                        'product_id' => $id, 'name' => $name,
                        'type' => in_array($row['type'] ?? '', ['select', 'checkbox', 'qty'], true) ? $row['type'] : 'select',
                        'required' => empty($row['required']) ? 0 : 1, 'sort' => $sort++,
                    ]);
                    foreach ((array)$vals as $v) {
                        $vn = trim((string)($v['name'] ?? ''));
                        if ($vn === '') continue;
                        Db::table('product_option_value')->insert([
                            'option_id' => $optionId, 'name' => $vn,
                            'price' => (float)($v['price'] ?? 0), 'setup_fee' => (float)($v['setup_fee'] ?? 0),
                            'sort' => (int)($v['sort'] ?? 0),
                        ]);
                    }
                }
            });
            $this->logAction('产品选项', ['product_id' => $id]);
            return $this->success('可配置选项已保存');
        }
        $options = Db::table('product_option')->where('product_id', $id)->order('sort')->select();
        foreach ($options as &$opt) {
            $opt['values'] = Db::table('product_option_value')->where('option_id', $opt['id'])->order('sort')->select();
        }
        unset($opt);
        $options[] = ['name' => '', 'type' => 'select', 'required' => 0, 'values' => []];
        $options[] = ['name' => '', 'type' => 'select', 'required' => 0, 'values' => []];
        return $this->view('product_options', compact('product', 'options'));
    }

    public function delete(int $id): Response
    {
        if (!$this->request->isPost()) return $this->redirect(admin_url('/products'));
        $used = Db::table('host')->where('product_id', $id)->exists() || Db::table('order_item')->where('product_id', $id)->exists();
        if ($used) {
            Db::table('product')->where('id', $id)->update(['status' => 0, 'hidden' => 1]);
            $this->logAction('下架产品', ['id' => $id]);
            return $this->success('该产品已有业务记录，已改为下架');
        }
        Db::table('product_option_value')->whereIn('option_id', Db::table('product_option')->where('product_id', $id)->column('id'))->delete();
        Db::table('product_option')->where('product_id', $id)->delete();
        Db::table('pricing')->where('product_id', $id)->delete();
        Db::table('product')->where('id', $id)->delete();
        $this->logAction('删除产品', ['id' => $id]);
        return $this->success('产品已删除');
    }
}
