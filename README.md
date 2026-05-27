# TechDashboard for GLPI

![GLPI](https://img.shields.io/badge/GLPI-10.x-blue)
![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4)
![License](https://img.shields.io/badge/License-GPL%202.0-green)
![Status](https://img.shields.io/badge/Status-Active-success)

White-label plugin for GLPI featuring operational dashboards, SLA/ISU metrics, reporting exports, service account filtering and company visual customization.

Compatible with **GLPI 10.x**, **PHP 8.1+** and **MariaDB/MySQL**.

---

## Features

- Operational ticket dashboard with KPIs, charts and historical evolution.
- Quarterly metrics (Quarter / Q1–Q4 model).
- Annual contribution calculation by quarter.
- Service / automation user exclusion from metrics.
- Auto-selection button for accounts titled **"Service Account"** or **"Service Accounts"**.
- PDF and CSV export (Excel compatible).
- Export audit footer with user and timestamp.
- User-reorganizable dashboard layout.
- GLPI-aligned priority color mapping.
- White-label branding:
  - Company name
  - Dashboard title
  - Logo upload
  - Custom colors

---

## Screenshots

Screenshots will be added soon.

Planned previews:

- Dashboard
- Governance
- Branding
- Report Exports
---

## Project Structure

```text
techdashboard/
├── assets/
│   └── default-logo.svg
├── css/
│   └── dashboard.css
├── docs/
├── front/
│   ├── config.form.php
│   └── index.php
├── inc/
│   ├── branding.class.php
│   └── dashboard.class.php
├── js/
│   └── dashboard.js
├── src/
│   └── Dashboard.php
├── uploads/
├── hook.php
├── setup.php
├── README.md
├── LICENSE
└── CHANGELOG.md
```

---

## Quick Installation

```bash
cd /var/www/html/glpi/plugins

git clone https://github.com/Nicolas-passos/Dashboard-Tecnico---techdashboard.git

sudo chown -R www-data:www-data techdashboard

sudo -u www-data php /var/www/html/glpi/bin/console cache:clear

sudo systemctl restart apache2
```

Then access GLPI as administrator:

```text
Setup → Plugins → TechDashboard → Install → Enable
```

---

## Branding / Company Logo

The plugin does **not include fixed company branding**.

To configure your own branding:

```text
Setup → Plugins → TechDashboard → Configure
```

Available options:

- Company Name
- Dashboard Title
- Enable Logo
- Logo Upload
- Primary Color
- Secondary Color

Supported formats:

```text
PNG
JPG
SVG
WEBP
```

See:

```text
docs/BRANDING.md
```

---

## Documentation

| File | Description |
|------|------|
| docs/INSTALL.md | Installation & updates |
| docs/CONFIGURACAO.md | Plugin configuration |
| docs/BRANDING.md | Branding configuration |
| docs/METRICAS.md | SLA, ISU & quarter rules |
| docs/EXPORTACAO.md | PDF, CSV & audit exports |
| docs/CUSTOMIZACAO.md | Adding KPIs & charts |
| docs/ARQUITETURA.md | Technical architecture |
| docs/TROUBLESHOOTING.md | Common issues |
| docs/MANUAL_USUARIO.md | User manual |
| docs/MANUAL_ADMINISTRADOR.md | Admin manual |

---

## Requirements

- GLPI 10+
- PHP 8.1+
- MariaDB / MySQL
- Modern browser with JavaScript enabled

---

## Security

The plugin uses native GLPI authentication and session handling.

Logo uploads accept only allowed image MIME types.

Exported reports include audit trail information.

See:

```text
SECURITY.md
```

---

## License

Distributed under **GPL-2.0-or-later**.

See:

```text
LICENSE
```
