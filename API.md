# ApiPublish - API 接口文档

> Typecho ApiPublish 插件提供的对外 HTTP 接口完整说明，包含传统 `?do=xxx` 与 RESTful `/api/xxx` 两套调用风格。

## 目录

- [基础信息](#基础信息)
- [认证方式](#认证方式)
- [通用响应格式](#通用响应格式)
- [错误码对照](#错误码对照)
- [频率限制](#频率限制)
- [传统接口（do=xxx）](#传统接口doxxx)
  - [test - 连通性测试](#test---连通性测试)
  - [getInfo - 凭据与速率信息](#getinfo---凭据与速率信息)
  - [getCategories - 分类列表](#getcategories---分类列表)
  - [newPost - 发布文章](#newpost---发布文章)
  - [editPost - 编辑文章](#editpost---编辑文章)
  - [deletePost - 删除文章](#deletepost---删除文章)
- [RESTful 接口（/api/xxx）](#restful-接口apixxx)
- [调用示例汇总](#调用示例汇总)

---

## 基础信息

### 接口入口

| 用途 | URL |
| --- | --- |
| 传统接口入口 | `https://你的域名/index.php/action/api-publish` |
| RESTful 接口入口 | `https://你的域名/api/`（前缀可在插件配置中修改） |
| 原生 XML-RPC | `https://你的域名/index.php/action/xmlrpc` |

> RESTful 路由前缀在插件配置项 **RESTful 路径前缀** 中维护，默认为 `/api/`。以下文档均按默认值说明。

### 请求要求

- 所有接口强制 UTF-8 编码
- 推荐 HTTPS；若使用 HTTP 请在可信内网中调用
- 请求体支持两种格式：
  - `application/x-www-form-urlencoded`（表单）
  - `application/json`（仅 RESTful POST 接口生效，POST 内容将自动解析）

---

## 认证方式

### 传统接口与大部分 RESTful 接口

凭据通过 **AppID / AppSecret** 标识，按下列优先级解析：

1. HTTP 请求头：`X-App-Id`、`X-App-Secret`
2. URL 查询参数：`app_id`、`app_secret`
3. POST 表单字段：`app_id`、`app_secret`

> **强烈建议**：生产环境始终通过请求头传递凭据，避免被网关/反向代理写入访问日志。

### RESTful 读接口（兼容）

部分 RESTful 读接口（如 `posts` / `comments` 等）兼容早期实现中的 `token` 头（值为 AppSecret）作为兜底认证；当 `X-App-Id` / `X-App-Secret` 同时存在时以它们为准。

---

## 通用响应格式

所有接口统一返回 JSON，结构如下：

### 成功响应

```json
{
  "ok": true,
  "...": "接口相关数据"
}
```

### 错误响应

```json
{
  "ok": false,
  "code": "machine_readable_code",
  "message": "人类可读的错误描述"
}
```

HTTP 状态码会同时设置为对应的语义码（401 / 403 / 404 / 429 / 500 等）。

---

## 错误码对照

| HTTP | code | 含义 | 触发场景 |
| --- | --- | --- | --- |
| 400 | `invalid_cid` | 编辑/删除时未传入合法 cid | `cid <= 0` |
| 400 | `invalid json body: ...` | JSON 解析失败 | RESTful POST 时 body 不是合法 JSON |
| 400 | `filter slug is empty` | 未提供过滤 slug | `filterType=category/tag/search` 但 `filterSlug` 为空 |
| 400 | `cid or slug is required` | 二选一参数缺失 | `post` / `comments` / `users` 等 |
| 400 | `cid is required and must be a positive integer` | RESTful 编辑/删除要求 cid | `editArticle` / `deleteArticle` |
| 400 | `type must be "category" or "tag"` | 类型非法 | `addMetas` |
| 400 | `name is required` | 名称为空 | `addMetas` |
| 400 | `options key not allowed` | 配置项 key 不在白名单 | `settings?key=xxx` |
| 401 | `missing_credentials` | 缺少 AppID / AppSecret | 头与参数均未提供 |
| 401 | `invalid_credentials` | AppID 不存在或 AppSecret 不匹配 | 校验失败 |
| 403 | `credential_disabled` | 凭据已被禁用 | `state = 0` |
| 403 | `delete_forbidden` | 未开启远程删除 | `allowDelete != 1` |
| 404 | `unknown_method` | 未识别的 do | 走默认分支 |
| 404 | `post_not_found` | 编辑/删除的目标文章不存在 | |
| 404 | `post not exists` | RESTful `post` 未命中 | |
| 404 | `user not found` | `users` 未命中 | |
| 404 | `unknown slug name` | 分类/标签 slug 未命中 | |
| 404 | `unknown_method` | 旧 Action 未匹配 | |
| 409 | `meta already exists` | 分类/标签同名 | `addMetas` |
| 429 | `rate_limited` | 触发限流 | 超过每分钟调用上限 |
| 500 | `publish_failed` | 发布异常 | `PostEdit::writePost()` 抛错 |
| 500 | `edit_failed` | 编辑异常 | 同上 |
| 500 | `delete_failed` | 删除异常 | `PostEdit::deletePost()` 抛错 |
| 500 | `insert failed: ...` | 新增分类/标签失败 | 数据库异常 |

---

## 频率限制

- **窗口**：60 秒滑动窗口，以 `last_used` 字段为起点
- **默认上限**：60 次/分钟（可在插件配置 **每分钟调用频率上限** 中调整）
- **`0`**：表示不限流
- **触发时**：返回 HTTP 429 `rate_limited`
- **生效范围**：所有需要凭据的传统接口与 RESTful 读/写接口
- **存储**：每次调用都会更新数据库中的 `call_count` 与 `last_used`

> 频率计数与凭据绑定；不同 AppID 互不影响。

---

## 传统接口（do=xxx）

> 入口：`POST https://你的域名/index.php/action/api-publish?do=xxx`
>
> 通过 `do` 参数路由，支持 GET/POST；以下示例全部使用 POST 以兼容长文。

### test - 连通性测试

校验凭据并返回服务端摘要信息。

**请求**

| 参数 | 位置 | 必填 | 说明 |
| --- | --- | --- | --- |
| `do` | URL | 是 | 固定 `test` |
| `app_id` / `X-App-Id` | 头/参数 | 是 | 凭据 ID |
| `app_secret` / `X-App-Secret` | 头/参数 | 是 | 凭据密钥 |

**响应**

```json
{
  "ok": true,
  "message": "凭据验证成功",
  "credential": {
    "app_id": "cli_xxx",
    "name": "我的脚本",
    "description": "...",
    "state": 1,
    "created": 1700000000,
    "last_used": 1700000100,
    "call_count": 3
  },
  "server": {
    "site": "https://example.com",
    "title": "站点标题",
    "apiEntry": "https://example.com/index.php/action/api-publish",
    "xmlrpc": "https://example.com/index.php/action/xmlrpc"
  }
}
```

### getInfo - 凭据与速率信息

返回当前凭据摘要以及速率窗口状态。

**请求**

| 参数 | 位置 | 必填 | 说明 |
| --- | --- | --- | --- |
| `do` | URL | 是 | 固定 `getInfo` |
| `app_id` | 头/参数 | 是 | |
| `app_secret` | 头/参数 | 是 | |

**响应**

```json
{
  "ok": true,
  "credential": {
    "app_id": "cli_xxx",
    "name": "...",
    "description": "...",
    "state": 1,
    "created": 1700000000,
    "last_used": 1700000100,
    "call_count": 5
  },
  "rateLimit": {
    "windowSeconds": 60,
    "limit": 60,
    "current": 5,
    "windowStart": 1700000100
  }
}
```

### getCategories - 分类列表

返回全部分类（按 `order` 升序）。

**请求**

| 参数 | 位置 | 必填 | 说明 |
| --- | --- | --- | --- |
| `do` | URL | 是 | 固定 `getCategories` |
| `app_id` | 头/参数 | 是 | |
| `app_secret` | 头/参数 | 是 | |

**响应**

```json
{
  "ok": true,
  "count": 3,
  "categories": [
    {
      "mid": 1,
      "name": "技术",
      "slug": "tech",
      "description": "",
      "parent": 0,
      "count": 12
    }
  ]
}
```

### newPost - 发布文章

发布一篇新文章。

**请求**

| 参数 | 位置 | 必填 | 说明 |
| --- | --- | --- | --- |
| `do` | URL | 是 | 固定 `newPost` |
| `app_id` | 头/参数 | 是 | |
| `app_secret` | 头/参数 | 是 | |
| `title` | form/query | 否 | 文章标题，缺省为"未命名文档" |
| `text` | form/query | 否 | 正文（Markdown 或 HTML，受配置影响） |
| `slug` | form/query | 否 | 自定义 slug |
| `tags` | form/query | 否 | 逗号分隔的标签 |
| `category` | form/query | 否 | 分类 slug 或名称（可重复提交多个）；缺省走插件配置 **默认分类** |
| `password` | form/query | 否 | 加密密码 |
| `allowComment` | form/query | 否 | `1`/`0`，默认 1 |
| `allowPing` | form/query | 否 | `1`/`0` |
| `allowFeed` | form/query | 否 | `1`/`0` |
| `visibility` | form/query | 否 | 直接传 Typecho 内部值（`publish`/`waiting`/`private`/`hidden`） |
| `status` | form/query | 否 | 外部状态字符串：`publish` / `draft` / `pending` / `private` / `hidden`，缺省走插件配置 **默认文章状态** |
| `created` | form/query | 否 | 自定义发布时间戳（秒），留空表示当前时间 |
| `trackback` | form/query | 否 | trackback URL |
| `uid` | form/query | 否 | 作者 UID，缺省走插件配置 **默认作者** |

> 启用 **是否启用 XSS 过滤** 后，`title` / `text` / `slug` / `tags` 会走 Typecho XSS filter。

**响应**

```json
{
  "ok": true,
  "cid": 123,
  "title": "我的文章",
  "status": "publish",
  "type": "post",
  "created": 1700000000
}
```

### editPost - 编辑文章

更新已有文章，参数集与 `newPost` 一致，**必须**额外传入 `cid`。

**请求**

| 参数 | 位置 | 必填 | 说明 |
| --- | --- | --- | --- |
| `do` | URL | 是 | 固定 `editPost` |
| `cid` | URL/form | 是 | 目标文章 cid |
| `app_id` | 头/参数 | 是 | |
| `app_secret` | 头/参数 | 是 | |
| 其他字段同 newPost | | | |

**响应**

```json
{
  "ok": true,
  "cid": 123,
  "title": "改后的标题",
  "status": "publish",
  "type": "post"
}
```

**错误**

- `400 invalid_cid`：cid 不合法
- `404 post_not_found`：目标文章不存在
- `500 edit_failed`：底层 `writePost()` 抛错

### deletePost - 删除文章

> 需要插件配置 **远程删除** 开启；默认关闭。

**请求**

| 参数 | 位置 | 必填 | 说明 |
| --- | --- | --- | --- |
| `do` | URL | 是 | 固定 `deletePost` |
| `cid` | URL/form | 是 | 目标文章 cid |
| `app_id` | 头/参数 | 是 | |
| `app_secret` | 头/参数 | 是 | |

**响应**

```json
{
  "ok": true,
  "cid": 123,
  "deleted": true
}
```

**错误**

- `403 delete_forbidden`：未启用远程删除
- `400 invalid_cid`：cid 缺失或非正整数
- `500 delete_failed`：删除异常

---

## RESTful 接口（/api/xxx）

> RESTful 路由通过反射扫描 `Action.php` 中所有 `xxxAction()` 方法自动注册。
>
> - 默认前缀：`/api/`
> - 读接口统一使用 `GET`
> - 写接口统一使用 `POST`
> - 所有接口均会发送 CORS 头，并在 `OPTIONS` 预检请求时直接 204 返回
> - 所有 POST 接口支持 JSON body 与表单 body 两种格式（JSON 优先）

### getRoutes - 路由清单

**请求**

| 方法 | URL |
| --- | --- |
| GET | `/api/getRoutes` |

**响应**

```json
[
  {
    "shortName": "posts",
    "uri": "/api/posts",
    "method": "GET",
    "doc": "文章列表（GET /api/posts） ..."
  }
]
```

> 适合在前端/CLI 中调用以动态生成接口列表。

### posts - 文章列表

**请求**

| 方法 | URL |
| --- | --- |
| GET | `/api/posts` |

| 参数 | 必填 | 说明 |
| --- | --- | --- |
| `page` | 否 | 页码，默认 1 |
| `pageSize` | 否 | 每页条数，默认 5 |
| `filterType` | 否 | `category` / `tag` / `search` |
| `filterSlug` | 否 | 过滤 slug 或搜索关键字（`filterType=search` 时为关键字） |
| `showContent` | 否 | `true`/`false`，是否返回 `text` 字段 |
| `showDigest` | 否 | `more`（按 `<!--more-->` 截取）/ `excerpt`（按字数截取）/ 空 |
| `limit` | 否 | `showDigest=excerpt` 时截取长度，默认 200 |

**响应**

```json
{
  "page": 1,
  "pageSize": 5,
  "pages": 12,
  "count": 58,
  "dataSet": [
    {
      "cid": 1,
      "title": "...",
      "created": 1700000000,
      "modified": 1700000100,
      "slug": "hello",
      "commentsNum": 3,
      "type": "post",
      "permalink": "https://example.com/hello.html",
      "categories": [{"mid":1,"name":"技术","slug":"tech"}],
      "tags": ["api","typecho"]
    }
  ]
}
```

> 仅返回 `status=publish` 且未加密（`password IS NULL`）且已到发布时间（`created < now`）的文章。

### post - 单篇文章详情

**请求**

| 方法 | URL |
| --- | --- |
| GET | `/api/post` |

| 参数 | 必填 | 说明 |
| --- | --- | --- |
| `cid` | 否* | 文章 ID |
| `slug` | 否* | 文章 slug |

> `cid` 与 `slug` 必须二选一；`cid` 优先。

**响应**

```json
{
  "cid": 1,
  "title": "...",
  "created": 1700000000,
  "modified": 1700000100,
  "slug": "hello",
  "commentsNum": 3,
  "text": "Markdown / HTML 正文",
  "type": "post",
  "status": "publish",
  "authorId": 1,
  "permalink": "...",
  "categories": [...],
  "tags": [...],
  "csrfToken": "..."
}
```

> `csrfToken` 适用于外部页面提交评论时复用 Typecho 自带的 CSRF 校验。

**错误**

- `400 cid or slug is required`
- `404 post not exists`

### pages - 页面列表

**请求**

| 方法 | URL |
| --- | --- |
| GET | `/api/pages` |

**响应**

```json
{
  "count": 3,
  "dataSet": [
    {
      "cid": 10,
      "title": "关于",
      "created": 1700000000,
      "modified": 1700000100,
      "slug": "about",
      "commentsNum": 0
    }
  ]
}
```

### categories - 分类列表

**请求**

| 方法 | URL |
| --- | --- |
| GET | `/api/categories` |

**响应**

```json
{
  "count": 2,
  "dataSet": [
    {
      "mid": 1,
      "name": "技术",
      "slug": "tech",
      "description": "",
      "count": 12,
      "order": 0,
      "parent": 0
    }
  ]
}
```

### tags - 标签列表

**请求**

| 方法 | URL |
| --- | --- |
| GET | `/api/tags` |

按 `count` 倒序。

**响应**

```json
{
  "count": 5,
  "dataSet": [
    {"mid": 2, "name": "API", "slug": "api", "description": "", "count": 3}
  ]
}
```

### recentComments - 最新评论

**请求**

| 方法 | URL |
| --- | --- |
| GET | `/api/recentComments` |

| 参数 | 必填 | 说明 |
| --- | --- | --- |
| `size` | 否 | 返回条数，1~100，默认 9 |

**响应**

```json
{
  "count": 9,
  "dataSet": [
    {
      "coid": 99,
      "cid": 1,
      "author": "访客",
      "text": "支持！",
      "created": 1700000000
    }
  ]
}
```

### comments - 文章/页面评论树

**请求**

| 方法 | URL |
| --- | --- |
| GET | `/api/comments` |

| 参数 | 必填 | 说明 |
| --- | --- | --- |
| `cid` | 否* | 文章 ID |
| `slug` | 否* | 文章 slug |
| `page` | 否 | 默认 1 |
| `pageSize` | 否 | 1~100，默认 20 |

**响应**

```json
{
  "page": 1,
  "pageSize": 20,
  "pages": 1,
  "count": 2,
  "dataSet": [
    {
      "coid": 99,
      "parent": 0,
      "cid": 1,
      "created": 1700000000,
      "author": "访客",
      "url": "https://...",
      "text": "支持！",
      "status": "approved",
      "mail": "visitor@example.com",
      "children": [
        {
          "coid": 100,
          "parent": 99,
          ...
        }
      ]
    }
  ]
}
```

### archives - 归档（按年月分组）

**请求**

| 方法 | URL |
| --- | --- |
| GET | `/api/archives` |

**响应**

```json
{
  "count": 50,
  "dataSet": {
    "2025": {
      "06": [
        {"cid":1,"title":"...","created":1700000000, ...}
      ],
      "05": [...]
    },
    "2024": {...}
  }
}
```

### userList - 全站用户列表

**请求**

| 方法 | URL |
| --- | --- |
| GET | `/api/userList` |

**响应**

```json
[
  {
    "uid": 1,
    "name": "admin",
    "screenName": "管理员",
    "url": "https://...",
    "group": "administrator",
    "mailHash": "md5(email)"
  }
]
```

> `mailHash` 已替换 `mail` 字段，可直接拼接 `https://www.gravatar.com/avatar/{hash}`。

### users - 单用户信息 + 其文章

**请求**

| 方法 | URL |
| --- | --- |
| GET | `/api/users` |

| 参数 | 必填 | 说明 |
| --- | --- | --- |
| `uid` | 否* | 用户 ID |
| `name` | 否* | 用户名 / 昵称 |

**响应**

```json
{
  "count": 1,
  "dataSet": [
    {
      "uid": 1,
      "name": "管理员",
      "mailHash": "md5(email)",
      "url": "https://...",
      "group": "administrator",
      "count": 12,
      "posts": [
        {"cid":1,"title":"...","slug":"...","created":1700000000,"modified":1700000100}
      ]
    }
  ]
}
```

### settings - 站点公开设置

**请求**

| 方法 | URL |
| --- | --- |
| GET | `/api/settings` |

| 参数 | 必填 | 说明 |
| --- | --- | --- |
| `key` | 否 | 仅返回指定项；允许值：`title` / `description` / `keywords` / `timezone` |

**响应（无 key）**

```json
{
  "title": "...",
  "description": "...",
  "keywords": "...",
  "timezone": "Asia/Shanghai",
  "siteUrl": "https://example.com"
}
```

**响应（有 key）**

```json
{
  "title": "..."
}
```

**错误**

- `403 options key not allowed`

### postArticle - 发布文章（RESTful）

**请求**

| 方法 | URL |
| --- | --- |
| POST | `/api/postArticle` |

支持 JSON body 与表单 body。

| 字段 | 必填 | 说明 |
| --- | --- | --- |
| `title` | 否 | 缺省 "未命名文档" |
| `text` | 否 | 正文 |
| `slug` | 否 | 自定义 slug |
| `tags` | 否 | 标签，逗号分隔 |
| `category` | 否 | 字符串或字符串数组（JSON） |
| `password` | 否 | 加密密码 |
| `allowComment` / `allowPing` / `allowFeed` | 否 | `1`/`0` |
| `visibility` | 否 | Typecho 内部 visibility |
| `status` | 否 | 外部状态字符串 |
| `created` | 否 | 自定义时间戳（秒） |
| `uid` | 否 | 作者 UID（缺省走配置） |
| `trackback` | 否 | |

**响应**

```json
{
  "cid": 123,
  "title": "我的文章",
  "status": "publish",
  "type": "post",
  "created": 1700000000,
  "message": "post created"
}
```

**错误**

- `400 invalid json body: ...`
- `500 publish failed: ...`

### editArticle - 编辑文章（RESTful）

**请求**

| 方法 | URL |
| --- | --- |
| POST | `/api/editArticle` |

字段集与 `postArticle` 一致，**必须**额外提供 `cid`。

| 字段 | 必填 | 说明 |
| --- | --- | --- |
| `cid` | 是 | 目标文章 cid |

**响应**

```json
{
  "cid": 123,
  "title": "改后",
  "status": "publish",
  "type": "post",
  "message": "post updated"
}
```

**错误**

- `400 cid is required and must be a positive integer`
- `404 post not found`
- `500 edit failed: ...`

### deleteArticle - 删除文章（RESTful）

> 需要插件配置 **远程删除** 开启；默认关闭。

**请求**

| 方法 | URL |
| --- | --- |
| POST | `/api/deleteArticle` |

| 字段 | 必填 | 说明 |
| --- | --- | --- |
| `cid` | 是 | 目标文章 cid |

**响应**

```json
{
  "cid": 123,
  "deleted": true,
  "message": "post deleted"
}
```

**错误**

- `403 remote delete is disabled, set allowDelete=1 in plugin config`
- `400 cid is required and must be a positive integer`
- `500 delete failed: ...`

### addMetas - 新增分类/标签

**请求**

| 方法 | URL |
| --- | --- |
| POST | `/api/addMetas` |

| 字段 | 必填 | 说明 |
| --- | --- | --- |
| `type` | 否 | `category`（默认）/ `tag` |
| `name` | 是 | 名称 |
| `slug` | 否 | 缺省与 name 相同 |
| `description` | 否 | 描述 |
| `parent` | 否 | 父分类 mid（仅 type=category），默认 0 |
| `order` | 否 | 排序，默认 0 |

**响应**

```json
{
  "mid": 42,
  "name": "新分类",
  "slug": "new-cat",
  "type": "category",
  "description": "",
  "parent": 0,
  "order": 0,
  "message": "meta created"
}
```

**错误**

- `400 type must be "category" or "tag"`
- `400 name is required`
- `409 meta already exists`
- `500 insert failed: ...`

---

## 调用示例汇总

> 所有示例使用占位符，请替换为你的实际域名、AppID、AppSecret。

### cURL 示例

#### 1. 传统接口 - 校验连通性

```bash
curl -X POST "https://example.com/index.php/action/api-publish?do=test" \
  -H "X-App-Id: cli_a1b2c3d4e5f6g7h8" \
  -H "X-App-Secret: 0a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p"
```

#### 2. 传统接口 - 发布文章

```bash
curl -X POST "https://example.com/index.php/action/api-publish?do=newPost" \
  -H "X-App-Id: cli_a1b2c3d4e5f6g7h8" \
  -H "X-App-Secret: 0a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p" \
  -d "title=我的文章" \
  -d "text=# 标题\n这是正文" \
  -d "tags=API,Typecho" \
  -d "category=技术" \
  -d "status=publish"
```

#### 3. RESTful 接口 - 查询路由

```bash
curl "https://example.com/api/getRoutes" \
  -H "X-App-Id: cli_a1b2c3d4e5f6g7h8" \
  -H "X-App-Secret: 0a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p"
```

#### 4. RESTful 接口 - 获取文章列表

```bash
curl "https://example.com/api/posts?page=1&pageSize=10&showDigest=excerpt&limit=120" \
  -H "X-App-Id: cli_a1b2c3d4e5f6g7h8" \
  -H "X-App-Secret: 0a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p"
```

#### 5. RESTful 接口 - JSON 发布

```bash
curl -X POST "https://example.com/api/postArticle" \
  -H "Content-Type: application/json" \
  -H "X-App-Id: cli_a1b2c3d4e5f6g7h8" \
  -H "X-App-Secret: 0a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p" \
  -d '{
    "title": "API 发布测试",
    "text": "# 标题\n这是 **Markdown**",
    "tags": "API,Typecho",
    "category": ["技术"],
    "status": "publish"
  }'
```

#### 6. RESTful 接口 - 删除文章

```bash
curl -X POST "https://example.com/api/deleteArticle" \
  -H "Content-Type: application/json" \
  -H "X-App-Id: cli_a1b2c3d4e5f6g7h8" \
  -H "X-App-Secret: 0a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p" \
  -d '{"cid": 123}'
```

### Python 示例

```python
import json
import urllib.request
import urllib.error

BASE = "https://example.com"
APP_ID = "cli_a1b2c3d4e5f6g7h8"
APP_SECRET = "0a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p"


def call(method: str, path: str, payload: dict | None = None) -> dict:
    """统一调用：method ∈ {GET, POST};path 为接口路径（不含域名）"""
    url = f"{BASE}{path}"
    headers = {
        "X-App-Id": APP_ID,
        "X-App-Secret": APP_SECRET,
        "Accept": "application/json",
    }
    data = None
    if payload is not None:
        if method == "GET":
            # 拼到查询串
            from urllib.parse import urlencode
            url = f"{url}?{urlencode(payload)}"
        else:
            headers["Content-Type"] = "application/json"
            data = json.dumps(payload).encode("utf-8")

    req = urllib.request.Request(url, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=10) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", errors="ignore")
        raise RuntimeError(f"HTTP {e.code}: {body}") from e


# 1. 测试连通性
print(call("POST", "/index.php/action/api-publish", {"do": "test"}))

# 2. RESTful 文章列表
print(call("GET", "/api/posts", {"page": 1, "pageSize": 5, "showDigest": "excerpt"}))

# 3. RESTful 发布文章
result = call("POST", "/api/postArticle", {
    "title": "通过 Python 发布",
    "text": "# Hi\n**Markdown** 内容",
    "tags": "API,Python",
    "category": ["技术"],
    "status": "publish",
})
print("新文章 cid =", result["cid"])
```

### PHP 示例

```php
<?php
/**
 * Typecho ApiPublish 调用客户端示例
 */

$base     = 'https://example.com';
$appId    = 'cli_a1b2c3d4e5f6g7h8';
$appSecret = '0a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p';

/**
 * 发起一次 HTTP 请求
 *
 * @param string $method GET / POST
 * @param string $path   例如 /api/postArticle
 * @param array  $payload  表单或 JSON 体；GET 时作为 query string
 * @param bool   $json    true: 发送 JSON body；false: 发送表单
 * @return array          解码后的响应体
 */
function api_call(string $method, string $path, array $payload = [], bool $json = false): array
{
    global $base, $appId, $appSecret;

    $url = $base . $path;
    $headers = [
        'X-App-Id: ' . $appId,
        'X-App-Secret: ' . $appSecret,
        'Accept: application/json',
    ];

    $body = null;
    if ($method === 'GET' && !empty($payload)) {
        $url .= '?' . http_build_query($payload);
    } elseif (!empty($payload)) {
        if ($json) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        } else {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $body = http_build_query($payload);
        }
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_POSTFIELDS     => $body,
    ]);
    $response = curl_exec($ch);
    $errno    = curl_errno($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        throw new RuntimeException('curl error: ' . curl_error($ch ?? curl_init()));
    }
    $data = json_decode((string)$response, true);
    if (!is_array($data)) {
        throw new RuntimeException("HTTP $code: $response");
    }
    if ($code >= 400 || (isset($data['ok']) && $data['ok'] === false)) {
        throw new RuntimeException("HTTP $code: " . ($data['message'] ?? $response));
    }
    return $data;
}

// 1. 传统接口：测试连通性
$info = api_call('POST', '/index.php/action/api-publish', ['do' => 'test']);
print_r($info);

// 2. RESTful：发布文章（JSON）
$post = api_call('POST', '/api/postArticle', [
    'title'    => '通过 PHP 发布',
    'text'     => "# 标题\n这是 **Markdown** 内容",
    'tags'     => 'API,PHP',
    'category' => ['技术'],
    'status'   => 'publish',
], true);
echo "新文章 cid = {$post['cid']}\n";

// 3. RESTful：编辑文章
$edit = api_call('POST', '/api/editArticle', [
    'cid'   => $post['cid'],
    'title' => '已更新标题',
], true);
print_r($edit);
```

### Node.js 示例

```javascript
const https = require('https');

const BASE = 'https://example.com';
const APP_ID = 'cli_a1b2c3d4e5f6g7h8';
const APP_SECRET = '0a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p';

/**
 * @param {string} method
 * @param {string} path
 * @param {object|null} payload
 * @returns {Promise<object>}
 */
function call(method, path, payload = null) {
  return new Promise((resolve, reject) => {
    let url = BASE + path;
    const headers = {
      'X-App-Id': APP_ID,
      'X-App-Secret': APP_SECRET,
      Accept: 'application/json',
    };
    let body = null;
    if (method === 'GET' && payload) {
      url += '?' + new URLSearchParams(payload).toString();
    } else if (payload) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(payload);
    }

    const u = new URL(url);
    const req = https.request(
      {
        hostname: u.hostname,
        port: u.port || 443,
        path: u.pathname + u.search,
        method,
        headers,
      },
      (res) => {
        let data = '';
        res.on('data', (chunk) => (data += chunk));
        res.on('end', () => {
          try {
            const json = JSON.parse(data);
            if (res.statusCode >= 400 || json.ok === false) {
              reject(new Error(`HTTP ${res.statusCode}: ${json.message || data}`));
            } else {
              resolve(json);
            }
          } catch (e) {
            reject(new Error(`HTTP ${res.statusCode}: ${data}`));
          }
        });
      }
    );
    req.on('error', reject);
    if (body) req.write(body);
    req.end();
  });
}

(async () => {
  // 1. 测试连通性
  console.log(await call('POST', '/index.php/action/api-publish', { do: 'test' }));

  // 2. RESTful 文章列表
  console.log(await call('GET', '/api/posts', { page: 1, pageSize: 5 }));

  // 3. RESTful 发布文章
  const created = await call('POST', '/api/postArticle', {
    title: 'Node 发布的文章',
    text: '# 标题\n正文',
    tags: 'API,Node',
    category: ['技术'],
    status: 'publish',
  });
  console.log('cid =', created.cid);
})();
```

---

## 常见问题

**Q1：传统接口和 RESTful 接口可以混用同一对 AppID/AppSecret 吗？**
> 可以。同一凭据在两套接口中均生效，频率计数共用。

**Q2：为什么 RESTful 接口没返回 `text` 字段？**
> 默认仅返回 `showContent=false`。如需正文，请传 `showContent=true`。

**Q3：为什么写接口 401？**
> 请检查：
> 1. 是否启用插件 **RESTful 接口总开关**
> 2. 对应接口是否在 **RESTful 接口独立开关** 中被关闭
> 3. AppID/AppSecret 是否被禁用
> 4. 是否触发了 **每分钟调用频率上限**

**Q4：如何调试？**
> 调用 `GET /api/getRoutes` 即可获得全部路由（含 HTTP 方法、URI、PHPDoc 摘要）。

---

> 本文档对应插件版本：ApiPublish v1.x（兼容 Typecho 1.3.0+ / PHP 7.2+）