# Contribuindo

1. Abra uma issue descrevendo o problema ou sugestão.
2. Crie uma branch a partir da `main`.
3. Rode lint PHP antes de abrir pull request:

```bash
find . -name "*.php" -print0 | xargs -0 -n1 php -l
```

4. Descreva claramente o impacto da mudança.

## Padrões

- Evite hardcodes de empresa.
- Documente novas métricas.
- Não versionar logos enviadas em `uploads/`.
- Não incluir dados reais de produção em issues ou testes.
