<?php

namespace TypechoPlugin\ApiPublish;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Typecho\Widget\Helper\Form\Element\Radio;
use TypechoPlugin\ApiPublish\Helper\Form\Element\ApiToken;
use Utils\Helper;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * API 自动发布插件
 *
 * 基于 XML-RPC 协议扩展，提供 AppID/AppSecret 凭据管理与外部文章同步能力；
 * 同时参考 moefront/typecho-plugin-Restful 提供 RESTful 风格的 URL 路由接口。
 *
 * @package ApiPublish
 * @author qoder
 * @version 1.1.0
 */
class Plugin implements PluginInterface
{
    /**
     * 路由名称前缀（用于 removeRoute 时按前缀批量清理）
     */
    const ROUTE_PREFIX = 'apipublish_restful_';

    /**
     * 激活插件：创建数据表 + 注册管理面板 + 注册 Action 端点 + 注册 RESTful 路由
     */
    public static function activate()
    {
        self::installTable();

        Helper::addPanel(
            3,
            'ApiPublish/manage-apipublish.php',
            _t('API 发布'),
            _t('管理 API 自动发布凭据与接入文档'),
            'administrator'
        );

        Helper::addAction('api-publish', 'ApiPublish_Action');

        // 注册 RESTful 路由（通过反射自动发现所有 xxxAction() 方法）
        foreach (self::discoverRestfulRoutes() as $route) {
            Helper::addRoute(
                self::ROUTE_PREFIX . $route['shortName'],
                $route['uri'],
                'ApiPublish_Action',
                $route['action']
            );
        }
    }

    /**
     * 停用插件：清理 Action、面板与 RESTful 路由
     */
    public static function deactivate()
    {
        Helper::removeAction('api-publish');
        Helper::removePanel(3, 'ApiPublish/manage-apipublish.php');

        // 按前缀清理全部 RESTful 路由
        try {
            $routingTable = Helper::options()->routingTable;
            if (is_array($routingTable)) {
                foreach (array_keys($routingTable) as $key) {
                    if (is_string($key) && strpos($key, self::ROUTE_PREFIX) === 0) {
                        Helper::removeRoute($key);
                    }
                }
            }
        } catch (\Throwable $e) {
            // 路由表读取失败时忽略
        }
    }

    /**
     * 全局配置面板
     */
    public static function config(Form $form)
    {
        /** 默认发布作者(当外部系统未指定作者时使用) */
        $users = self::fetchUserOptions();
        $defaultAuthor = new Select(
            'defaultAuthor',
            $users,
            self::firstAdminUid(),
            _t('默认发布作者')
        );
        $form->addInput($defaultAuthor);

        /** 默认文章状态 */
        $statusOptions = [
            'publish' => _t('直接发布'),
            'draft'   => _t('保存为草稿'),
            'waiting' => _t('待审核'),
            'private' => _t('私密文章'),
        ];
        $defaultStatus = new Select(
            'defaultStatus',
            $statusOptions,
            'publish',
            _t('默认文章状态')
        );
        $form->addInput($defaultStatus);

        /** 默认分类(可空) */
        $defaultCategory = new Text(
            'defaultCategory',
            null,
            '',
            _t('默认分类')
        );
        $form->addInput($defaultCategory);

        /** 内容过滤钩子 */
        $filterHook = new Radio(
            'filterHook',
            [
                'on'  => _t('启用（推荐）'),
                'off' => _t('关闭'),
            ],
            'on',
            _t('内容安全过滤')
        );
        $form->addInput($filterHook);

        /** Markdown 开关 */
        $markdown = new Radio(
            'markdown',
            [
                '1' => _t('启用（按 Markdown 解析）'),
                '0' => _t('关闭（按 HTML 处理）'),
            ],
            '1',
            _t('Markdown 支持')
        );
        $form->addInput($markdown);

        /** 调用频率提示 */
        $rateInfo = new Textarea(
            'rateInfo',
            null,
            '60',
            _t('单凭据每分钟调用上限')
        );
        $form->addInput($rateInfo);

        /* ===========================================================
         * RESTful API 配置（参考 moefront/typecho-plugin-Restful）
         * ===========================================================*/

        /** RESTful 总开关 */
        $restfulEnabled = new Radio(
            'restfulEnabled',
            [
                'on'  => _t('启用'),
                'off' => _t('关闭'),
            ],
            'on',
            _t('RESTful 接口总开关')
        );
        $form->addInput($restfulEnabled);

        /** RESTful URL 前缀 */
        $restfulPrefix = new Text(
            'restfulPrefix',
            null,
            '/api/',
            _t('RESTful 路由前缀')
        );
        $form->addInput($restfulPrefix);

        /** CORS 跨域白名单 */
        $corsOrigin = new Textarea(
            'corsOrigin',
            null,
            '',
            _t('CORS 跨域白名单')
        );
        $form->addInput($corsOrigin);

        /** API Token（可选，与 AppID/AppSecret 并行） */
        $apiToken = new ApiToken(
            'apiToken',
            null,
            '',
            _t('API Token（可选）')
        );
        $form->addInput($apiToken);

        /** CSRF 加密盐 */
        $restfulCsrfSalt = new Text(
            'restfulCsrfSalt',
            null,
            'apipublish_' . substr(md5((string)time()), 0, 16),
            _t('RESTful CSRF 加密盐')
        );
        $form->addInput($restfulCsrfSalt);

        /** 写接口是否要求后台登录 */
        $validateLogin = new Radio(
            'validateLogin',
            [
                '0' => _t('否（仅靠 AppID/AppSecret 鉴权）'),
                '1' => _t('是（必须同时携带后台 Cookie）'),
            ],
            '0',
            _t('RESTful 写接口是否要求登录')
        );
        $form->addInput($validateLogin);

        /** 每接口独立开关（通过反射自动列出所有 RESTful 接口） */
        $routes = self::discoverRestfulRoutes();
        if (!empty($routes)) {
            echo '<h3>' . _t('RESTful 接口独立开关') . '</h3>';
            foreach ($routes as $route) {
                $state = new Radio(
                    'rest_' . $route['shortName'],
                    [
                        '0' => _t('禁用'),
                        '1' => _t('启用'),
                    ],
                    '1',
                    $route['uri']
                );
                $form->addInput($state);
            }
        }

        // 为 API Token 输入框挂接"生成 Token"与"眼睛切换"交互脚本
        echo self::renderApiTokenScript();

        // 插件说明
        echo '<div style="margin-top: 24px; padding: 16px; background: #f8f9fa; border-left: 4px solid #2271b1;">';
        echo '<h4 style="margin: 0 0 8px 0;">' . _t('插件说明') . '</h4>';
        echo '<p style="margin: 0;">';
        echo _t('本插件基于 Typecho XML-RPC 协议扩展，提供 AppID/AppSecret 凭据管理与外部文章同步能力；同时支持 RESTful 风格的 URL 路由接口。');
        echo '</p>';
        echo '<p style="margin: 8px 0 0 0;">';
        echo '<a href="https://github.com/noonhorse/typecho-Resetful-Api" target="_blank" rel="noopener">';
        echo _t('GitHub 项目主页') . '</a>';
        echo '</p>';
        echo '</div>';
    }

    /**
     * 生成 API Token 输入控件的交互脚本
     *
     * 该脚本在后台配置页加载时被输出，会绑定 data-role="apipublish-token-*" 的按钮。
     */
    private static function renderApiTokenScript(): string
    {
        $genConfirm = _t('已生成新 Token，原 Token 将被覆盖。确定要继续吗？');
        $hideTitle  = _t('隐藏 Token');
        $showTitle  = _t('显示 Token');
        $maskPlaceholder = _t('点击右侧眼睛图标查看完整 Token');

        return <<<JS
<script type="text/javascript">
(function () {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    // 生成随机 Token（64 位十六进制）
    function generateToken() {
        var buf = new Uint8Array(32);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(buf);
        } else {
            for (var i = 0; i < buf.length; i++) { buf[i] = Math.floor(Math.random() * 256); }
        }
        var hex = '';
        for (var j = 0; j < buf.length; j++) {
            hex += (buf[j] < 16 ? '0' : '') + buf[j].toString(16);
        }
        return hex;
    }

    // 同步 data-token 与实际展示
    function refreshInput(input) {
        if (!input) { return; }
        var token = input.getAttribute('data-token') || '';
        var masked = input.getAttribute('data-masked') === '1';
        if (token === '') {
            input.value = '';
            input.setAttribute('placeholder', '{$maskPlaceholder}');
            return;
        }
        if (masked) {
            var head = token.substr(0, 4);
            var tail = token.substr(-4);
            input.value = head + '********' + tail;
            input.setAttribute('type', 'text');
        } else {
            input.value = token;
            input.setAttribute('type', 'text');
        }
    }

    function setEyeState(btnEye, masked) {
        btnEye.setAttribute('title', masked ? '{$showTitle}' : '{$hideTitle}');
        var eyeOpen  = btnEye.querySelector('[data-role="apipublish-token-eye-open"]');
        var eyeClose = btnEye.querySelector('[data-role="apipublish-token-eye-close"]');
        if (eyeOpen && eyeClose) {
            eyeOpen.style.display  = masked ? 'inline' : 'none';
            eyeClose.style.display = masked ? 'none' : 'inline';
        }
    }

    function bind(input) {
        var toolbar = input.parentNode.querySelector('[data-role="apipublish-token-toolbar"]');
        if (!toolbar) { return; }
        var btnGen = toolbar.querySelector('[data-role="apipublish-token-generate"]');
        var btnEye = toolbar.querySelector('[data-role="apipublish-token-toggle"]');
        if (!btnGen || !btnEye) { return; }

        // 点击生成按钮
        btnGen.addEventListener('click', function (e) {
            e.preventDefault();
            if (input.getAttribute('data-token') && !window.confirm('{$genConfirm}')) {
                return;
            }
            var t = generateToken();
            input.setAttribute('data-token', t);
            input.setAttribute('data-masked', '0');
            refreshInput(input);
            setEyeState(btnEye, false);
        });

        // 点击眼睛切换
        btnEye.addEventListener('click', function (e) {
            e.preventDefault();
            var masked = input.getAttribute('data-masked') === '1';
            input.setAttribute('data-masked', masked ? '0' : '1');
            refreshInput(input);
            setEyeState(btnEye, !masked);
        });

        // 初始：若已存在 Token 则保持掩码状态
        if (input.getAttribute('data-token')) {
            input.setAttribute('data-masked', '1');
            refreshInput(input);
            setEyeState(btnEye, true);
        } else {
            setEyeState(btnEye, true);
        }
    }

    function init() {
        $$('input[data-role="apipublish-token-input"]').forEach(bind);
    }

    // form 提交前：将 ApiToken input 的 value 同步为完整 token，避免提交掩码
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') { return; }
        $$('input[data-role="apipublish-token-input"]', form).forEach(function (input) {
            var token = input.getAttribute('data-token') || '';
            if (token !== '') {
                input.value = token;
            }
        });
    }, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
JS;
    }

    /**
     * 个人用户配置面板
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 安装/初始化数据表
     */
    public static function installTable()
    {
        $db = \Typecho\Db::get();
        $type = explode('_', $db->getAdapterName());
        $type = array_pop($type);
        $prefix = $db->getPrefix();

        $sqlFile = __TYPECHO_ROOT_DIR__ . '/usr/plugins/ApiPublish/' . $type . '.sql';
        if (!is_file($sqlFile)) {
            return _t('未找到数据表初始化脚本，跳过建表');
        }

        $scripts = file_get_contents($sqlFile);
        $scripts = str_replace(['%prefix%', '%engine%'], [$prefix, 'InnoDB'], $scripts);
        $statements = array_filter(array_map('trim', explode(';', $scripts)));

        try {
            foreach ($statements as $script) {
                if ($script !== '') {
                    $db->query($script, \Typecho\Db::WRITE);
                }
            }
            return _t('API 发布插件数据表已就绪');
        } catch (\Typecho\Db\Exception $e) {
            $code = $e->getCode();
            // 表已存在
            if (($type === 'Mysql' && (1050 === $code || $code === '42S01'))
                || ($type === 'SQLite' && ($code === 'HY000' || $code === 1))) {
                return _t('检测到 API 发布数据表，插件启用成功');
            }
            throw new \Typecho\Plugin\Exception(_t('数据表建立失败，错误号：%s', $code));
        }
    }

    /**
     * 获取所有可作为默认作者的用户
     *
     * @return array
     */
    private static function fetchUserOptions(): array
    {
        try {
            $db = \Typecho\Db::get();
            $rows = $db->fetchAll($db->select('uid', 'screenName', 'name')->from('table.users'));
        } catch (\Throwable $e) {
            return [];
        }

        $options = [];
        foreach ($rows as $row) {
            $label = !empty($row['screenName']) ? $row['screenName'] : $row['name'];
            $options[(int)$row['uid']] = $label . ' (#' . $row['uid'] . ')';
        }
        return $options;
    }

    /**
     * 获取首个管理员 uid（用作默认作者兜底）
     */
    private static function firstAdminUid(): int
    {
        try {
            $db = \Typecho\Db::get();
            $row = $db->fetchRow(
                $db->select('uid')->from('table.users')
                    ->where('group = ?', 'administrator')
                    ->order('uid', \Typecho\Db::SORT_ASC)
                    ->limit(1)
            );
            if ($row && isset($row['uid'])) {
                return (int)$row['uid'];
            }
        } catch (\Throwable $e) {
        }
        return 1;
    }

    /**
     * 通过反射发现 Action.php 中所有 xxxAction() 方法
     *
     * 该方法只读取方法签名与文档注释，不会实际调用方法。
     *
     * @return array<int, array{action:string,name:string,shortName:string,uri:string,description:string,method:string}>
     */
    public static function discoverRestfulRoutes(): array
    {
        $actionFile = __TYPECHO_ROOT_DIR__ . '/usr/plugins/ApiPublish/Action.php';
        if (!is_file($actionFile)) {
            return [];
        }

        // 必须在不重复定义的前提下拿到反射信息——Action.php 是基于 widget 流程按需加载的，
        // 这里仅做静态分析：正则扫描所有形如 `xxxAction(` 的方法。
        $source = file_get_contents($actionFile);
        if ($source === false) {
            return [];
        }

        $prefix = '/api/';
        $routes = [];
        if (preg_match_all(
            '/function\s+([a-zA-Z][a-zA-Z0-9_]*Action)\s*\(/',
            $source,
            $matches
        )) {
            foreach ($matches[1] as $methodName) {
                $shortName = substr($methodName, 0, -6); // 去掉 Action
                // 仅保留合法的接口短名
                if ($shortName === '' || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $shortName)) {
                    continue;
                }
                $routes[] = [
                    'action'      => $methodName,
                    'name'        => self::ROUTE_PREFIX . $shortName,
                    'shortName'   => $shortName,
                    'uri'         => $prefix . $shortName,
                    'description' => self::extractMethodDocComment($source, $methodName),
                    'method'      => self::extractMethodHttpMethod($source, $methodName),
                ];
            }
        }

        // 去重 + 按 shortName 排序
        $seen = [];
        $unique = [];
        foreach ($routes as $r) {
            if (isset($seen[$r['shortName']])) {
                continue;
            }
            $seen[$r['shortName']] = true;
            $unique[] = $r;
        }
        usort($unique, function ($a, $b) {
            return strcmp($a['shortName'], $b['shortName']);
        });
        return $unique;
    }

    /**
     * 从源码中提取方法上方的 PHPDoc 注释
     */
    private static function extractMethodDocComment(string $source, string $methodName): string
    {
        $pattern = '/\/\*\*([\s\S]*?)\*\/\s*(?:public|protected|private)?\s*function\s+' . preg_quote($methodName, '/') . '\s*\(/';
        if (!preg_match($pattern, $source, $m)) {
            return '';
        }
        $raw = $m[1];
        // 去掉每行开头的 * 和首尾空白
        $lines = preg_split('/\r?\n/', $raw);
        $clean = [];
        foreach ($lines as $line) {
            $line = ltrim($line);
            $line = (strpos($line, '*') === 0) ? ltrim(substr($line, 1)) : $line;
            $line = trim($line);
            if ($line === '' || strpos($line, '@') === 0) {
                continue;
            }
            $clean[] = $line;
        }
        return implode(' ', $clean);
    }

    /**
     * 从源码中推断方法的 HTTP 方法（通过 lockMethod('get'/'post') 调用）
     */
    private static function extractMethodHttpMethod(string $source, string $methodName): string
    {
        // 取方法体的前 50 行做扫描
        $pattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*\{([\s\S]{0,2000})\}/';
        if (!preg_match($pattern, $source, $m)) {
            return 'GET';
        }
        if (stripos($m[1], "lockMethod('get'") !== false || stripos($m[1], 'lockMethod("get"') !== false) {
            return 'GET';
        }
        if (stripos($m[1], "lockMethod('post'") !== false || stripos($m[1], 'lockMethod("post"') !== false) {
            return 'POST';
        }
        return 'GET';
    }
}