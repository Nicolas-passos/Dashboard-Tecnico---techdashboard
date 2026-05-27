# Troubleshooting

## Tela branca

Verifique sintaxe PHP:

```bash
php -l /var/www/html/glpi/plugins/techdashboard/front/index.php
```

Verifique logs:

```bash
tail -n 120 /var/www/html/glpi/files/_log/php-errors.log
tail -n 120 /var/log/apache2/error.log
```

## Menu não atualiza

Limpe cache:

```bash
php /var/www/html/glpi/bin/console cache:clear
rm -rf /var/www/html/glpi/files/_cache/*
systemctl restart apache2
```

## Logo não aparece

- Confirme se **Exibir logo** está marcado.
- Verifique permissões da pasta `uploads/`.
- Confirme se o arquivo existe em `plugins/techdashboard/uploads/`.

## Botão Ocultar usuários não abre

Verifique erros no console do navegador e garanta que o arquivo `index.php` não possui erros JavaScript após customizações.
