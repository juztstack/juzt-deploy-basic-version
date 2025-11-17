# Juzt Deploy

> WordPress plugin for seamless GitHub integration and automated deployment workflows

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![WordPress](https://img.shields.io/badge/WordPress-5.8+-blue.svg)](https://wordpress.org/)
[![Version](https://img.shields.io/badge/version-1.9.0-green.svg)](https://github.com/tu-usuario/juzt-deploy/releases)

## 📋 Overview

Juzt Deploy brings Shopify-like deployment workflows to WordPress. It connects your WordPress site with GitHub repositories, enabling version control, automated deployments, and collaborative theme development without relying on the WordPress database for theme structure.

Part of the **Juzt Stack** ecosystem - a comprehensive WordPress development toolkit that revolutionizes theme and template management.

## ✨ Features

- 🔗 **GitHub Integration** - Connect WordPress with GitHub repositories via OAuth
- 🚀 **Automated Deployments** - Push to deploy workflow
- 📦 **Version Control** - Full Git history for your themes
- 🔄 **Sync Templates** - Keep your local and remote templates synchronized
- 🔐 **Secure Authentication** - GitHub App integration with secure token management
- 📝 **Deployment Logs** - Track all deployments and changes

## 🚀 Installation

### Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- GitHub account
- Juzt Pulse theme (compatible theme)

### Steps

1. Download the latest release
2. Upload to `/wp-content/plugins/juzt-deploy`
3. Activate the plugin
4. Navigate to **Juzt Deploy** → **Settings**
5. Connect your GitHub account
6. Configure your repository settings

## 📖 Quick Start

### 1. Connect GitHub
```
WordPress Admin → Juzt Deploy → Settings → Connect GitHub
```

### 2. Link Repository
```
Select your repository → Configure branch → Save
```

### 3. Deploy
```
Make changes → Commit → Push → Automatic deployment
```

## 🏗️ Architecture

Juzt Deploy works with a middleware service that handles GitHub webhook communications:
```
GitHub → Webhook → Middleware → WordPress → Theme Update
```

### Components

- **WordPress Plugin** - Manages GitHub connection and deployments
- **Middleware Service** - Handles webhook processing (repository included)
- **GitHub App** - Secure OAuth integration

## 🛣️ Roadmap

This is the community edition with core functionality. **Juzt Deploy Pro** (coming soon) will include:

- ⭐ Multi-repository management
- ⭐ Advanced deployment rules
- ⭐ Rollback functionality
- ⭐ Team collaboration features
- ⭐ Deployment approval workflows
- ⭐ Priority support

## 🔧 Configuration

### GitHub App Setup

1. Create a GitHub App in your GitHub account
2. Set the callback URL: `https://your-site.com/wp-admin/admin-ajax.php?action=juzt_deploy_callback`
3. Enable Repository webhooks
4. Copy Client ID and Client Secret to plugin settings

### Middleware Setup

The middleware repository is available at [here](https://github.com/juztstack/starter-basic-template-middleware-github-oauth). Follow its installation guide for deployment.

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## 📝 Recent Changes

### Latest Release (v1.9.0)
- Git command compatibility improvements for limited hosting environments
- GitHub API integration for clone, pull, and push operations

[View full changelog](CHANGELOG.md)

## 📝 License

Copyright © 2024 Jesus Uzcategui

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🔗 Related Projects

Part of the **Juzt Stack** ecosystem:
> The ecosystem will be published in the coming weeks. Subscribe to our newsletter at [https://www.juztstack.dev](https://www.juztstack.dev)
<!--
- [Juzt Studio](link) - Visual template builder for WordPress
- [Juzt Pulse](link) - JSON-powered theme engine
- [Juzt CLI](link) - Command-line development tools
-->

## 👤 Author

**Jesus Uzcategui**

- Website: [jesusuzcategui.com]
- GitHub: [@jesusuzcategui](https://github.com/jesusuzcategui)

## 💬 Support

- 📧 Email: info@juztstack.dev
- 💼 Issues: [GitHub Issues](https://github.com/juztstack/juzt-deploy-basic-version/issues)
- 📖 Documentation: [Full Documentation](https://juztstack.dev/docs)

---

Made with ❤️ in Colombia by a Venezuelan.

