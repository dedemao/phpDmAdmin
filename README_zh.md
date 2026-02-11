# phpDmAdmin

一个轻量级、单文件的 PHP Web 管理工具，用于达梦（DM8）数据库。提供快速浏览、临时 SQL 执行与行内编辑能力，无需额外依赖。

## 功能

- 带默认连接配置的登录页
- Schema / Table 浏览与筛选
- SQL Console（服务端分页、列筛选、可编辑）
- Table View（支持 NULL/空字符串区分、长文本预览、行内编辑）
- 默认 Schema 自动补全（可选）
- 基本的字符集转换支持（mbstring/iconv）

## 环境要求

- PHP 7.4+（推荐）
- PDO 扩展与 `pdo_dm` 驱动
- 可访问的 DM8 实例

## 快速开始

1. 将 `index.php`（以及可选的 `config.php`）上传到网站目录。
2. 使用浏览器打开页面。
3. 填写连接信息并点击 **Connect**。

## 配置

可创建 `config.php` 覆盖默认值：

```php
<?php
$DM_CONFIG = [
    'enabled' => true,
    'default_host' => '127.0.0.1',
    'default_port' => 5236,
    'default_db' => 'DM8',
    'default_user' => 'SYSDBA',
    'default_password' => '',
    'default_schema' => '',
    'default_charset' => 'UTF-8',
    'data_charset' => '',
    'error_charset' => '',
    'output_charset' => 'UTF-8',
    'auto_schema' => true,
    'max_rows' => 200,
];
```

说明：
- `data_charset` 用于写入数据库时的输入转换。
- `error_charset` 用于规范化错误信息编码。
- `max_rows` 控制分页大小。

## 安全提示

- 建议仅在可信网络环境使用。
- 建议对目录加认证或 IP 限制。
- 不使用时可在 `config.php` 中设置 `'enabled' => false` 关闭。

## 许可协议

MIT License。详见 `LICENSE`。
