# Métricas

## SLA

O SLA considera chamados fechados, com `solvedate` e `time_to_resolve` preenchidos.

Regra base:

```text
SLA = chamados dentro do prazo ÷ chamados com SLA × 100
```

## ISU

O ISU considera respostas de satisfação (`glpi_ticketsatisfactions`).

Regra base:

```text
ISU % = média de satisfação ÷ 5 × 100
```

## Quarter

O dashboard divide o ano em quatro quarters:

| Quarter | Meses |
|---|---|
| Q1 | Janeiro, Fevereiro, Março |
| Q2 | Abril, Maio, Junho |
| Q3 | Julho, Agosto, Setembro |
| Q4 | Outubro, Novembro, Dezembro |

Cada quarter vale 25% do ano.

## Contribuição anual por quarter

Cada mês do quarter vale:

```text
25 ÷ 3 = 8,3333 pontos percentuais do ano
```

Pontuação mensal:

```text
pontuação do mês = (% do mês ÷ 100) × 8,3333
```

Pontuação do quarter:

```text
Q = soma das pontuações dos 3 meses
```

Exemplo:

```text
Janeiro = 73,0%  → 6,08%
Fevereiro = 57,0% → 4,75%
Março = 99,6% → 8,30%
Q1 = 19,13% de contribuição anual
```

## Meses futuros

Meses futuros são tratados como 0 na contribuição anual, permitindo acompanhar o progresso parcial do ano.
