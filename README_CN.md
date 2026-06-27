# ApiPublish - Typecho 自动发布插件

> 让任何支持 HTTP 请求的工具/脚本/AI 都能通过 RESTful 或传统接口向 Typecho 博客发布文章。

[![Typecho](https://img.shields.io/badge/Typecho-1.3.0+-blue.svg)](https://typecho.org)
[![PHP](https://img.shields.io/badge/PHP-7.2+-green.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-orange.svg)](#许可证)

---

## ✨ 功能特性

- 🔐 **多凭据管理**：可为不同应用/设备分别生成独立的 AppID / AppSecret / API Token
- 🌐 **双接口风格**：同时支持传统 `?do=xxx` 接口和现代 RESTful `/api/xxx` 接口
- ⚡ **高频可用**：单凭据每分钟调用频率可配置（默认 60 次/分钟，`0` 表示不限制）
- 🛡️ **安全可控**：支持按接口独立开关、远程删除开关、CORS 跨域白名单
- 🧹 **XSS 过滤**：内置钩子过滤，可关闭
- 📝 **Markdown 支持**：自动识别 Markdown 文本并按需解析
- 🎯 **完整 CRUD**：文章、页面、分类、标签、用户、评论、设置 全部支持读取；写操作支持发布、编辑、删除
- 🔑 **Token 增强**：内置"生成 Token"按钮、掩码显示、眼睛切换，降低误操作风险

## 📦 安装方法

1. 下载本插件压缩包或克隆本仓库
2. 将 `ApiPublish` 目录上传到 Typecho 的 `usr/plugins/` 目录下
3. 登录 Typecho 后台，进入「**控制台 → 插件**」找到 **ApiPublish**
4. 点击「**启用**」按钮
5. 启用后进入「**控制台 → 设置 → 插件配置 → ApiPublish**」配置全局参数
6. 进入「**控制台 → 插件 → ApiPublish → 凭据管理**」生成第一个 AppID/AppSecret/API Token

> 详细接口清单与调用示例请参考 [API.md](./API.md)

## ⚙️ 基本配置

进入 **「控制台 → 设置 → 插件配置 → ApiPublish」** 页面可配置以下选项：

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| **RESTful 接口总开关** | 开启 | 是否启用 RESTful API（`/api/xxx` 风格） |
| **RESTful 接口独立开关** | 全部开启 | 按接口细粒度开启/关闭 |
| **RESTful 写接口是否要求登录** | 否 | 写接口是否必须携带后台登录 Cookie |
| **RESTful 路径前缀** | `/api/` | RESTful 路由前缀 |
| **CORS 跨域白名单** | （空） | 允许跨域调用的 Origin，一行一个，`*` 表示任意 |
| **每分钟调用频率上限** | 60 | 单凭据每分钟最多调用次数，`0` 表示不限制 |
| **远程删除（RESTful）** | 关闭 | 是否允许通过 RESTful 删除文章/页面 |
| **默认文章状态** | publish | 通过 API 发布时未指定状态时的默认值 |
| **默认分类** | （空） | 发布时未指定分类时的默认分类 slug，多个用英文逗号 |
| **是否启用 Markdown** | 开启 | 是否对 `text` 字段按 Markdown 解析 |
| **是否启用 XSS 过滤** | 开启 | 是否对发布内容执行 XSS 过滤 |

## 🔑 凭据管理

进入 **「控制台 → 插件 → ApiPublish → 凭据管理」**：

- 点击「**添加凭据**」生成新的 AppID/AppSecret
- 凭据创建后可以重置 AppSecret（输入 32 位以上随机字符串）
- 可以启用/禁用某个凭据
- 支持多选删除（不可恢复，请谨慎操作）

> 💡 **API Token 增强**：在插件配置页（设置 → ApiToken）可直接点击「**生成 Token**」随机生成 64 位 Token；已有 Token 会以 `abcd********wxyz` 形式掩码显示，点击眼睛图标可临时查看完整值。

## 🌐 接口入口

- **本插件 API 入口**：`https://你的域名/index.php/action/api-publish`
- **RESTful 入口**：`https://你的域名/api/`（可在插件配置中修改前缀）
- **原生 XML-RPC**：`https://你的域名/index.php/action/xmlrpc`（Typecho 自带）

> 📖 完整接口文档：[API.md](./API.md)

## 🛠️ 快速开始

### 1. 生成凭据

在凭据管理页面添加一个新凭据，记录下：
- `AppID`：形如 `cli_a1b2c3d4e5f6g7h8`
- `AppSecret`：形如 `0a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p`

### 2. 验证连通性

```bash
# 传统接口：测试连通性
curl -X POST "https://你的域名/index.php/action/api-publish?do=test" \
  -H "X-App-Id: YOUR_APP_ID" \
  -H "X-App-Secret: YOUR_APP_SECRET"
```

### 3. 发布第一篇文章

```bash
# RESTful 接口
curl -X POST "https://你的域名/api/postArticle" \
  -H "Content-Type: application/json" \
  -H "X-App-Id: YOUR_APP_ID" \
  -H "X-App-Secret: YOUR_APP_SECRET" \
  -d '{
    "title": "我的第一篇 API 文章",
    "text": "# 标题\n这是 **Markdown** 正文",
    "tags": "API,Typecho",
    "category": ["技术"],
    "status": "publish"
  }'
```

## 🔒 安全建议

1. **妥善保管 AppSecret**：一旦泄露请立即在凭据管理页面重置
2. **按需启用接口**：关闭不需要的写接口（删除、添加分类等）
3. **CORS 白名单**：填写确切的调用方域名，避免 `*`
4. **限流保护**：保留每分钟调用频率上限（默认 60），避免被刷
5. **HTTPS 强制**：生产环境请配置 HTTPS，避免 AppSecret 在网络层泄露
6. **写接口二次验证**：可在插件配置中开启"RESTful 写接口要求登录"，让写操作必须携带后台 Cookie

## 🤝 兼容性

- Typecho 1.3.0+（推荐）
- PHP 7.2+（建议 PHP 7.4+）
- 数据库：MySQL / SQLite / PostgreSQL（Typecho 支持的均可）

## 📄 许可证

本插件基于 [MIT 许可证](https://opensource.org/licenses/MIT) 开源。

## 🙏 致谢

- RESTful 接口实现参考了 [moefront/typecho-plugin-Restful](https://github.com/moefront/typecho-plugin-Restful)
- Typecho 框架：[typecho.org](https://typecho.org)
