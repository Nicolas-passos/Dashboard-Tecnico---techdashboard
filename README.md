# TechDashboard para GLPI

Plugin white-label para GLPI com dashboard operacional de chamados, métricas de SLA/ISU, exportação de relatórios, ocultação de usuários de serviço e personalização visual por empresa.

> Projeto preparado para GLPI 10.x, PHP 8.1+ e MariaDB/MySQL.

## Principais recursos

- Dashboard de chamados com KPIs, gráficos e evolução temporal.
- Métricas trimestrais por quarter.
- Cálculo de contribuição anual por quarter.
- Filtro para ocultar usuários de serviço/automações das métricas.
- Botão para selecionar automaticamente contas cujo título seja “Conta de Serviço” ou “Contas de Serviço”.
- Exportação em PDF e CSV compatível com Excel.
- Rodapé de auditoria nas exportações com usuário e data/hora.
- Layout reorganizável para a visão do usuário.
- Cores de prioridade alinháveis com matriz de prioridade do GLPI.
- Branding white-label: nome da empresa, título do dashboard, upload de logo e cores.

## Estrutura do projeto

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

## Instalação rápida

```bash
cd /var/www/html/glpi/plugins
git clone https://github.com/SUA-ORGANIZACAO/techdashboard.git
sudo chown -R www-data:www-data techdashboard
sudo -u www-data php /var/www/html/glpi/bin/console cache:clear
sudo systemctl restart apache2
```

Depois acesse o GLPI como administrador:

```text
Configurar → Plugins → TechDashboard → Instalar → Ativar
```

## Branding / logo da empresa

O plugin não possui logo fixa de nenhuma empresa. Para adicionar a sua:

```text
Configurar → Plugins → TechDashboard → Configurar
```

Configure:

- Nome da empresa.
- Título do dashboard.
- Exibir logo.
- Upload da logo.
- Cor primária.
- Cor secundária.

Formatos aceitos: PNG, JPG, SVG e WEBP.

Mais detalhes em [`docs/BRANDING.md`](docs/BRANDING.md).

## Documentação

- [`docs/INSTALL.md`](docs/INSTALL.md) — instalação e atualização.
- [`docs/CONFIGURACAO.md`](docs/CONFIGURACAO.md) — configuração do plugin.
- [`docs/BRANDING.md`](docs/BRANDING.md) — logo, nome da empresa e cores.
- [`docs/METRICAS.md`](docs/METRICAS.md) — regras de SLA, ISU e quarters.
- [`docs/EXPORTACAO.md`](docs/EXPORTACAO.md) — PDF, CSV e auditoria.
- [`docs/CUSTOMIZACAO.md`](docs/CUSTOMIZACAO.md) — como adicionar gráficos e KPIs.
- [`docs/ARQUITETURA.md`](docs/ARQUITETURA.md) — visão técnica.
- [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md) — problemas comuns.
- [`docs/MANUAL_USUARIO.md`](docs/MANUAL_USUARIO.md) — manual operacional.
- [`docs/MANUAL_ADMINISTRADOR.md`](docs/MANUAL_ADMINISTRADOR.md) — manual administrativo.

## Requisitos

- GLPI 10.0 ou superior.
- PHP 8.1 ou superior.
- MariaDB/MySQL.
- Navegador moderno com JavaScript habilitado.

## Segurança

- O plugin usa autenticação e sessão do GLPI.
- O upload de logo aceita apenas tipos MIME de imagem permitidos.
- Relatórios exportados incluem trilha de auditoria com usuário e data/hora.

Consulte [`SECURITY.md`](SECURITY.md).

## Licença

Distribuído sob licença GPL-2.0-or-later. Consulte [`LICENSE`](LICENSE).
