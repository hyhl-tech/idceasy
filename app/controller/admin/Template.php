<?php
/**
 * 后台：模板文件在线编辑（全部 .htm）
 */
namespace app\controller\admin;

use think\Db;
use think\Response;

class Template extends Base
{
    protected string $perm = 'template';

    public function index(): Response
    {
        $themes = $this->themes();
        $theme = (string)$this->request->param('theme', cfg('theme', 'default'));
        if (!in_array($theme, $themes, true)) $theme = 'default';
        $files = [];
        $base = BASE_PATH . '/template/' . $theme;
        foreach ($this->scan($base) as $rel) {
            $files[] = [
                'name' => $rel,
                'size' => filesize($base . '/' . $rel),
                'mtime' => date('Y-m-d H:i', filemtime($base . '/' . $rel)),
            ];
        }
        usort($files, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $this->view('template_list', compact('themes', 'theme', 'files'));
    }

    public function edit(): Response
    {
        $theme = $this->safeTheme((string)$this->request->param('theme', cfg('theme', 'default')));
        $file = $this->safeFile((string)$this->request->param('file', ''));
        $path = BASE_PATH . '/template/' . $theme . '/' . $file;
        if (!is_file($path)) return $this->error('模板文件不存在', admin_url('/templates?theme=' . $theme));
        $content = (string)file_get_contents($path);
        return $this->view('template_edit', compact('theme', 'file', 'content'));
    }

    public function save(): Response
    {
        if (!$this->request->isPost()) return $this->redirect(admin_url('/templates'));
        $theme = $this->safeTheme((string)$this->request->param('theme'));
        $file = $this->safeFile((string)$this->request->param('file'));
        $content = (string)$this->request->param('content');
        if (strlen($content) > 2 * 1024 * 1024) return $this->error('文件过大');
        $path = BASE_PATH . '/template/' . $theme . '/' . $file;
        if (!is_file($path)) return $this->error('模板文件不存在');
        $this->backup($theme, $file, $path);
        if (file_put_contents($path, $content, LOCK_EX) === false) return $this->error('写入失败，请检查目录权限');
        $this->clearCache();
        $this->logAction('编辑模板', ['theme' => $theme, 'file' => $file]);
        return $this->success('模板已保存', admin_url('/templates/edit?theme=' . $theme . '&file=' . urlencode($file)));
    }

    public function create(): Response
    {
        if (!$this->request->isPost()) return $this->redirect(admin_url('/templates'));
        $theme = $this->safeTheme((string)$this->request->param('theme'));
        $file = $this->safeFile((string)$this->request->param('file'));
        $path = BASE_PATH . '/template/' . $theme . '/' . $file;
        if (is_file($path)) return $this->error('文件已存在');
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (file_put_contents($path, (string)$this->request->param('content', "<!-- new template -->\n")) === false) {
            return $this->error('创建失败，请检查目录权限');
        }
        $this->logAction('新建模板', ['theme' => $theme, 'file' => $file]);
        return $this->success('文件已创建', admin_url('/templates/edit?theme=' . $theme . '&file=' . urlencode($file)));
    }

    public function delete(): Response
    {
        if (!$this->request->isPost()) return $this->redirect(admin_url('/templates'));
        $theme = $this->safeTheme((string)$this->request->param('theme'));
        $file = $this->safeFile((string)$this->request->param('file'));
        $path = BASE_PATH . '/template/' . $theme . '/' . $file;
        if (!is_file($path)) return $this->error('文件不存在');
        $this->backup($theme, $file, $path);
        @unlink($path);
        $this->clearCache();
        $this->logAction('删除模板', ['theme' => $theme, 'file' => $file]);
        return $this->success('已删除（备份保留在 data/backups/templates）', admin_url('/templates?theme=' . $theme));
    }

    public function switch(): Response
    {
        if (!$this->request->isPost()) return $this->redirect(admin_url('/templates'));
        $theme = $this->safeTheme((string)$this->request->param('theme'));
        cfg_set('theme', $theme);
        $this->clearCache();
        $this->logAction('切换主题', ['theme' => $theme]);
        return $this->success('主题已切换', admin_url('/templates?theme=' . $theme));
    }

    protected function themes(): array
    {
        $themes = [];
        foreach ((array)glob(BASE_PATH . '/template/*', GLOB_ONLYDIR) as $dir) {
            $themes[] = basename($dir);
        }
        return $themes ?: ['default'];
    }

    protected function safeTheme(string $theme): string
    {
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $theme) || !is_dir(BASE_PATH . '/template/' . $theme)) {
            throw new \think\HttpException('无效主题', 404);
        }
        return $theme;
    }

    protected function safeFile(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        if ($file === '' || str_contains($file, '..') || !preg_match('#^[a-zA-Z0-9_\-/]+\.htm$#', $file)) {
            throw new \think\HttpException('无效文件名，只允许 .htm', 404);
        }
        return $file;
    }

    protected function scan(string $dir, string $prefix = ''): array
    {
        $out = [];
        foreach ((array)glob($dir . '/*') as $path) {
            $name = basename($path);
            if (is_dir($path)) {
                $out = array_merge($out, $this->scan($path, $prefix . $name . '/'));
            } elseif (str_ends_with($name, '.htm')) {
                $out[] = $prefix . $name;
            }
        }
        return $out;
    }

    protected function backup(string $theme, string $file, string $path): void
    {
        $dir = DATA_PATH . '/backups/templates/' . $theme . '/' . dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @copy($path, $dir . '/' . basename($file) . '.' . date('YmdHis') . '.bak');
    }

    protected function clearCache(): void
    {
        foreach ((array)glob(DATA_PATH . '/cache/views/*.php') as $file) @unlink($file);
    }
}
