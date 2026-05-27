# Branding White-label

O TechDashboard foi preparado para não depender de nenhuma marca específica.

## Acessar configuração

```text
Configurar → Plugins → TechDashboard → Configurar
```

## Campos disponíveis

| Campo | Descrição |
|---|---|
| Nome da empresa | Nome exibido abaixo do título do dashboard. |
| Título do dashboard | Nome principal exibido no topo. |
| Exibir logo | Liga/desliga a logo. |
| Upload da logo | Envia a imagem da empresa. |
| Cor primária | Cor principal dos botões, linhas e destaques. |
| Cor secundária | Cor principal dos textos. |

## Como adicionar sua logo

1. Abra a configuração do plugin.
2. Marque **Exibir logo**.
3. Clique em **Enviar nova logo**.
4. Selecione um arquivo PNG, JPG, SVG ou WEBP.
5. Clique em **Salvar configuração**.
6. Atualize o dashboard.

## Como remover a logo

1. Abra a configuração do plugin.
2. Marque **Remover logo**.
3. Opcionalmente desmarque **Exibir logo**.
4. Salve.

## Onde a logo fica salva

Os uploads são salvos em:

```text
plugins/techdashboard/uploads/
```

Essa pasta está no `.gitignore`, pois logos corporativas não devem ser versionadas.

## Logo padrão

O plugin inclui uma logo neutra em:

```text
assets/default-logo.svg
```

Ela pode ser usada em documentação ou como referência visual, mas o dashboard só exibe logo quando uma imagem é configurada.
