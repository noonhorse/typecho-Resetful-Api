<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

use Typecho\Db;
use Typecho\Plugin as TypechoPlugin;
use Typecho\Widget as TypechoWidget;
use Widget\ActionInterface;
use Widget\Base\Contents as BaseContents;
use Widget\Base\Comments as BaseComments;
use Widget\Contents\Post\Edit as PostEdit;
use Widget\Options as WidgetOptions;
use Widget\User as WidgetUser;

/**
 * 接口说明
 * 提供两套接口：
 *
 * 1. 传统 Action 接口（通过 ?do=xxx 访问，保持向后兼容）：
 *    - ?do=test           验证凭据
 *    - ?do=getInfo        凭据使用情况
 *    - ?do=getCategories  分类列表
 *    - ?do=newPost        发布文章
 *    - ?do=editPost       编辑文章
 *    - ?do=deletePost     删除文章
 *    - ?do=admin*         后台凭据管理
 *
 * 2. RESTful 路由接口（通过 /api/xxx 访问，参考 moefront/typecho-plugin-Restful）：
 *    - GET  /api/posts              文章列表
 *    - GET  /api/post               单篇文章
 *    - GET  /api/pages              页面列表
 *    - GET  /api/categories         分类列表
 *    - GET  /api/tags               标签列表
 *    - GET  /api/recentComments     最新评论
 *    - GET  /api/comments           文章评论
 *    - GET  /api/archives           归档
 *    - GET  /api/userList           用户列表
 *    - GET  /api/users              用户详情
 *    - GET  /api/settings           站点设置
 *    - GET  /api/getRoutes          路由自描述
 *    - POST /api/postArticle        发布文章
 *    - POST /api/editArticle        编辑文章
 *    - POST /api/deleteArticle      删除文章
 *    - POST /api/addMetas           新增分类/标签
 */



/**
 * API 自动发布 Action 控制器
 *
 * @package ApiPublish
 */
class ApiPublish_Action extends TypechoWidget implements ActionInterface
{
    /** @var Db */
    private $db;

    /** @var string 数据表前缀 */
    private $prefix;

    /** @var array 当前插件配置 */
    private $config;

    /** @var array|null 命中凭据行 */
    private $credential;

    /** @var int 凭据命中起始时间戳（用于速率限制） */
    private $rateStarted = 0;

    /** @var int 凭据命中窗口内累计调用次数 */
    private $rateCount = 0;

    /** Action 分发入口 */
    public function action()
    {
        $this->boot();

        $this->security->enable(false);

        // 外部 API 端点
        $this->on($this->request->is('do=test'))->doTest();
        $this->on($this->request->is('do=getInfo'))->doGetInfo();
        $this->on($this->request->is('do=getCategories'))->doGetCategories();
        $this->on($this->request->is('do=newPost'))->doNewPost();
        $this->on($this->request->is('do=editPost'))->doEditPost();
        $this->on($this->request->is('do=deletePost'))->doDeletePost();

        // 管理员后台接口
        $this->on($this->request->is('do=adminInsert'))->adminInsert();
        $this->on($this->request->is('do=adminUpdate'))->adminUpdate();
        $this->on($this->request->is('do=adminDelete'))->adminDelete();
        $this->on($this->request->is('do=adminToggle'))->adminToggle();
        $this->on($this->request->is('do=adminBatchToggle'))->adminBatchToggle();
        $this->on($this->request->is('do=adminReset'))->adminResetSecret();
        $this->on($this->request->is('do=adminGenerate'))->adminGenerateSecret();

        $this->doDefault();
    }

    /**
     * 初始化控制器公共资源（基类 init() 为 protected，故改为其他名字）
     */
    private function boot(): void
    {
        $this->db = Db::get();
        $this->prefix = $this->db->getPrefix();
        $this->config = WidgetOptions::alloc()->plugin('ApiPublish') ?? [];
    }

    /** 未匹配的 do 返回 404 */
    private function doDefault()
    {
        $this->respondError(404, 'unknown_method', _t('未知接口方法，请参考 API 文档'));
    }

    /**
     * 测试连通性
     */
    public function doTest()
    {
        $cred = $this->requireCredential();
        $this->respondSuccess([
            'ok'         => true,
            'message'    => _t('凭据验证成功'),
            'credential' => $this->summarizeCredential($cred),
            'server'     => [
                'site'     => $this->options->siteUrl,
                'title'    => $this->options->title,
                'apiEntry' => $this->options->siteUrl . 'index.php/action/api-publish',
                'xmlrpc'   => $this->options->siteUrl . 'index.php/action/xmlrpc',
            ],
        ]);
    }

    /** 凭据使用情况 */
    public function doGetInfo()
    {
        $cred = $this->requireCredential();
        $this->respondSuccess([
            'credential' => $this->summarizeCredential($cred),
            'rateLimit'  => [
                'windowSeconds' => 60,
                'limit'         => (int)($this->config['rateInfo'] ?? 60),
                'current'       => $this->rateCount,
                'windowStart'   => $this->rateStarted,
            ],
        ]);
    }

    /** 获取分类 */
    public function doGetCategories()
    {
        $this->requireCredential();

        $rows = $this->db->fetchAll(
            $this->db->select('mid', 'name', 'slug', 'description', 'parent', 'count')
                ->from($this->prefix . 'metas')
                ->where('type = ?', 'category')
                ->order($this->prefix . 'metas.order', Db::SORT_ASC)
        );

        $categories = [];
        foreach ($rows as $row) {
            $categories[] = [
                'mid'         => (int)$row['mid'],
                'name'        => $row['name'],
                'slug'        => $row['slug'],
                'description' => $row['description'],
                'parent'      => (int)$row['parent'],
                'count'       => (int)$row['count'],
            ];
        }

        $this->respondSuccess([
            'categories' => $categories,
            'count'      => count($categories),
        ]);
    }

    /** 发布新文章 */
    public function doNewPost()
    {
        $cred = $this->requireCredential();
        $input = $this->collectPostInput();
        $input['do'] = 'publish';
        $input['markdown'] = (string)($this->config['markdown'] ?? '1');

        $input['uid'] = $this->resolveAuthorUid($input, $cred);

        try {
            $widget = PostEdit::alloc(null, $input, function (PostEdit $post) {
                $post->writePost();
            });
        } catch (\Throwable $e) {
            $this->respondError(500, 'publish_failed', $e->getMessage());
        }

        $this->respondSuccess([
            'cid'     => (int)$widget->cid,
            'title'   => $widget->title,
            'status'  => $widget->status,
            'type'    => $widget->type,
            'created' => (int)$widget->created,
        ]);
    }

    /** 编辑文章 */
    public function doEditPost()
    {
        $cred = $this->requireCredential();
        $cid = (int)$this->request->get('cid');
        if ($cid <= 0) {
            $this->respondError(400, 'invalid_cid', _t('编辑接口必须传入正确的文章 cid'));
        }

        $row = $this->db->fetchRow(
            $this->db->select('cid', 'type', 'authorId', 'status')
                ->from($this->prefix . 'contents')
                ->where('cid = ?', $cid)
        );
        if (!$row) {
            $this->respondError(404, 'post_not_found', _t('目标文章不存在'));
        }

        $input = $this->collectPostInput();
        $input['cid'] = $cid;
        $input['do'] = 'publish';
        $input['markdown'] = (string)($this->config['markdown'] ?? '1');
        $input['uid'] = $this->resolveAuthorUid($input, $cred);

        try {
            $widget = PostEdit::alloc(null, $input, function (PostEdit $post) {
                $post->writePost();
            });
        } catch (\Throwable $e) {
            $this->respondError(500, 'edit_failed', $e->getMessage());
        }

        $this->respondSuccess([
            'cid'    => (int)$widget->cid,
            'title'  => $widget->title,
            'status' => $widget->status,
            'type'   => $widget->type,
        ]);
    }

    /** 删除文章 */
    public function doDeletePost()
    {
        $cred = $this->requireCredential();
        if (($this->config['allowDelete'] ?? '0') !== '1') {
            $this->respondError(403, 'delete_forbidden', _t('当前插件未开启远程删除，请联系管理员'));
        }

        $cid = (int)$this->request->get('cid');
        if ($cid <= 0) {
            $this->respondError(400, 'invalid_cid', _t('请传入正确的文章 cid'));
        }

        try {
            PostEdit::alloc(null, ['cid' => $cid], function (PostEdit $post) {
                $post->deletePost();
            });
        } catch (\Throwable $e) {
            $this->respondError(500, 'delete_failed', $e->getMessage());
        }

        $this->respondSuccess([
            'cid'      => $cid,
            'deleted'  => true,
        ]);
    }

    /** 凭据校验 */
    private function requireCredential(): array
    {
        $appId = $this->extractAppId();
        $appSecret = $this->extractAppSecret();

        if ($appId === '' || $appSecret === '') {
            $this->respondError(401, 'missing_credentials',
                _t('缺少 AppID 或 AppSecret，请通过 X-App-Id / X-App-Secret 请求头（或 app_id / app_secret 参数）传递'));
        }

        $row = $this->db->fetchRow(
            $this->db->select()->from($this->prefix . 'api_publish')
                ->where('app_id = ?', $appId)
                ->limit(1)
        );

        if (!$row) {
            $this->respondError(401, 'invalid_credentials', _t('AppID 不存在'));
        }

        if ((int)$row['state'] !== 1) {
            $this->respondError(403, 'credential_disabled', _t('该凭据已被禁用'));
        }

        if (!hash_equals((string)$row['app_secret'], $appSecret)) {
            $this->respondError(401, 'invalid_credentials', _t('AppSecret 校验失败'));
        }

        $limit = (int)($this->config['rateInfo'] ?? 60);
        if ($limit > 0 && !$this->consumeRate($row, $limit)) {
            $this->respondError(429, 'rate_limited',
                _t('调用过于频繁，请稍后再试（每凭据每分钟上限 %d 次）', $limit));
        }

        $this->credential = $row;
        $this->bumpUsage($row);
        return $row;
    }

    /** 获取 AppID */
    private function extractAppId(): string
    {
        $header = $this->server->getHeader('X-App-Id');
        if ($header !== null && $header !== '') {
            return trim((string)$header);
        }

        return trim((string)$this->request->get('app_id'));
    }

    /** 获取 AppSecret */
    private function extractAppSecret(): string
    {
        $header = $this->server->getHeader('X-App-Secret');
        if ($header !== null && $header !== '') {
            return trim((string)$header);
        }

        return trim((string)$this->request->get('app_secret'));
    }

    /** 速率限制 */
    private function consumeRate(array $row, int $limit): bool
    {
        $now = time();
        $start = (int)$row['last_used'];
        $count = (int)$row['call_count'];

        if ($start <= 0 || ($now - $start) > 60) {
            $this->rateStarted = $now;
            $this->rateCount = 0;
            return true;
        }

        $this->rateStarted = $start;
        $this->rateCount = $count;

        return ($count + 1) <= $limit;
    }

    /** 更新使用计数 */
    private function bumpUsage(array $row): void
    {
        $now = time();
        $start = (int)$row['last_used'];
        $count = (int)$row['call_count'];

        if ($start <= 0 || ($now - $start) > 60) {
            $this->db->query($this->db->update($this->prefix . 'api_publish')
                ->rows(['last_used' => $now, 'call_count' => 1])
                ->where('id = ?', $row['id']));
        } else {
            $this->db->query($this->db->update($this->prefix . 'api_publish')
                ->rows(['call_count' => $count + 1])
                ->where('id = ?', $row['id']));
        }
    }

    /** 收集文章字段 */
    private function collectPostInput(): array
    {
        $params = $this->request->from([
            'title', 'slug', 'text', 'password', 'tags',
            'allowComment', 'allowPing', 'allowFeed',
            'visibility', 'trackback', 'created',
        ]);

        $params['title'] = isset($params['title']) && $params['title'] !== ''
            ? (string)$params['title']
            : _t('未命名文档');

        $params['text'] = isset($params['text']) ? (string)$params['text'] : '';

        if (!empty($this->config['filterHook']) && $this->config['filterHook'] === 'on') {
            $params['title'] = $this->request->filter('xss')->title;
            $params['text']  = $this->request->filter('xss')->text;
            $params['slug']  = $this->request->filter('xss')->slug;
            $params['tags']  = $this->request->filter('xss')->tags;
        }

        $categories = $this->request->getArray('category');
        if (empty($categories)) {
            $defaultSlug = trim((string)($this->config['defaultCategory'] ?? ''));
            if ($defaultSlug !== '') {
                $categories = array_filter(array_map('trim', explode(',', $defaultSlug)));
            }
        }
        if (!empty($categories)) {
            $params['category'] = $this->resolveCategoryIds($categories);
        }

        $status = strtolower((string)($this->request->get('status') ?: ($this->config['defaultStatus'] ?? 'publish')));
        $params['visibility'] = $this->mapStatus($status);

        return $params;
    }

    /** 状态映射 */
    private function mapStatus(string $status): string
    {
        switch ($status) {
            case 'draft':
            case 'pending':
            case 'waiting':
                return 'waiting';
            case 'private':
                return 'private';
            case 'hidden':
                return 'hidden';
            case 'publish':
            case 'public':
            case 'published':
            default:
                return 'publish';
        }
    }

    /** 解析分类ID */
    private function resolveCategoryIds(array $names): array
    {
        $mids = [];
        foreach ($names as $name) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }

            $row = $this->db->fetchRow(
                $this->db->select('mid')->from($this->prefix . 'metas')
                    ->where('type = ? AND (name = ? OR slug = ?)', 'category', $name, $name)
                    ->limit(1)
            );

            if ($row) {
                $mids[] = (int)$row['mid'];
                continue;
            }

            try {
                $newId = (int)$this->db->query($this->db->insert($this->prefix . 'metas')->rows([
                    'name'        => $name,
                    'slug'        => $name,
                    'type'        => 'category',
                    'description' => '',
                    'count'       => 0,
                    'order'       => 0,
                    'parent'      => 0,
                ]));
                if ($newId > 0) {
                    $mids[] = $newId;
                }
            } catch (\Throwable $e) {
            }
        }

        return $mids;
    }

    /** 确定作者UID */
    private function resolveAuthorUid(array $input, array $cred): int
    {
        $defaultUid = (int)($this->config['defaultAuthor'] ?? 1);

        $passUid = (int)($this->request->get('uid'));
        if ($passUid > 0) {
            $exists = $this->db->fetchRow(
                $this->db->select('uid')->from($this->prefix . 'users')->where('uid = ?', $passUid)->limit(1)
            );
            if ($exists) {
                return (int)$exists['uid'];
            }
        }

        return $defaultUid > 0 ? $defaultUid : 1;
    }

    /** 凭据摘要 */
    private function summarizeCredential(array $cred): array
    {
        return [
            'app_id'     => $cred['app_id'],
            'name'       => $cred['name'],
            'description'=> $cred['description'],
            'state'      => (int)$cred['state'],
            'created'    => (int)$cred['created'],
            'last_used'  => (int)$cred['last_used'],
            'call_count' => (int)$cred['call_count'],
        ];
    }

    /** 错误响应 */
    private function respondError(int $status, string $code, string $message)
    {
        $this->response->setStatus($status);
        $this->response->throwJson([
            'ok'      => false,
            'code'    => $code,
            'message' => $message,
        ]);
        exit;
    }

    /** 成功响应 */
    private function respondSuccess(array $data)
    {
        $this->response->throwJson(array_merge(['ok' => true], $data));
        exit;
    }

    /* ===========================================================
     *  后台凭据管理接口（需要管理员登录）
     * ===========================================================*/

    /** 要求管理员权限 */
    private function requireAdmin(): void
    {
        \Widget\User::alloc()->pass('administrator');
    }

    /** 新增凭据 */
    public function adminInsert()
    {
        $this->requireAdmin();
        $params = $this->request->from('name', 'description', 'app_id', 'app_secret', 'state');

        $appId     = trim((string)($params['app_id'] ?? ''));
        $appSecret = trim((string)($params['app_secret'] ?? ''));
        $name      = trim((string)($params['name'] ?? ''));
        $desc      = trim((string)($params['description'] ?? ''));
        $state     = (int)($params['state'] ?? 1) === 0 ? 0 : 1;

        if ($appId === '' || $appSecret === '') {
            $this->widget('Widget_Notice')->set(_t('AppID 与 AppSecret 均不能为空'), 'error');
            $this->response->goBack();
        }

        if ($this->db->fetchRow($this->db->select('id')->from($this->prefix . 'api_publish')->where('app_id = ?', $appId))) {
            $this->widget('Widget_Notice')->set(_t('AppID 已存在，请勿重复添加'), 'error');
            $this->response->goBack();
        }

        $this->db->query($this->db->insert($this->prefix . 'api_publish')->rows([
            'app_id'      => $appId,
            'app_secret'  => $appSecret,
            'name'        => $name !== '' ? $name : $appId,
            'description' => $desc,
            'state'       => $state,
            'created'     => time(),
            'last_used'   => 0,
            'call_count'  => 0,
        ]));

        $this->widget('Widget_Notice')->set(_t('API 凭据添加成功'), 'success');
        $this->response->goBack();
    }

    /** 更新凭据 */
    public function adminUpdate()
    {
        $this->requireAdmin();
        $id = (int)$this->request->get('id');
        if ($id <= 0) {
            $this->response->goBack();
        }

        $params = $this->request->from('name', 'description', 'state');
        $rows = [
            'name'        => trim((string)($params['name'] ?? '')),
            'description' => trim((string)($params['description'] ?? '')),
            'state'       => (int)($params['state'] ?? 1) === 0 ? 0 : 1,
        ];

        $this->db->query($this->db->update($this->prefix . 'api_publish')->rows($rows)->where('id = ?', $id));
        $this->widget('Widget_Notice')->set(_t('API 凭据已更新'), 'success');
        $this->response->goBack();
    }

    /** 删除凭据 */
    public function adminDelete()
    {
        $this->requireAdmin();
        $ids = $this->request->filter('int')->getArray('id');
        if (empty($ids)) {
            $this->response->goBack();
        }

        foreach ($ids as $id) {
            $this->db->query($this->db->delete($this->prefix . 'api_publish')->where('id = ?', (int)$id));
        }

        $this->widget('Widget_Notice')->set(_t('API 凭据已删除'), 'success');
        $this->response->goBack();
    }

    /** 切换状态 */
    public function adminToggle()
    {
        $this->requireAdmin();
        $id = (int)$this->request->get('id');
        $toState = (int)$this->request->get('state') === 1 ? 1 : 0;

        if ($id <= 0) {
            $this->response->goBack();
        }

        $this->db->query($this->db->update($this->prefix . 'api_publish')
            ->rows(['state' => $toState])
            ->where('id = ?', $id));

        $this->widget('Widget_Notice')->set(
            $toState === 1 ? _t('API 凭据已启用') : _t('API 凭据已禁用'),
            'success'
        );
        $this->response->goBack();
    }

    /** 重置Secret */
    public function adminResetSecret()
    {
        $this->requireAdmin();
        $id = (int)$this->request->get('id');
        $newSecret = trim((string)$this->request->get('app_secret'));

        if ($id <= 0 || $newSecret === '') {
            $this->response->goBack();
        }

        $this->db->query($this->db->update($this->prefix . 'api_publish')
            ->rows(['app_secret' => $newSecret])
            ->where('id = ?', $id));

        $this->widget('Widget_Notice')->set(_t('AppSecret 已重置'), 'success');
        $this->response->goBack();
    }

    /** 生成随机凭据 */
    public function adminGenerateSecret()
    {
        $this->requireAdmin();

        $appId = 'app_' . bin2hex(random_bytes(8));
        $appSecret = bin2hex(random_bytes(24));

        $this->response->throwJson([
            'ok'         => true,
            'app_id'     => $appId,
            'app_secret' => $appSecret,
        ]);
        exit;
    }

    /* ===========================================================
     *  RESTful 接口（参考 moefront/typecho-plugin-Restful）
     *  路由：/api/xxx  (Typecho\Router 分发后调用对应 xxxAction() 方法)
     * ===========================================================*/

    /** @var array|null RESTful 请求中解析出的 JSON body */
    private $httpParams = [];

    /** Widget入口 */
    public function execute()
    {
    }

    /** CORS处理 */
    private function sendCors(): void
    {
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string)$_SERVER['HTTP_ORIGIN']) : '';
        $config = WidgetOptions::alloc()->plugin('ApiPublish');

        $allowed = [];
        if (!empty($config->corsOrigin)) {
            $allowed = array_filter(array_map('trim', explode("\n", str_replace("\r", '', (string)$config->corsOrigin))));
        }

        if ($origin !== '') {
            if (in_array('*', $allowed, true)) {
                $this->response->setHeader('Access-Control-Allow-Origin', '*');
            } elseif (in_array($origin, $allowed, true)) {
                $this->response->setHeader('Access-Control-Allow-Origin', $origin);
                $this->response->setHeader('Vary', 'Origin');
            }
            $this->response->setHeader('Access-Control-Allow-Credentials', 'true');
        }

        if (isset($_SERVER['REQUEST_METHOD']) && strtolower((string)$_SERVER['REQUEST_METHOD']) === 'options') {
            $this->response->setStatus(204);
            $this->response->setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            $this->response->setHeader('Access-Control-Allow-Headers', 'Origin, X-Requested-With, Content-Type, Accept, Authorization, X-App-Id, X-App-Secret, token');
            $this->response->setHeader('Access-Control-Max-Age', '86400');
            exit;
        }
    }

    /** 解析JSON请求体 */
    private function parseJsonBody(): void
    {
        if (!$this->request->isPost()) {
            $this->httpParams = [];
            return;
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            $this->httpParams = [];
            return;
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->throwRestError('invalid json body: ' . json_last_error_msg(), 400);
        }
        $this->httpParams = is_array($data) ? $data : [];
    }

    /** 获取参数 */
    private function getRestParam(string $key, $default = null)
    {
        if ($this->request->isGet()) {
            return $this->request->get($key, $default);
        }
        return array_key_exists($key, $this->httpParams) ? $this->httpParams[$key] : $default;
    }

    /** 限制HTTP方法 */
    private function lockMethod(string $method): void
    {
        $actual = isset($_SERVER['REQUEST_METHOD']) ? strtolower((string)$_SERVER['REQUEST_METHOD']) : '';
        if ($actual !== strtolower($method)) {
            $this->throwRestError('method not allowed', 405);
        }
    }

    /** 检查RESTful状态 */
    private function checkRestfulState(string $shortName): void
    {
        $config = WidgetOptions::alloc()->plugin('ApiPublish');

        if (isset($config->restfulEnabled) && (string)$config->restfulEnabled === 'off') {
            $this->throwRestError('RESTful API is disabled globally', 403);
        }

        $flag = 'rest_' . $shortName;
        if (isset($config->$flag) && (string)$config->$flag === '0') {
            $this->throwRestError('this API endpoint is disabled', 403);
        }

        $configuredToken = isset($config->apiToken) ? trim((string)$config->apiToken) : '';
        if ($configuredToken !== '') {
            $headerToken = $this->server->getHeader('token');
            $headerToken = is_string($headerToken) ? trim($headerToken) : '';
            if ($headerToken !== '' && !hash_equals($configuredToken, $headerToken)) {
                $this->throwRestError('apiToken is invalid', 403);
            }
        }
    }

    /** RESTful鉴权 */
    private function requireRestfulAuth(): array
    {
        $config = WidgetOptions::alloc()->plugin('ApiPublish');
        $configuredToken = isset($config->apiToken) ? trim((string)$config->apiToken) : '';

        $headerToken = $this->server->getHeader('token');
        $headerToken = is_string($headerToken) ? trim($headerToken) : '';
        if ($configuredToken !== '' && $headerToken !== '' && hash_equals($configuredToken, $headerToken)) {
            return ['auth' => 'token'];
        }

        return $this->requireCredential();
    }

    /** 检查登录要求 */
    private function requireLoginIfNeeded(): void
    {
        $config = WidgetOptions::alloc()->plugin('ApiPublish');
        if (isset($config->validateLogin) && (string)$config->validateLogin === '1') {
            try {
                WidgetUser::alloc()->pass('administrator');
            } catch (\Throwable $e) {
                $this->throwRestError('admin login required', 401);
            }
        }
    }

    /** RESTful错误响应 */
    private function throwRestError(string $message, int $status = 400): void
    {
        $this->response->setStatus($status);
        $this->response->throwJson([
            'status'  => 'error',
            'message' => $message,
            'data'    => null,
        ]);
        exit;
    }

    /** RESTful成功响应 */
    private function throwRestData($data, string $message = ''): void
    {
        $this->response->throwJson([
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
        ]);
        exit;
    }

    /** 刷新Meta计数 */
    private function refreshMetas(array $midArray): void
    {
        if (empty($midArray)) {
            return;
        }
        $safeIds = array_filter(array_map('intval', $midArray), function ($v) { return $v > 0; });
        if (empty($safeIds)) {
            return;
        }
        $rows = $this->db->fetchAll(
            $this->db->select('mid')->from($this->prefix . 'metas')
                ->where('mid IN (' . implode(',', $safeIds) . ')')
        );
        foreach ($rows as $row) {
            $mid = (int)$row['mid'];
            $count = (int)$this->db->fetchObject(
                $this->db->select(['COUNT(cid)' => 'num'])
                    ->from($this->prefix . 'relationships')
                    ->where('mid = ?', $mid)
            )->num;
            $this->db->query(
                $this->db->update($this->prefix . 'metas')
                    ->rows(['count' => $count])
                    ->where('mid = ?', $mid)
            );
        }
    }

    /** 补充文章信息 */
    private function enrichPost(array $value): array
    {
        if (!isset($value['cid'])) {
            return $value;
        }
        try {
            $contents = BaseContents::alloc();
            $value = $contents->filter($value);
            if (!empty($value['slug'])) {
                $value['permalink'] = $contents->permalink;
            }
            $metas = $this->db->fetchAll(
                $this->db->select('type', 'name', 'slug')
                    ->from($this->prefix . 'metas')
                    ->join($this->prefix . 'relationships', $this->prefix . 'relationships.mid = ' . $this->prefix . 'metas.mid')
                    ->where($this->prefix . 'relationships.cid = ?', $value['cid'])
            );
            $categories = [];
            $tags = [];
            foreach ($metas as $m) {
                if ($m['type'] === 'category') {
                    $categories[] = ['name' => $m['name'], 'slug' => $m['slug']];
                } elseif ($m['type'] === 'tag') {
                    $tags[] = ['name' => $m['name'], 'slug' => $m['slug']];
                }
            }
            $value['categories'] = $categories;
            $value['tags']       = $tags;
        } catch (\Throwable $e) {
            // enrich 失败时返回原始值
        }
        $value['password'] = '';
        return $value;
    }

    /** 构建评论树 */
    private function buildCommentTree(array $comments): array
    {
        $childMap = [];
        $parentMap = [];
        foreach ($comments as $idx => $c) {
            $comments[$idx]['mailHash'] = isset($c['mail']) ? md5((string)$c['mail']) : '';
            unset($comments[$idx]['mail']);
            $parent = (int)($c['parent'] ?? 0);
            if ($parent > 0) {
                $childMap[$parent][] = $idx;
            } else {
                $parentMap[] = $idx;
            }
        }
        $walk = function ($parents) use (&$walk, &$comments, $childMap) {
            $result = [];
            foreach ($parents as $p) {
                $item = $comments[$p];
                $coid = (int)$item['coid'];
                $item['children'] = isset($childMap[$coid]) ? $walk($childMap[$coid]) : [];
                $result[] = $item;
            }
            return $result;
        };
        return $walk($parentMap);
    }

    /** 生成CSRF Token */
    private function generateCsrfToken(string $key): string
    {
        $config = WidgetOptions::alloc()->plugin('ApiPublish');
        $salt = isset($config->restfulCsrfSalt) ? (string)$config->restfulCsrfSalt : 'apipublish_default_salt';
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
        $inner = hash_hmac('sha256', date('Ymd') . $ip . $ua, hash('sha256', $key, true), true);
        return base64_encode(hash_hmac('sha256', $inner, $salt, true));
    }

    /* ===========================================================
     *  RESTful 读接口
     * ===========================================================*/

    /** 获取路由列表 */
    public function getRoutesAction()
    {
        $this->lockMethod('get');
        $this->sendCors();

        $ref = new \ReflectionClass(__CLASS__);
        $routes = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            $name = $m->getName();
            if (substr($name, -6) !== 'Action') {
                continue;
            }
            $short = substr($name, 0, -6);
            if ($short === '') {
                continue;
            }
            $body = file_get_contents($m->getFileName());
            $bodySnippet = '';
            $start = $m->getStartLine() - 1;
            $end   = $m->getEndLine();
            if ($body !== false && $start >= 0 && $end > $start) {
                $lines = explode("\n", $body);
                $bodySnippet = implode("\n", array_slice($lines, $start, $end - $start));
            }
            $http = 'GET';
            if (stripos($bodySnippet, "lockMethod('post'") !== false || stripos($bodySnippet, 'lockMethod("post"') !== false) {
                $http = 'POST';
            }
            $comment = (string)$m->getDocComment();
            $routes[] = [
                'shortName' => $short,
                'uri'       => '/api/' . $short,
                'method'    => $http,
                'doc'       => trim(preg_replace('/\s+/', ' ', $comment)),
            ];
        }
        $this->throwRestData($routes);
    }

    /** 文章列表 */
    public function postsAction()
    {
        $this->lockMethod('get');
        $this->sendCors();
        $this->checkRestfulState('posts');
        $this->requireRestfulAuth();

        $pageSize = max(1, (int)$this->getRestParam('pageSize', 5));
        $page     = max(1, (int)$this->getRestParam('page', 1));
        $offset   = $pageSize * ($page - 1);

        $filterType = trim((string)$this->getRestParam('filterType', ''));
        $filterSlug = trim((string)$this->getRestParam('filterSlug', ''));
        $showContent = strtolower((string)$this->getRestParam('showContent', 'false')) === 'true';
        $showDigest = trim((string)$this->getRestParam('showDigest', ''));

        $cids = null;
        if (in_array($filterType, ['category', 'tag', 'search'], true)) {
            if ($filterSlug === '') {
                $this->throwRestError('filter slug is empty');
            }
            if ($filterType !== 'search') {
                $row = $this->db->fetchRow(
                    $this->db->select('mid')->from($this->prefix . 'metas')
                        ->where('type = ? AND slug = ?', $filterType, $filterSlug)
                );
                if (!$row || !isset($row['mid'])) {
                    $this->throwRestError('unknown slug name', 404);
                }
                $rows = $this->db->fetchAll(
                    $this->db->select('cid')->from($this->prefix . 'relationships')->where('mid = ?', $row['mid'])
                );
                $cids = array_map(function ($r) { return (int)$r['cid']; }, $rows);
            }
        }

        $select = $this->db->select('cid', 'title', 'created', 'modified', 'slug', 'commentsNum', 'text', 'type')
            ->from($this->prefix . 'contents')
            ->where('type = ?', 'post')
            ->where('status = ?', 'publish')
            ->where('created < ?', time())
            ->where('password IS NULL')
            ->order('created', Db::SORT_DESC);

        if (is_array($cids)) {
            if (empty($cids)) {
                $this->throwRestData([
                    'page'     => $page,
                    'pageSize' => $pageSize,
                    'pages'    => 0,
                    'count'    => 0,
                    'dataSet'  => [],
                ]);
            }
            $select->where('cid IN (' . implode(',', $cids) . ')');
        } elseif ($filterType === 'search') {
            $kw = '%' . str_replace(' ', '%', $filterSlug) . '%';
            $select->where('title LIKE ? OR text LIKE ?', $kw, $kw);
        }

        $count = count($this->db->fetchAll($select));
        $select->offset($offset)->limit($pageSize);
        $rows = $this->db->fetchAll($select);

        $results = [];
        foreach ($rows as $r) {
            if ($showDigest === 'more') {
                $parts = explode('<!--more-->', (string)$r['text']);
                $r['digest'] = str_replace('<!--markdown-->', '', $parts[0]);
            } elseif ($showDigest === 'excerpt') {
                $limit = (int)$this->getRestParam('limit', 200);
                $r['digest'] = mb_substr(
                    htmlspecialchars_decode(strip_tags((string)$r['text'])),
                    0,
                    max(1, $limit),
                    'utf-8'
                ) . '...';
            }
            if (!$showContent) {
                unset($r['text']);
            }
            $r = $this->enrichPost($r);
            $results[] = $r;
        }

        $this->throwRestData([
            'page'     => $page,
            'pageSize' => $pageSize,
            'pages'    => $pageSize > 0 ? (int)ceil($count / $pageSize) : 0,
            'count'    => $count,
            'dataSet'  => $results,
        ]);
    }

    /** 单篇文章 */
    public function postAction()
    {
        $this->lockMethod('get');
        $this->sendCors();
        $this->checkRestfulState('post');
        $this->requireRestfulAuth();

        $cid  = $this->getRestParam('cid', '');
        $slug = $this->getRestParam('slug', '');

        $select = $this->db->select('cid', 'title', 'created', 'modified', 'slug', 'commentsNum', 'text', 'type', 'status', 'authorId')
            ->from($this->prefix . 'contents')
            ->where('password IS NULL');

        if (is_numeric($cid) && (int)$cid > 0) {
            $select->where('cid = ?', (int)$cid);
        } elseif ($slug !== '') {
            $select->where('slug = ?', $slug);
        } else {
            $this->throwRestError('cid or slug is required');
        }

        $row = $this->db->fetchRow($select);
        if (!$row) {
            $this->throwRestError('post not exists', 404);
        }
        $row = $this->enrichPost($row);

        // 附带 CSRF token，便于外部页提交评论
        try {
            if (!empty($row['permalink'])) {
                $row['csrfToken'] = $this->generateCsrfToken($row['permalink']);
            }
        } catch (\Throwable $e) {
        }

        $this->throwRestData($row);
    }

    /** 页面列表 */
    public function pagesAction()
    {
        $this->lockMethod('get');
        $this->sendCors();
        $this->checkRestfulState('pages');
        $this->requireRestfulAuth();

        $rows = $this->db->fetchAll(
            $this->db->select('cid', 'title', 'created', 'modified', 'slug', 'commentsNum')
                ->from($this->prefix . 'contents')
                ->where('type = ?', 'page')
                ->where('status = ?', 'publish')
                ->where('password IS NULL')
                ->order('order', Db::SORT_ASC)
        );
        $this->throwRestData([
            'count'   => count($rows),
            'dataSet' => $rows,
        ]);
    }

    /** 分类列表 */
    public function categoriesAction()
    {
        $this->lockMethod('get');
        $this->sendCors();
        $this->checkRestfulState('categories');
        $this->requireRestfulAuth();

        $rows = $this->db->fetchAll(
            $this->db->select('mid', 'name', 'slug', 'description', 'count', 'order', 'parent')
                ->from($this->prefix . 'metas')
                ->where('type = ?', 'category')
                ->order('order', Db::SORT_ASC)
        );
        $this->throwRestData([
            'count'   => count($rows),
            'dataSet' => $rows,
        ]);
    }

    /** 标签列表 */
    public function tagsAction()
    {
        $this->lockMethod('get');
        $this->sendCors();
        $this->checkRestfulState('tags');
        $this->requireRestfulAuth();

        $rows = $this->db->fetchAll(
            $this->db->select('mid', 'name', 'slug', 'description', 'count')
                ->from($this->prefix . 'metas')
                ->where('type = ?', 'tag')
                ->order('count', Db::SORT_DESC)
        );
        $this->throwRestData([
            'count'   => count($rows),
            'dataSet' => $rows,
        ]);
    }

    /** 最新评论 */
    public function recentCommentsAction()
    {
        $this->lockMethod('get');
        $this->sendCors();
        $this->checkRestfulState('recentComments');
        $this->requireRestfulAuth();

        $size = max(1, min(100, (int)$this->getRestParam('size', 9)));
        $rows = $this->db->fetchAll(
            $this->db->select('coid', 'cid', 'author', 'text', 'created')
                ->from($this->prefix . 'comments')
                ->where('type = ? AND status = ?', 'comment', 'approved')
                ->order('created', Db::SORT_DESC)
                ->limit($size)
        );
        $this->throwRestData([
            'count'   => count($rows),
            'dataSet' => $rows,
        ]);
    }

    /** 评论树 */
    public function commentsAction()
    {
        $this->lockMethod('get');
        $this->sendCors();
        $this->checkRestfulState('comments');
        $this->requireRestfulAuth();

        $cid  = $this->getRestParam('cid', '');
        $slug = $this->getRestParam('slug', '');
        $pageSize = max(1, min(100, (int)$this->getRestParam('pageSize', 20)));
        $page     = max(1, (int)$this->getRestParam('page', 1));
        $offset   = $pageSize * ($page - 1);

        if ((empty($cid) || !is_numeric($cid)) && $slug === '') {
            $this->throwRestError('cid or slug is required');
        }

        $select = $this->db->select(
            $this->prefix . 'comments.coid',
            $this->prefix . 'comments.parent',
            $this->prefix . 'comments.cid',
            $this->prefix . 'comments.created',
            $this->prefix . 'comments.author',
            $this->prefix . 'comments.url',
            $this->prefix . 'comments.text',
            $this->prefix . 'comments.status',
            $this->prefix . 'comments.mail'
        )->from($this->prefix . 'comments')
         ->join($this->prefix . 'contents', $this->prefix . 'comments.cid = ' . $this->prefix . 'contents.cid', Db::LEFT_JOIN)
         ->where($this->prefix . 'comments.type = ?', 'comment')
         ->where($this->prefix . 'comments.status = ?', 'approved')
         ->order($this->prefix . 'comments.created', Db::SORT_DESC);

        if (is_numeric($cid) && (int)$cid > 0) {
            $select->where($this->prefix . 'comments.cid = ?', (int)$cid);
        } else {
            $select->where($this->prefix . 'contents.slug = ?', $slug);
        }

        $rows = $this->db->fetchAll($select);
        $tree = $this->buildCommentTree($rows);
        $total = count($tree);
        $paged = array_slice($tree, $offset, $pageSize);

        $this->throwRestData([
            'page'     => $page,
            'pageSize' => $pageSize,
            'pages'    => $pageSize > 0 ? (int)ceil($total / $pageSize) : 0,
            'count'    => $total,
            'dataSet'  => $paged,
        ]);
    }

    /** 归档列表 */
    public function archivesAction()
    {
        $this->lockMethod('get');
        $this->sendCors();
        $this->checkRestfulState('archives');
        $this->requireRestfulAuth();

        $rows = $this->db->fetchAll(
            $this->db->select('cid', 'title', 'slug', 'created', 'modified', 'type', 'text')
                ->from($this->prefix . 'contents')
                ->where('type = ?', 'post')
                ->where('status = ?', 'publish')
                ->where('password IS NULL')
                ->order('created', Db::SORT_DESC)
        );

        $archives = [];
        foreach ($rows as $r) {
            $r = $this->enrichPost($r);
            unset($r['text']);
            $ts = (int)$r['created'];
            $archives[date('Y', $ts)][date('m', $ts)][] = $r;
        }
        krsort($archives);
        foreach ($archives as $y => $ms) {
            krsort($archives[$y]);
        }

        $this->throwRestData([
            'count'   => count($rows),
            'dataSet' => $archives,
        ]);
    }

    /** 用户列表 */
    public function userListAction()
    {
        $this->lockMethod('get');
        $this->sendCors();
        $this->checkRestfulState('userList');
        $this->requireRestfulAuth();

        $rows = $this->db->fetchAll(
            $this->db->select('uid', 'name', 'screenName', 'mail', 'url', 'group')
                ->from($this->prefix . 'users')
                ->order('uid', Db::SORT_ASC)
        );
        foreach ($rows as &$r) {
            $r['mailHash'] = md5((string)$r['mail']);
            unset($r['mail']);
        }
        unset($r);
        $this->throwRestData($rows);
    }

    /** 用户详情 */
    public function usersAction()
    {
        $this->lockMethod('get');
        $this->sendCors();
        $this->checkRestfulState('users');
        $this->requireRestfulAuth();

        $uid  = $this->getRestParam('uid', '');
        $name = $this->getRestParam('name', '');

        if ((empty($uid) || !is_numeric($uid)) && $name === '') {
            $this->throwRestError('uid or name is required');
        }

        $select = $this->db->select('uid', 'name', 'screenName', 'mail', 'url', 'group')
            ->from($this->prefix . 'users');
        if (is_numeric($uid) && (int)$uid > 0) {
            $select->where('uid = ?', (int)$uid);
        } else {
            $select->where('name = ? OR screenName = ?', $name, $name);
        }
        $rows = $this->db->fetchAll($select);
        if (empty($rows)) {
            $this->throwRestError('user not found', 404);
        }

        $result = [];
        foreach ($rows as $r) {
            $posts = $this->db->fetchAll(
                $this->db->select('cid', 'title', 'slug', 'created', 'modified')
                    ->from($this->prefix . 'contents')
                    ->where('authorId = ?', $r['uid'])
                    ->where('type = ?', 'post')
                    ->where('status = ?', 'publish')
                    ->where('password IS NULL')
                    ->order('created', Db::SORT_DESC)
            );
            $result[] = [
                'uid'      => (int)$r['uid'],
                'name'     => (string)$r['screenName'],
                'mailHash' => md5((string)$r['mail']),
                'url'      => (string)$r['url'],
                'group'    => (string)$r['group'],
                'count'    => count($posts),
                'posts'    => $posts,
            ];
        }
        unset($r);

        $this->throwRestData([
            'count'   => count($result),
            'dataSet' => $result,
        ]);
    }

    /** 站点设置 */
    public function settingsAction()
    {
        $this->lockMethod('get');
        $this->sendCors();
        $this->checkRestfulState('settings');
        $this->requireRestfulAuth();

        $key = trim((string)$this->getRestParam('key', ''));
        $allowed = ['title', 'description', 'keywords', 'timezone'];

        $data = [
            'title'       => $this->options->title,
            'description' => $this->options->description,
            'keywords'    => $this->options->keywords,
            'timezone'    => $this->options->timezone,
            'siteUrl'     => $this->options->siteUrl,
        ];

        if ($key !== '') {
            if (!in_array($key, $allowed, true)) {
                $this->throwRestError('options key not allowed', 403);
            }
            $this->throwRestData([$key => $data[$key] ?? null]);
        }
        $this->throwRestData($data);
    }

    /* ===========================================================
     *  RESTful 写接口（POST /api/*）
     * ===========================================================*/

    /** 收集RESTful写入字段 */
    private function collectRestPostInput(): array
    {
        $params = [];
        foreach (['title', 'slug', 'text', 'password', 'tags',
                  'allowComment', 'allowPing', 'allowFeed',
                  'visibility', 'trackback', 'created', 'status', 'uid'] as $k) {
            $params[$k] = $this->getRestParam($k, '');
        }

        $params['title'] = (string)$params['title'] !== ''
            ? (string)$params['title']
            : _t('未命名文档');
        $params['text']  = (string)$params['text'];

        if (!empty($this->config['filterHook']) && $this->config['filterHook'] === 'on') {
            foreach (['title', 'text', 'slug', 'tags'] as $k) {
                $params[$k] = $this->request->filter('xss')->{$k};
            }
        }

        // 分类：JSON body 可能是数组或字符串
        $categories = $this->getRestParam('category', []);
        if (is_string($categories) && $categories !== '') {
            $categories = [$categories];
        }
        if (!is_array($categories)) {
            $categories = [];
        }
        if (empty($categories)) {
            $defaultSlug = trim((string)($this->config['defaultCategory'] ?? ''));
            if ($defaultSlug !== '') {
                $categories = array_filter(array_map('trim', explode(',', $defaultSlug)));
            }
        }
        if (!empty($categories)) {
            $params['category'] = $this->resolveCategoryIds($categories);
        }

        $status = strtolower((string)($params['status'] ?: ($this->config['defaultStatus'] ?? 'publish')));
        $params['visibility'] = $this->mapStatus($status);
        unset($params['status']);

        return $params;
    }

    /** 发布文章 */
    public function postArticleAction()
    {
        $this->lockMethod('post');
        $this->sendCors();
        $this->parseJsonBody();
        $this->checkRestfulState('postArticle');
        $cred = $this->requireRestfulAuth();
        $this->requireLoginIfNeeded();

        $input = $this->collectRestPostInput();
        $input['do'] = 'publish';
        $input['markdown'] = (string)($this->config['markdown'] ?? '1');
        $input['uid'] = $this->resolveAuthorUid($input, is_array($cred) ? $cred : []);

        try {
            $widget = PostEdit::alloc(null, $input, function (PostEdit $post) {
                $post->writePost();
            });
        } catch (\Throwable $e) {
            $this->throwRestError('publish failed: ' . $e->getMessage(), 500);
        }

        $this->throwRestData([
            'cid'     => (int)$widget->cid,
            'title'   => $widget->title,
            'status'  => $widget->status,
            'type'    => $widget->type,
            'created' => (int)$widget->created,
        ], 'post created');
    }

    /** 编辑文章 */
    public function editArticleAction()
    {
        $this->lockMethod('post');
        $this->sendCors();
        $this->parseJsonBody();
        $this->checkRestfulState('editArticle');
        $cred = $this->requireRestfulAuth();
        $this->requireLoginIfNeeded();

        $cid = (int)$this->getRestParam('cid', 0);
        if ($cid <= 0) {
            $this->throwRestError('cid is required and must be a positive integer', 400);
        }

        $row = $this->db->fetchRow(
            $this->db->select('cid', 'type', 'authorId', 'status')
                ->from($this->prefix . 'contents')
                ->where('cid = ?', $cid)
        );
        if (!$row) {
            $this->throwRestError('post not found', 404);
        }

        $input = $this->collectRestPostInput();
        $input['cid'] = $cid;
        $input['do'] = 'publish';
        $input['markdown'] = (string)($this->config['markdown'] ?? '1');
        $input['uid'] = $this->resolveAuthorUid($input, is_array($cred) ? $cred : []);

        try {
            $widget = PostEdit::alloc(null, $input, function (PostEdit $post) {
                $post->writePost();
            });
        } catch (\Throwable $e) {
            $this->throwRestError('edit failed: ' . $e->getMessage(), 500);
        }

        $this->throwRestData([
            'cid'    => (int)$widget->cid,
            'title'  => $widget->title,
            'status' => $widget->status,
            'type'   => $widget->type,
        ], 'post updated');
    }

    /** 删除文章 */
    public function deleteArticleAction()
    {
        $this->lockMethod('post');
        $this->sendCors();
        $this->parseJsonBody();
        $this->checkRestfulState('deleteArticle');
        $this->requireRestfulAuth();
        $this->requireLoginIfNeeded();

        if (($this->config['allowDelete'] ?? '0') !== '1') {
            $this->throwRestError('remote delete is disabled, set allowDelete=1 in plugin config', 403);
        }

        $cid = (int)$this->getRestParam('cid', 0);
        if ($cid <= 0) {
            $this->throwRestError('cid is required and must be a positive integer', 400);
        }

        try {
            PostEdit::alloc(null, ['cid' => $cid], function (PostEdit $post) {
                $post->deletePost();
            });
        } catch (\Throwable $e) {
            $this->throwRestError('delete failed: ' . $e->getMessage(), 500);
        }

        $this->throwRestData([
            'cid'     => $cid,
            'deleted' => true,
        ], 'post deleted');
    }

    /** 新增分类/标签 */
    public function addMetasAction()
    {
        $this->lockMethod('post');
        $this->sendCors();
        $this->parseJsonBody();
        $this->checkRestfulState('addMetas');
        $this->requireRestfulAuth();
        $this->requireLoginIfNeeded();

        $type = strtolower(trim((string)$this->getRestParam('type', 'category')));
        if (!in_array($type, ['category', 'tag'], true)) {
            $this->throwRestError('type must be "category" or "tag"', 400);
        }

        $name        = trim((string)$this->getRestParam('name', ''));
        $slug        = trim((string)$this->getRestParam('slug', ''));
        $description = trim((string)$this->getRestParam('description', ''));
        $parent      = (int)$this->getRestParam('parent', 0);
        $order       = (int)$this->getRestParam('order', 0);

        if ($name === '') {
            $this->throwRestError('name is required', 400);
        }
        if ($slug === '') {
            $slug = $name;
        }

        $exists = $this->db->fetchRow(
            $this->db->select('mid')->from($this->prefix . 'metas')
                ->where('type = ? AND (name = ? OR slug = ?)', $type, $name, $slug)
                ->limit(1)
        );
        if ($exists) {
            $this->throwRestError('meta already exists', 409);
        }

        try {
            $newId = (int)$this->db->query($this->db->insert($this->prefix . 'metas')->rows([
                'name'        => $name,
                'slug'        => $slug,
                'type'        => $type,
                'description' => $description,
                'count'       => 0,
                'order'       => $order,
                'parent'      => $parent,
            ]));
        } catch (\Throwable $e) {
            $this->throwRestError('insert failed: ' . $e->getMessage(), 500);
        }

        $this->throwRestData([
            'mid'         => $newId,
            'name'        => $name,
            'slug'        => $slug,
            'type'        => $type,
            'description' => $description,
            'parent'      => $parent,
            'order'       => $order,
        ], 'meta created');
    }
}