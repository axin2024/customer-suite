# 依赖、素材与声明

本项目源码和原创演示内容按 [Apache-2.0](../LICENSE) 提供。可再分发源码来源见 [NOTICE](../NOTICE)。

| 项目 | 用途与分发方式 |
| --- | --- |
| PHP、PDO、pdo_mysql、mbstring | `composer.json` 声明直接运行依赖。Compose 从 `php:8.4-apache` 构建应用镜像，PHP 与扩展许可随上游发行物提供。 |
| Apache HTTP Server | 容器的 HTTP 服务，由 PHP 基础镜像提供。 |
| MySQL Community Server 8.4 | 独立数据库容器，使用上游 `mysql:8.4` 镜像。数据库服务程序由使用者获取。 |
| OpenSSL CLI | 集成测试的本机 TLS 回放场景需要。测试临时生成证书和密钥。 |
| `actions/checkout` | GitHub Actions 工作流中拉取当前仓库，由平台加载。 |
| 公开演示 | 合成客户与联系人、文本签收、商机、日历、报表。随项目源码生成。 |
| 前端 | 仓库内的 HTML、CSS、JavaScript，字体使用设备系统字体。 |
