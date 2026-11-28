<?php
/**
 * 后台通用数据管理（公告、页面、知识库、模板、优惠码、币种、TLD、员工等）
 * 单一控制器 + 元数据配置，减少重复代码，便于二次开发。
 */
namespace app\controller\admin;

use think\Db;
use think\HttpException;
use think\Plugin;
use think\Request;
use think\Response;

class Data extends Base
{
    protected string $perm = 'content';
    protected array $entities = [];
    protected static array $columnsCache = [];

    public function __construct()
    {
        parent::__construct();
        $this->entities = $this->registry();
    }

    public function authorize(string $method): void
    {
        $entity = (string)Request::instance()->param('entity');
        if (!isset($this->entities[$entity])) throw new HttpException('未知数据表', 404);
        $perm = $this->entities[$entity]['perm'] ?? 'content';
        $map = ['index' => 'view', 'create' => 'edit', 'edit' => 'edit', 'delete' => 'delete'];
        $action = $map[$method] ?? $method;
        if (!can($perm . '.' . $action)) throw new HttpException('无权执行该操作', 403);
    }

    public function index(string $entity): Response
    {
        $cfg = $this->cfg($entity);
        $q = Db::table($cfg['table']);
        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '' && !empty($cfg['search'])) {
            $parts = [];
            $binds = [];
            foreach ($cfg['search'] as $field) {
                $parts[] = "`{$field}` LIKE ?";
                $binds[] = '%' . $keyword . '%';
            }
            $q->whereRaw('(' . implode(' OR ', $parts) . ')', $binds);
        }
        $order = $cfg['order'] ?? 'id desc';
        [$field, $dir] = array_pad(explode(' ', $order), 2, 'desc');
        $pagination = $q->order($field, $dir)->paginate(20, $this->pageNo());
        return $this->view('table', [
            'title' => $cfg['title'],
            'columns' => $cfg['columns'],
            'rows' => $pagination,
            'rowLinks' => [['label' => '编辑', 'url' => admin_url('/data/' . $entity . '/edit/__ID__'), 'class' => 'btn-sm']],
            'rowDelete' => true,
            'deleteUrl' => admin_url('/data/' . $entity . '/delete/__ID__'),
            'filters' => !empty($cfg['search']) ? [[
                'type' => 'text', 'name' => 'keyword', 'placeholder' => '搜索 ' . implode(' / ', $cfg['search']), 'value' => $keyword,
            ]] : [],
            'topActions' => [['label' => '新增' . $cfg['title'], 'url' => admin_url('/data/' . $entity . '/create'), 'class' => 'btn-primary']],
            'baseUrl' => admin_url('/data/' . $entity),
        ]);
    }

    public function create(string $entity): Response
    {
        $cfg = $this->cfg($entity);
        if (!$this->request->isPost()) {
            return $this->view('form', [
                'title' => '新增' . $cfg['title'], 'fields' => $cfg['fields'], 'values' => $cfg['defaults'] ?? [],
                'action' => admin_url('/data/' . $entity . '/create'), 'cancel' => admin_url('/data/' . $entity),
            ]);
        }
        try {
            $data = $this->collect($cfg, null, true);
            $data = $this->beforeSave($entity, $data, null, true);
            $id = Db::table($cfg['table'])->insert($data);
        } catch (\Throwable $e) {
            return $this->error('保存失败：' . $e->getMessage());
        }
        $this->logAction('新增' . $cfg['title'], ['entity' => $entity, 'id' => $id]);
        return $this->success('已保存', admin_url('/data/' . $entity));
    }

    public function edit(string $entity, int $id): Response
    {
        $cfg = $this->cfg($entity);
        $row = Db::table($cfg['table'])->where('id', $id)->find();
        if (!$row) return $this->error('记录不存在', admin_url('/data/' . $entity));
        if (!$this->request->isPost()) {
            $values = $row;
            foreach ($cfg['fields'] as $field) {
                if (($field['type'] ?? '') === 'datetime' && !empty($values[$field['name']])) {
                    $values[$field['name']] = date('Y-m-d\TH:i', strtotime($values[$field['name']]));
                }
            }
            return $this->view('form', [
                'title' => '编辑' . $cfg['title'], 'fields' => $cfg['fields'], 'values' => $values,
                'action' => admin_url('/data/' . $entity . '/edit/' . $id), 'cancel' => admin_url('/data/' . $entity),
            ]);
        }
        try {
            $data = $this->collect($cfg, $row, false);
            $data = $this->beforeSave($entity, $data, $row, false);
            Db::table($cfg['table'])->where('id', $id)->update($data);
        } catch (\Throwable $e) {
            return $this->error('保存失败：' . $e->getMessage());
        }
        $this->logAction('编辑' . $cfg['title'], ['entity' => $entity, 'id' => $id]);
        return $this->success('已保存', admin_url('/data/' . $entity));
    }

    public function delete(string $entity, int $id): Response
    {
        if (!$this->request->isPost()) return $this->redirect(admin_url('/data/' . $entity));
        $cfg = $this->cfg($entity);
        if (!empty($cfg['guard'])) {
            $error = call_user_func($cfg['guard'], $id);
            if ($error) return $this->error($error);
        }
        Db::table($cfg['table'])->where('id', $id)->delete();
        $this->logAction('删除' . $cfg['title'], ['entity' => $entity, 'id' => $id]);
        return $this->success('已删除', admin_url('/data/' . $entity));
    }

    protected function cfg(string $entity): array
    {
        if (!isset($this->entities[$entity])) throw new HttpException('未知数据表', 404);
        return $this->entities[$entity];
    }

    protected function tableColumns(string $table): array
    {
        if (!isset(self::$columnsCache[$table])) {
            $rows = Db::select('SHOW COLUMNS FROM ' . Db::prefix() . $table);
            self::$columnsCache[$table] = array_column($rows, 'Field');
        }
        return self::$columnsCache[$table];
    }

    protected function collect(array $cfg, ?array $old, bool $isNew): array
    {
        $columns = $this->tableColumns($cfg['table']);
        $data = [];
        foreach ($cfg['fields'] as $field) {
            $name = $field['name'];
            if (!in_array($name, $columns, true)) continue;
            $type = $field['type'] ?? 'text';
            $value = $this->request->param($name);
            if ($type === 'checkbox') {
                $data[$name] = $value ? 1 : 0;
                continue;
            }
            if ($type === 'password') {
                if (is_string($value) && $value !== '') $data[$name] = password_hash($value, PASSWORD_DEFAULT);
                continue;
            }
            $value = is_array($value) ? implode(',', $value) : (string)$value;
            if ($type === 'number' || $type === 'money') {
                $data[$name] = $value === '' ? 0 : (float)$value;
            } elseif ($type === 'datetime') {
                $data[$name] = $value === '' ? null : date('Y-m-d H:i:s', strtotime($value));
            } else {
                $data[$name] = trim($value);
            }
            if (!empty($field['required']) && ($data[$name] === '' || $data[$name] === null)) {
                throw new HttpException('请填写：' . $field['label'], 422);
            }
            if (!empty($field['required_on_create']) && $isNew && $data[$name] === '') {
                throw new HttpException('请填写：' . $field['label'], 422);
            }
        }
        if ($isNew && in_array('created_at', $columns, true) && empty($data['created_at'])) $data['created_at'] = now();
        if (!$isNew && in_array('updated_at', $columns, true)) $data['updated_at'] = now();
        return $data;
    }

    protected function beforeSave(string $entity, array $data, ?array $old, bool $isNew): array
    {
        if ($entity === 'currency') {
            $data['code'] = strtoupper($data['code'] ?? '');
            if (!empty($data['default'])) {
                Db::table('currency')->whereRaw('1 = 1')->update(['default' => 0]);
            }
        }
        if ($entity === 'coupon') {
            $data['code'] = strtoupper($data['code'] ?? '');
        }
        if ($entity === 'staff') {
            if ($isNew && empty($data['password'])) throw new HttpException('请设置登录密码', 422);
            if (empty($data['password'])) unset($data['password']);
        }
        return $data;
    }

    /** 所有可管理实体的元数据 */
    protected function registry(): array
    {
        $roles = array_column(Db::table('admin_role')->select(), 'name', 'id');
        $kbCats = array_column(Db::table('kb_category')->select(), 'name', 'id');
        $currencies = array_column(Db::table('currency')->select(), 'code', 'code');
        $registrars = [];
        foreach (Plugin::discover('registrar') as $id => $info) $registrars[$id] = $info['title'] ?? $id;

        $money = ['type' => 'money'];
        $text = ['type' => 'text'];
        $int = ['type' => 'number'];

        return [
            'announcement' => [
                'table' => 'announcement', 'title' => '公告', 'perm' => 'content', 'order' => 'id desc',
                'search' => ['title'],
                'columns' => [
                    ['field' => 'id', 'label' => 'ID', 'width' => '60px'],
                    ['field' => 'title', 'label' => '标题'],
                    ['field' => 'published', 'label' => '发布', 'type' => 'bool'],
                    ['field' => 'views', 'label' => '浏览'],
                    ['field' => 'created_at', 'label' => '时间', 'type' => 'date'],
                ],
                'fields' => [
                    ['name' => 'title', 'label' => '标题', 'type' => 'text', 'required' => true],
                    ['name' => 'slug', 'label' => 'Slug', 'type' => 'text', 'help' => '可留空'],
                    ['name' => 'content', 'label' => '内容', 'type' => 'code', 'rows' => 14, 'help' => '支持 HTML'],
                    ['name' => 'published', 'label' => '立即发布', 'type' => 'checkbox'],
                ],
                'defaults' => ['published' => 1],
            ],
            'page' => [
                'table' => 'page', 'title' => '单页', 'perm' => 'content', 'order' => 'id desc', 'search' => ['title', 'slug'],
                'columns' => [
                    ['field' => 'id', 'label' => 'ID', 'width' => '60px'],
                    ['field' => 'title', 'label' => '标题'],
                    ['field' => 'slug', 'label' => 'Slug'],
                    ['field' => 'status', 'label' => '状态', 'type' => 'bool'],
                ],
                'fields' => [
                    ['name' => 'title', 'label' => '标题', 'type' => 'text', 'required' => true],
                    ['name' => 'slug', 'label' => 'Slug', 'type' => 'text', 'required' => true, 'help' => '前台地址 /page/{slug}'],
                    ['name' => 'content', 'label' => '内容', 'type' => 'code', 'rows' => 16],
                    ['name' => 'status', 'label' => '启用', 'type' => 'checkbox'],
                ],
                'defaults' => ['status' => 1],
            ],
            'kb_category' => [
                'table' => 'kb_category', 'title' => '知识库分类', 'perm' => 'content', 'order' => 'sort asc', 'search' => ['name'],
                'columns' => [
                    ['field' => 'id', 'label' => 'ID', 'width' => '60px'],
                    ['field' => 'name', 'label' => '名称'],
                    ['field' => 'slug', 'label' => 'Slug'],
                    ['field' => 'sort', 'label' => '排序'],
                ],
                'fields' => [
                    ['name' => 'name', 'label' => '名称', 'type' => 'text', 'required' => true],
                    ['name' => 'slug', 'label' => 'Slug', 'type' => 'text'],
                    ['name' => 'sort', 'label' => '排序', 'type' => 'number'],
                ],
            ],
            'kb_article' => [
                'table' => 'kb_article', 'title' => '知识库文章', 'perm' => 'content', 'order' => 'sort asc', 'search' => ['title'],
                'columns' => [
                    ['field' => 'id', 'label' => 'ID', 'width' => '60px'],
                    ['field' => 'title', 'label' => '标题'],
                    ['field' => 'category_id', 'label' => '分类', 'type' => 'map', 'options' => $kbCats],
                    ['field' => 'views', 'label' => '浏览'],
                    ['field' => 'created_at', 'label' => '时间', 'type' => 'date'],
                ],
                'fields' => [
                    ['name' => 'category_id', 'label' => '分类', 'type' => 'select', 'options' => $kbCats],
                    ['name' => 'title', 'label' => '标题', 'type' => 'text', 'required' => true],
                    ['name' => 'slug', 'label' => 'Slug', 'type' => 'text'],
                    ['name' => 'content', 'label' => '内容', 'type' => 'code', 'rows' => 14],
                    ['name' => 'sort', 'label' => '排序', 'type' => 'number'],
                ],
            ],
            'download' => [
                'table' => 'download', 'title' => '下载', 'perm' => 'content', 'order' => 'id desc', 'search' => ['title'],
                'columns' => [
                    ['field' => 'id', 'label' => 'ID', 'width' => '60px'],
                    ['field' => 'title', 'label' => '标题'],
                    ['field' => 'file', 'label' => '文件地址'],
                    ['field' => 'size', 'label' => '大小', 'type' => 'bytes'],
                ],
                'fields' => [
                    ['name' => 'title', 'label' => '标题', 'type' => 'text', 'required' => true],
                    ['name' => 'file', 'label' => '文件地址', 'type' => 'text', 'help' => '可填写外部 URL 或 /uploads/xxx'],
                    ['name' => 'size', 'label' => '文件大小（字节）', 'type' => 'number'],
                    ['name' => 'description', 'label' => '说明', 'type' => 'textarea'],
                ],
            ],
            'email_template' => [
                'table' => 'email_template', 'title' => '邮件模板', 'perm' => 'content', 'order' => 'id asc', 'search' => ['code', 'name', 'subject'],
                'columns' => [
                    ['field' => 'code', 'label' => '代码'],
                    ['field' => 'name', 'label' => '名称'],
                    ['field' => 'subject', 'label' => '主题'],
                    ['field' => 'enabled', 'label' => '启用', 'type' => 'bool'],
                ],
                'fields' => [
                    ['name' => 'code', 'label' => '模板代码', 'type' => 'text', 'required' => true, 'help' => '如 welcome / invoice_created'],
                    ['name' => 'name', 'label' => '名称', 'type' => 'text', 'required' => true],
                    ['name' => 'subject', 'label' => '邮件主题', 'type' => 'text', 'required' => true],
                    ['name' => 'body', 'label' => '正文', 'type' => 'code', 'rows' => 14, 'help' => '支持 HTML 与 {{name}} 变量'],
                    ['name' => 'enabled', 'label' => '启用', 'type' => 'checkbox'],
                ],
                'defaults' => ['enabled' => 1],
            ],
            'coupon' => [
                'table' => 'coupon', 'title' => '优惠码', 'perm' => 'invoice', 'order' => 'id desc', 'search' => ['code'],
                'columns' => [
                    ['field' => 'code', 'label' => '优惠码'],
                    ['field' => 'type', 'label' => '类型', 'type' => 'map', 'options' => ['percent' => '百分比', 'fixed' => '固定金额']],
                    ['field' => 'value', 'label' => '优惠值'],
                    ['field' => 'uses', 'label' => '已用'],
                    ['field' => 'max_uses', 'label' => '上限'],
                    ['field' => 'status', 'label' => '启用', 'type' => 'bool'],
                ],
                'fields' => [
                    ['name' => 'code', 'label' => '优惠码', 'type' => 'text', 'required' => true],
                    ['name' => 'type', 'label' => '类型', 'type' => 'select', 'options' => ['percent' => '百分比折扣', 'fixed' => '固定金额']],
                    ['name' => 'value', 'label' => '优惠值', 'type' => 'money', 'required' => true, 'help' => '百分比填 10 表示 9 折'],
                    ['name' => 'min_total', 'label' => '最低订单金额', 'type' => 'money'],
                    ['name' => 'max_uses', 'label' => '最大使用次数', 'type' => 'number', 'help' => '0 表示不限'],
                    ['name' => 'start_at', 'label' => '开始时间', 'type' => 'datetime'],
                    ['name' => 'end_at', 'label' => '结束时间', 'type' => 'datetime'],
                    ['name' => 'status', 'label' => '启用', 'type' => 'checkbox'],
                ],
                'defaults' => ['status' => 1, 'type' => 'percent'],
            ],
            'currency' => [
                'table' => 'currency', 'title' => '货币', 'perm' => 'setting', 'order' => 'default desc, code asc', 'search' => ['code', 'name'],
                'columns' => [
                    ['field' => 'code', 'label' => '代码'],
                    ['field' => 'name', 'label' => '名称'],
                    ['field' => 'symbol', 'label' => '符号'],
                    ['field' => 'rate', 'label' => '汇率'],
                    ['field' => 'default', 'label' => '默认', 'type' => 'bool'],
                ],
                'fields' => [
                    ['name' => 'code', 'label' => '货币代码', 'type' => 'text', 'required' => true, 'help' => '如 CNY / USD'],
                    ['name' => 'name', 'label' => '名称', 'type' => 'text', 'required' => true],
                    ['name' => 'symbol', 'label' => '符号', 'type' => 'text', 'required' => true],
                    ['name' => 'rate', 'label' => '汇率', 'type' => 'number', 'help' => '相对基准货币'],
                    ['name' => 'prefix', 'label' => '符号在前', 'type' => 'select', 'options' => ['1' => '是', '0' => '否']],
                    ['name' => 'default', 'label' => '设为默认', 'type' => 'checkbox'],
                ],
                'defaults' => ['rate' => 1, 'prefix' => 1],
                'guard' => function ($id) {
                    $row = Db::table('currency')->where('id', $id)->find();
                    return ($row && (int)$row['default'] === 1) ? '默认货币不能删除' : '';
                },
            ],
            'tax_rule' => [
                'table' => 'tax_rule', 'title' => '税率', 'perm' => 'setting', 'order' => 'priority asc', 'search' => ['name', 'country'],
                'columns' => [
                    ['field' => 'name', 'label' => '名称'],
                    ['field' => 'country', 'label' => '国家/地区'],
                    ['field' => 'rate', 'label' => '税率 %'],
                    ['field' => 'status', 'label' => '启用', 'type' => 'bool'],
                ],
                'fields' => [
                    ['name' => 'name', 'label' => '名称', 'type' => 'text', 'required' => true],
                    ['name' => 'country', 'label' => '国家代码', 'type' => 'text', 'help' => '如 CN，留空表示全部'],
                    ['name' => 'rate', 'label' => '税率（%）', 'type' => 'number'],
                    ['name' => 'priority', 'label' => '优先级', 'type' => 'number'],
                    ['name' => 'status', 'label' => '启用', 'type' => 'checkbox'],
                ],
            ],
            'tld' => [
                'table' => 'domain_tld', 'title' => '域名后缀', 'perm' => 'domain', 'order' => 'sort asc', 'search' => ['tld', 'registrar'],
                'columns' => [
                    ['field' => 'tld', 'label' => '后缀'],
                    ['field' => 'registrar', 'label' => '注册商'],
                    ['field' => 'register_price', 'label' => '注册价', 'type' => 'money'],
                    ['field' => 'renew_price', 'label' => '续费价', 'type' => 'money'],
                    ['field' => 'status', 'label' => '启用', 'type' => 'bool'],
                ],
                'fields' => [
                    ['name' => 'tld', 'label' => '后缀', 'type' => 'text', 'required' => true, 'help' => '不含点，如 com'],
                    ['name' => 'registrar', 'label' => '注册商', 'type' => 'select', 'options' => $registrars],
                    ['name' => 'register_price', 'label' => '注册价', 'type' => 'money'],
                    ['name' => 'renew_price', 'label' => '续费价', 'type' => 'money'],
                    ['name' => 'transfer_price', 'label' => '转入价', 'type' => 'money'],
                    ['name' => 'currency', 'label' => '货币', 'type' => 'select', 'options' => $currencies],
                    ['name' => 'sort', 'label' => '排序', 'type' => 'number'],
                    ['name' => 'status', 'label' => '启用', 'type' => 'checkbox'],
                ],
                'defaults' => ['status' => 1, 'currency' => 'CNY'],
            ],
            'canned_reply' => [
                'table' => 'canned_reply', 'title' => '预设回复', 'perm' => 'ticket', 'order' => 'id asc', 'search' => ['title'],
                'columns' => [
                    ['field' => 'id', 'label' => 'ID', 'width' => '60px'],
                    ['field' => 'title', 'label' => '标题'],
                    ['field' => 'message', 'label' => '内容', 'type' => 'excerpt'],
                ],
                'fields' => [
                    ['name' => 'title', 'label' => '标题', 'type' => 'text', 'required' => true],
                    ['name' => 'message', 'label' => '内容', 'type' => 'textarea', 'rows' => 6],
                ],
            ],
            'staff' => [
                'table' => 'admin', 'title' => '员工', 'perm' => 'staff', 'order' => 'id asc', 'search' => ['username', 'name', 'email'],
                'columns' => [
                    ['field' => 'id', 'label' => 'ID', 'width' => '60px'],
                    ['field' => 'username', 'label' => '用户名'],
                    ['field' => 'name', 'label' => '姓名'],
                    ['field' => 'email', 'label' => '邮箱'],
                    ['field' => 'role_id', 'label' => '角色', 'type' => 'map', 'options' => $roles],
                    ['field' => 'status', 'label' => '状态', 'type' => 'bool'],
                    ['field' => 'last_login_at', 'label' => '最后登录', 'type' => 'date'],
                ],
                'fields' => [
                    ['name' => 'username', 'label' => '用户名', 'type' => 'text', 'required' => true],
                    ['name' => 'password', 'label' => '密码', 'type' => 'password', 'required_on_create' => true, 'help' => '留空表示不修改'],
                    ['name' => 'name', 'label' => '姓名', 'type' => 'text'],
                    ['name' => 'email', 'label' => '邮箱', 'type' => 'text'],
                    ['name' => 'role_id', 'label' => '角色', 'type' => 'select', 'options' => $roles],
                    ['name' => 'status', 'label' => '启用', 'type' => 'checkbox'],
                ],
                'defaults' => ['status' => 1, 'role_id' => 2],
                'guard' => function ($id) {
                    $me = current_admin();
                    if ($me && (int)$me['id'] === $id) return '不能删除当前登录账户';
                    if ($id === 1) return '内置超级管理员不能删除';
                    return '';
                },
            ],
            'role' => [
                'table' => 'admin_role', 'title' => '角色', 'perm' => 'staff', 'order' => 'id asc', 'search' => ['name'],
                'columns' => [
                    ['field' => 'id', 'label' => 'ID', 'width' => '60px'],
                    ['field' => 'name', 'label' => '名称'],
                    ['field' => 'permissions', 'label' => '权限', 'type' => 'excerpt'],
                ],
                'fields' => [
                    ['name' => 'name', 'label' => '名称', 'type' => 'text', 'required' => true],
                    ['name' => 'permissions', 'label' => '权限（JSON 数组）', 'type' => 'code', 'rows' => 6,
                        'help' => '示例：["client.*","invoice.view"]，["*"] 表示全部'],
                ],
                'guard' => function ($id) {
                    if ($id === 1) return '内置角色不能删除';
                    return Db::table('admin')->where('role_id', $id)->exists() ? '该角色下仍有员工' : '';
                },
            ],
            'product_group' => [
                'table' => 'product_group', 'title' => '产品分组', 'perm' => 'product', 'order' => 'sort asc', 'search' => ['name'],
                'columns' => [
                    ['field' => 'id', 'label' => 'ID', 'width' => '60px'],
                    ['field' => 'name', 'label' => '名称'],
                    ['field' => 'slug', 'label' => 'Slug'],
                    ['field' => 'sort', 'label' => '排序'],
                    ['field' => 'hidden', 'label' => '隐藏', 'type' => 'bool'],
                ],
                'fields' => [
                    ['name' => 'name', 'label' => '名称', 'type' => 'text', 'required' => true],
                    ['name' => 'slug', 'label' => 'Slug', 'type' => 'text'],
                    ['name' => 'description', 'label' => '描述', 'type' => 'textarea'],
                    ['name' => 'sort', 'label' => '排序', 'type' => 'number'],
                    ['name' => 'hidden', 'label' => '前台隐藏', 'type' => 'checkbox'],
                ],
                'guard' => function ($id) {
                    return Db::table('product')->where('group_id', $id)->exists() ? '该分组下仍有产品' : '';
                },
            ],
            'server_group' => [
                'table' => 'server_group', 'title' => '服务器分组', 'perm' => 'server', 'order' => 'id asc', 'search' => ['name'],
                'columns' => [
                    ['field' => 'id', 'label' => 'ID', 'width' => '60px'],
                    ['field' => 'name', 'label' => '名称'],
                    ['field' => 'created_at', 'label' => '创建时间', 'type' => 'date'],
                ],
                'fields' => [['name' => 'name', 'label' => '名称', 'type' => 'text', 'required' => true]],
                'guard' => function ($id) {
                    return Db::table('server')->where('group_id', $id)->exists() ? '该分组下仍有服务器' : '';
                },
            ],
        ];
    }
}
