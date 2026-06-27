# ApiPublish - Typecho Auto-Publish Plugin

> Allow any tool/script/AI that supports HTTP requests to publish articles to Typecho blog via RESTful or traditional interfaces.

[![Typecho](https://img.shields.io/badge/Typecho-1.3.0+-blue.svg)](https://typecho.org)
[![PHP](https://img.shields.io/badge/PHP-7.2+-green.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-orange.svg)](#license)

---

## ✨ Features

- 🔐 **Multiple Credential Management**: Generate independent AppID / AppSecret / API Token for different applications/devices
- 🌐 **Dual Interface Styles**: Support both traditional `?do=xxx` and modern RESTful `/api/xxx` interfaces
- ⚡ **High Frequency Usable**: Configurable call frequency per credential per minute (default 60 times/minute, `0` means no limit)
- 🛡️ **Secure & Controllable**: Support independent interface switches, remote deletion switch, CORS cross-origin whitelist
- 🧹 **XSS Filtering**: Built-in hook filtering, can be disabled
- 📝 **Markdown Support**: Auto-detect Markdown text and parse as needed
- 🎯 **Complete CRUD**: Full read support for articles, pages, categories, tags, users, comments, settings; write operations support publish, edit, delete
- 🔑 **Token Enhancement**: Built-in "Generate Token" button, masked display, eye toggle to reduce misoperation risk

## 📦 Installation

1. Download the plugin archive or clone this repository
2. Upload the `ApiPublish` directory to Typecho's `usr/plugins/` directory
3. Log in to Typecho backend, go to **Console → Plugins** and find **ApiPublish**
4. Click the **Enable** button
5. After enabling, go to **Console → Settings → Plugin Configuration → ApiPublish** to configure global parameters
6. Go to **Console → Plugins → ApiPublish → Credential Management** to generate the first AppID/AppSecret/API Token

> For detailed interface list and call examples, please refer to [API.md](./API.md) (Chinese version)

## ⚙️ Basic Configuration

Go to **"Console → Settings → Plugin Configuration → ApiPublish"** page to configure the following options:

| Configuration Item | Default Value | Description |
| --- | --- | --- |
| **RESTful Interface Master Switch** | Enabled | Whether to enable RESTful API (`/api/xxx` style) |
| **RESTful Interface Individual Switches** | All enabled | Fine-grained enable/disable by interface |
| **RESTful Write Interface Requires Login** | No | Whether write interface must carry backend login Cookie |
| **RESTful Path Prefix** | `/api/` | RESTful route prefix |
| **CORS Cross-origin Whitelist** | (empty) | Allowed cross-origin call Origins, one per line, `*` for any |
| **Call Frequency Limit Per Minute** | 60 | Maximum calls per credential per minute, `0` means no limit |
| **Remote Deletion (RESTful)** | Disabled | Whether to allow deleting articles/pages via RESTful |
| **Default Article Status** | publish | Default value when publishing via API without specifying status |
| **Default Category** | (empty) | Default category slug when publishing without specifying category, multiple separated by commas |
| **Enable Markdown** | Enabled | Whether to parse `text` field as Markdown |
| **Enable XSS Filtering** | Enabled | Whether to perform XSS filtering on published content |

## 🔑 Credential Management

Go to **"Console → Plugins → ApiPublish → Credential Management"**:

- Click **"Add Credential"** to generate new AppID/AppSecret
- After credential creation, you can reset AppSecret (enter a random string of 32+ characters)
- Can enable/disable a specific credential
- Supports multi-select deletion (irreversible, please operate with caution)

> 💡 **API Token Enhancement**: In the plugin configuration page (Settings → ApiToken), you can directly click **"Generate Token"** to randomly generate a 64-bit Token; existing Tokens will be displayed in masked form like `abcd********wxyz`, click the eye icon to temporarily view the full value.

## 🌐 Interface Entry Points

- **Plugin API Entry**: `https://yourdomain.com/index.php/action/api-publish`
- **RESTful Entry**: `https://yourdomain.com/api/` (prefix can be modified in plugin configuration)
- **Native XML-RPC**: `https://yourdomain.com/index.php/action/xmlrpc` (Typecho built-in)

> 📖 Complete API documentation: [API.md](./API.md) (Chinese version)

## 🛠️ Quick Start

### 1. Generate Credentials

Add a new credential in the credential management page, record:
- `AppID`: Looks like `cli_a1b2c3d4e5f6g7h8`
- `AppSecret`: Looks like `0a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p`

### 2. Verify Connectivity

```bash
# Traditional interface: test connectivity
curl -X POST "https://yourdomain.com/index.php/action/api-publish?do=test" \
  -H "X-App-Id: YOUR_APP_ID" \
  -H "X-App-Secret: YOUR_APP_SECRET"
```

### 3. Publish Your First Article

```bash
# RESTful interface
curl -X POST "https://yourdomain.com/api/postArticle" \
  -H "Content-Type: application/json" \
  -H "X-App-Id: YOUR_APP_ID" \
  -H "X-App-Secret: YOUR_APP_SECRET" \
  -d '{
    "title": "My First API Article",
    "text": "# Title\nThis is **Markdown** content",
    "tags": "API,Typecho",
    "category": ["Technology"],
    "status": "publish"
  }'
```

## 🔒 Security Recommendations

1. **Keep AppSecret Safe**: If compromised, immediately reset it in the credential management page
2. **Enable Interfaces As Needed**: Disable unnecessary write interfaces (delete, add categories, etc.)
3. **CORS Whitelist**: Fill in exact caller domains, avoid `*`
4. **Rate Limiting Protection**: Keep the call frequency limit per minute (default 60) to prevent abuse
5. **HTTPS Enforcement**: Configure HTTPS in production environment to prevent AppSecret leakage at network layer
6. **Write Interface Secondary Verification**: Enable "RESTful write interface requires login" in plugin configuration to make write operations require backend Cookie

## 🤝 Compatibility

- Typecho 1.3.0+ (recommended)
- PHP 7.2+ (PHP 7.4+ suggested)
- Database: MySQL / SQLite / PostgreSQL (all supported by Typecho)

## 📄 License

This plugin is open-sourced under the [MIT License](https://opensource.org/licenses/MIT).

## 🙏 Acknowledgments

- RESTful interface implementation references [moefront/typecho-plugin-Restful](https://github.com/moefront/typecho-plugin-Restful)
- Typecho framework: [typecho.org](https://typecho.org)

---

[中文版本](./README_CN.md)