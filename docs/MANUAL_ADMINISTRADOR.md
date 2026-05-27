# Manual do Administrador

## Instalar

Consulte `INSTALL.md`.

## Configurar branding

Acesse:

```text
Configurar → Plugins → TechDashboard → Configurar
```

Configure título, nome da empresa, logo e cores.

## Adicionar logo

1. Marque **Exibir logo**.
2. Selecione a imagem.
3. Salve.

## Remover logo

1. Marque **Remover logo**.
2. Salve.

## Permissões

O plugin usa permissões padrão de configuração/visualização do GLPI. Ajustes finos podem ser implementados por perfil em versões futuras.

## Backup antes de alterar

```bash
cd /var/www/html/glpi/plugins
cp -r techdashboard techdashboard_backup_$(date +%Y%m%d_%H%M)
```
