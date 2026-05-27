# Instalação

## 1. Copiar o plugin

```bash
cd /var/www/html/glpi/plugins
git clone https://github.com/SUA-ORGANIZACAO/techdashboard.git
```

Ou envie o ZIP e descompacte:

```bash
cd /var/www/html/glpi/plugins
unzip techdashboard.zip
```

A pasta final deve ser:

```text
/var/www/html/glpi/plugins/techdashboard
```

## 2. Permissões

```bash
sudo chown -R www-data:www-data /var/www/html/glpi/plugins/techdashboard
sudo find /var/www/html/glpi/plugins/techdashboard -type d -exec chmod 755 {} \;
sudo find /var/www/html/glpi/plugins/techdashboard -type f -exec chmod 644 {} \;
sudo chmod -R 755 /var/www/html/glpi/plugins/techdashboard/uploads
```

## 3. Limpar cache

```bash
sudo -u www-data php /var/www/html/glpi/bin/console cache:clear
sudo systemctl restart apache2
```

## 4. Ativar no GLPI

No GLPI:

```text
Configurar → Plugins → TechDashboard → Instalar → Ativar
```

## 5. Configurar branding

Acesse:

```text
Configurar → Plugins → TechDashboard → Configurar
```

Preencha nome da empresa, título, logo e cores.

## Atualização

Antes de atualizar:

```bash
cd /var/www/html/glpi/plugins
cp -r techdashboard techdashboard_backup_$(date +%Y%m%d_%H%M)
```

Depois substitua os arquivos e limpe o cache.
