<?php
/**
 * 前台：首页、产品、单页、公告、知识库、下载、联系
 */
namespace app\controller;

use app\service\Mailer;
use think\Db;
use think\Response;

class Home extends Front
{
    public function index(): Response
    {
        $groups = Db::table('product_group')->where('hidden', 0)->order('sort')->select();
        $products = Db::table('product')->where(['status' => 1, 'hidden' => 0])->order('sort')->limit(6)->select();
        foreach ($products as &$p) {
            $min = $this->minPrice((int)$p['id']);
            $p['price_from'] = $min['price'];
            $p['price_cycle'] = $min['cycle'];
        }
        unset($p);
        $announcements = Db::table('announcement')->where('published', 1)->order('id', 'desc')
            ->limit((int)cfg('announce_home_count', 3))->select();
        $stats = [
            'clients' => (int)Db::table('user')->where('status', 1)->count(),
            'domains' => (int)Db::table('domain')->where('status', 'active')->count(),
            'hosts'   => (int)Db::table('host')->whereIn('status', ['active', 'suspended'])->count(),
        ];
        return $this->view('index', compact('groups', 'products', 'announcements', 'stats'));
    }

    public function products(): Response
    {
        $groupId = $this->request->int('group');
        $groups = Db::table('product_group')->where('hidden', 0)->order('sort')->select();
        $q = Db::table('product')->where(['status' => 1, 'hidden' => 0]);
        if ($groupId) $q->where('group_id', $groupId);
        $products = $q->order('sort')->select();
        foreach ($products as &$p) {
            $min = $this->minPrice((int)$p['id']);
            $p['price_from'] = $min['price'];
            $p['price_cycle'] = $min['cycle'];
        }
        unset($p);
        $currentGroup = null;
        foreach ($groups as $g) if ((int)$g['id'] === $groupId) $currentGroup = $g;
        return $this->view('products', compact('groups', 'products', 'currentGroup', 'groupId'));
    }

    public function product(string $slug): Response
    {
        $product = Db::table('product')->where('slug', $slug)->where('status', 1)->find()
            ?: Db::table('product')->where('id', (int)$slug)->where('status', 1)->find();
        if (!$product) return $this->notFound('产品不存在或已下架');
        $pricing = Db::table('pricing')->where(['product_id' => $product['id'], 'enabled' => 1])->select();
        $options = Db::table('product_option')->where('product_id', $product['id'])->order('sort')->select();
        foreach ($options as &$opt) {
            $opt['values'] = Db::table('product_option_value')->where('option_id', $opt['id'])->order('sort')->select();
        }
        unset($opt);
        $group = Db::table('product_group')->where('id', $product['group_id'])->find();
        $related = Db::table('product')->where('group_id', $product['group_id'])
            ->where('id', '<>', $product['id'])->where(['status' => 1, 'hidden' => 0])->limit(3)->select();
        return $this->view('product', compact('product', 'pricing', 'options', 'group', 'related'));
    }

    public function page(string $slug): Response
    {
        $page = Db::table('page')->where('slug', $slug)->where('status', 1)->find();
        if (!$page) return $this->notFound();
        return $this->view('page', compact('page'));
    }

    public function announcements(): Response
    {
        $list = Db::table('announcement')->where('published', 1)->order('id', 'desc')->paginate(10, $this->pageNo());
        return $this->view('announcements', ['list' => $list]);
    }

    public function announcement(int $id): Response
    {
        $item = Db::table('announcement')->where(['id' => $id, 'published' => 1])->find();
        if (!$item) return $this->notFound();
        Db::table('announcement')->where('id', $id)->increment('views');
        return $this->view('announcement', compact('item'));
    }

    public function kb(): Response
    {
        $categories = Db::table('kb_category')->order('sort')->select();
        foreach ($categories as &$c) {
            $c['articles'] = Db::table('kb_article')->where('category_id', $c['id'])->order('sort')->limit(6)->select();
        }
        unset($c);
        return $this->view('kb', compact('categories'));
    }

    public function kbCategory(int $id): Response
    {
        $category = Db::table('kb_category')->where('id', $id)->find();
        if (!$category) return $this->notFound();
        $articles = Db::table('kb_article')->where('category_id', $id)->order('sort')->select();
        return $this->view('kb_category', compact('category', 'articles'));
    }

    public function kbArticle(int $id): Response
    {
        $article = Db::table('kb_article')->where('id', $id)->find();
        if (!$article) return $this->notFound();
        Db::table('kb_article')->where('id', $id)->increment('views');
        $category = Db::table('kb_category')->where('id', $article['category_id'])->find();
        $related = Db::table('kb_article')->where('category_id', $article['category_id'])
            ->where('id', '<>', $id)->limit(5)->select();
        return $this->view('kb_article', compact('article', 'category', 'related'));
    }

    public function downloads(): Response
    {
        $list = Db::table('download')->order('id', 'desc')->select();
        return $this->view('downloads', compact('list'));
    }

    public function contact(): Response
    {
        if ($this->request->isPost()) {
            $name = trim((string)$this->request->param('name'));
            $email = trim((string)$this->request->param('email'));
            $subject = trim((string)$this->request->param('subject'));
            $message = trim((string)$this->request->param('message'));
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $message === '') {
                return $this->error('请填写姓名、有效邮箱与内容');
            }
            $body = '<p>来自官网联系表单</p><p>姓名：' . e($name) . '<br>邮箱：' . e($email) . '<br>主题：' . e($subject) . '</p><p>' . nl2br(e($message)) . '</p>';
            Mailer::send((string)cfg('company_email', ''), '官网咨询：' . ($subject ?: $name), $body, false);
            return $this->success('已收到，我们会尽快与您联系', url('/contact'));
        }
        return $this->view('contact');
    }
}
