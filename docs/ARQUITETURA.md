# Arquitetura

```text
GLPI Database
   ↓
Queries PHP
   ↓
Tratamento de métricas
   ↓
HTML + JSON
   ↓
Chart.js
   ↓
Dashboard no navegador
```

## Componentes

| Arquivo | Função |
|---|---|
| `setup.php` | Metadados, hooks e menu do GLPI. |
| `hook.php` | Instalação/desinstalação de tabelas. |
| `src/Dashboard.php` | Item de menu no GLPI. |
| `inc/branding.class.php` | Carregamento e gravação de branding. |
| `front/config.form.php` | Tela de configuração white-label. |
| `front/index.php` | Dashboard principal. |

## Tabelas criadas

- `glpi_plugin_techdashboard_configs`
- `glpi_plugin_techdashboard_cache`
- `glpi_plugin_techdashboard_branding`
