<?php
/**
 * idceasy - 控制器基类（ThinkPHP 风格）
 */
namespace think;

class Controller
{
    protected string $theme = 'default';
    protected array $assign = [];
    protected Request $request;

    public function __construct()
    {
        $this->request = Request::instance();
    }

    protected function assign($name, $value = null): void
    {
        if (is_array($name)) $this->assign = array_merge($this->assign, $name);
        else $this->assign[$name] = $value;
    }

    protected function fetch(string $template, array $data = []): Response
    {
        $vars = array_merge($this->assign, $this->commonVars(), $data);
        return Response::html(View::render($template, $vars, $this->theme));
    }

    /** 兼容写法：view() 与 fetch() 等价 */
    protected function view(string $template, array $data = []): Response
    {
        return $this->fetch($template, $data);
    }

    protected function commonVars(): array
    {
        return [
            'title'    => '',
            'rowLinks' => [],
            'rowDelete'=> false,
            'deleteUrl'=> '',
            'filters'  => [],
            'tabs'     => [],
            'topActions'=> [],
            'baseUrl'  => '',
            'note'     => '',
            'user'     => current_user(),
            'admin'    => current_admin(),
            'flashOk'  => Session::pull('ok'),
            'flashErr' => Session::pull('error'),
            'theme'    => $this->theme,
            'siteName' => cfg('site_name', 'idceasy'),
            'siteUrl'  => rtrim(cfg('site_url', ''), '/'),
            'cartCount'=> cart_count(),
            'year'     => date('Y'),
            'menu'     => nav_menu(),
        ];
    }

    protected function json($data, int $code = 200): Response
    {
        return Response::json($data, $code);
    }

    protected function success(string $msg = '操作成功', string $url = ''): Response
    {
        if ($this->request->isAjax()) return $this->json(['ok' => true, 'msg' => $msg, 'url' => $url]);
        Session::flash('ok', $msg);
        return Response::redirect($url ?: ($_SERVER['HTTP_REFERER'] ?? url('/')));
    }

    protected function error(string $msg = '操作失败', string $url = ''): Response
    {
        if ($this->request->isAjax()) return $this->json(['ok' => false, 'msg' => $msg], 422);
        Session::flash('error', $msg);
        return Response::redirect($url ?: ($_SERVER['HTTP_REFERER'] ?? url('/')));
    }

    protected function redirect(string $url): Response
    {
        return Response::redirect($url);
    }

    protected function back(): Response
    {
        return Response::redirect($_SERVER['HTTP_REFERER'] ?? url('/'));
    }

    protected function verifyCsrf(): bool
    {
        return Session::verify($this->request->param('_token'));
    }

    protected function requirePost(): void
    {
        if (!$this->request->isPost()) throw new \RuntimeException('仅支持 POST 请求', 405);
    }

    /** 取分页参数 */
    protected function pageNo(): int
    {
        return max(1, $this->request->int('page', 1));
    }
}
