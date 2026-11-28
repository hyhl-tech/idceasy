<?php
/**
 * idceasy - 模板引擎
 * 前台与后台模板全部为 .htm，支持：
 *   {$var} {$var.key} {:raw} {if} {elseif} {else} {/if}
 *   {loop $list as $item} {loop $list as $k => $v} {/loop}
 *   {include header} {csrf} {func($arg)}
 * 模板由用户直接编辑，修改后自动重新编译。
 */
namespace think;

class View
{
    protected static array $compiling = [];

    public static function exists(string $template, string $theme = 'default'): bool
    {
        return is_file(self::resolve($template, $theme));
    }

    public static function resolve(string $template, string $theme = 'default'): string
    {
        $template = str_replace(['..', '\\'], '', $template);
        if (!str_ends_with($template, '.htm')) $template .= '.htm';
        $theme = preg_replace('/[^a-zA-Z0-9_\-]/', '', $theme) ?: 'default';
        $file = BASE_PATH . '/template/' . $theme . '/' . ltrim($template, '/');
        if (!is_file($file)) {
            $file = BASE_PATH . '/template/default/' . ltrim($template, '/');
        }
        return $file;
    }

    public static function render(string $templateName, array $__data = [], string $themeName = 'default'): string
    {
        $__file = self::resolve($templateName, $themeName);
        if (!is_file($__file)) {
            throw new \RuntimeException('模板不存在: ' . $templateName . '（主题 ' . $themeName . '）');
        }
        $__compiled = self::compile($__file, $themeName);
        $__cacheDir = DATA_PATH . '/cache/views';
        if (!is_dir($__cacheDir)) @mkdir($__cacheDir, 0755, true);
        $__cacheFile = $__cacheDir . '/' . md5($__file . '|' . $themeName . '|' . self::themeVersion($themeName, $__file)) . '.php';
        if (!is_file($__cacheFile)) {
            $__tmp = $__cacheFile . '.' . getmypid() . '.tmp';
            file_put_contents($__tmp, $__compiled, LOCK_EX);
            @rename($__tmp, $__cacheFile);
        }
        extract($__data, EXTR_SKIP);
        ob_start();
        try {
            include $__cacheFile;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return ob_get_clean();
    }

    protected static function themeVersion(string $theme, string $file): string
    {
        static $versions = [];
        if (isset($versions[$theme])) return $versions[$theme];
        $max = (int)@filemtime($file);
        $dir = BASE_PATH . '/template/' . $theme;
        if (is_dir($dir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), '.htm')) {
                    $max = max($max, (int)$f->getMTime());
                }
            }
        }
        $versions[$theme] = (string)$max;
        return $versions[$theme];
    }

    protected static function compile(string $file, string $theme): string
    {
        if (isset(self::$compiling[$file])) return '';
        self::$compiling[$file] = true;
        $html = (string)file_get_contents($file);

        // 注释
        $html = preg_replace('/\{--.*?--\}/s', '', $html);

        // 引入（编译期内联，包含文件同样支持全部语法）
        $html = preg_replace_callback('/\{include\s+([a-zA-Z0-9_\-\/]+)\}/', function ($m) use ($theme) {
            $inc = self::resolve($m[1], $theme);
            if (!is_file($inc)) return '<!-- include missing: ' . htmlspecialchars($m[1]) . ' -->';
            return self::compile($inc, $theme);
        }, $html);

        // CSRF
        $html = str_replace('{csrf}', '<?= csrf_field() ?>', $html);

        // 循环
        $html = preg_replace_callback('/\{loop\s+(.+?)\s+as\s+(\$[a-zA-Z_]\w*)(?:\s*=>\s*(\$[a-zA-Z_]\w*))?\}/', function ($m) {
            $source = self::expr(trim($m[1]));
            $first = $m[2];
            $second = $m[3] ?? '';
            $as = $second !== '' ? $first . ' => ' . $second : $first;
            return '<?php foreach((' . $source . ') ?? [] as ' . $as . '): ?>';
        }, $html);
        $html = str_replace('{/loop}', '<?php endforeach; ?>', $html);

        // 条件
        $html = preg_replace_callback('/\{if\s+(.+?)\}/', function ($m) {
            return '<?php if(' . self::expr(trim($m[1])) . '): ?>';
        }, $html);
        $html = preg_replace_callback('/\{elseif\s+(.+?)\}/', function ($m) {
            return '<?php elseif(' . self::expr(trim($m[1])) . '): ?>';
        }, $html);
        $html = str_replace('{else}', '<?php else: ?>', $html);
        $html = str_replace('{/if}', '<?php endif; ?>', $html);

        // 原始表达式
        $html = preg_replace_callback('/\{:(.+?)\}/s', function ($m) {
            return '<?= ' . self::expr(trim($m[1])) . ' ?>';
        }, $html);

        // 变量输出 {$a.b|raw}
        $html = preg_replace_callback('/\{\$([^}]+)\}/', function ($m) {
            $code = '$' . trim($m[1]);
            $raw = false;
            if (str_contains($code, '|raw')) {
                $raw = true;
                $code = str_replace('|raw', '', $code);
            }
            $php = self::expr($code);
            return $raw ? '<?= ' . $php . ' ?>' : '<?= e(' . $php . ') ?>';
        }, $html);

        // 函数调用 {money($x)}
        $allowed = 'url|asset|cfg|money|e|date|number_format|count|round|floor|ceil|strtoupper|strtolower|ucfirst|substr|nl2br|implode|in_array|empty|isset|max|min|abs|intval|floatval|json_encode|htmlspecialchars|str_replace|trim|sprintf|time|format_bytes|cycle_text|status_text|first_char|mask_email|http_build_query|paginate_links|abs_url|admin_url|option_label|strtotime|number_format|arr_get|nl2br|strip_tags|mb_substr|date_format_str';
        $html = preg_replace_callback('/\{(' . $allowed . ')\(([^{}]*)\)\}/', function ($m) {
            return '<?= e(' . $m[1] . '(' . self::expr($m[2]) . ')) ?>';
        }, $html);

        unset(self::$compiling[$file]);
        return $html;
    }

    /** 将 $a.b.c 转为 ($a['b']['c'] ?? null) */
    protected static function expr(string $code): string
    {
        return preg_replace_callback('/\$([a-zA-Z_]\w*)((?:\.[a-zA-Z_]\w*)+)/', function ($m) {
            $keys = explode('.', ltrim($m[2], '.'));
            $out = '$' . $m[1];
            foreach ($keys as $k) $out .= "['" . $k . "']";
            return '(' . $out . ' ?? null)';
        }, $code);
    }
}
