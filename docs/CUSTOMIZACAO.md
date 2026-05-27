# Customização

## Adicionar um novo KPI

1. Crie a query PHP no `front/index.php` ou extraia para uma classe futura.
2. Adicione o valor ao payload ou variável PHP.
3. Crie o card HTML na seção de KPIs.
4. Teste com `php -l`.

## Adicionar um novo gráfico

1. Crie os dados no PHP.
2. Inclua no JSON enviado ao JavaScript.
3. Crie o `<canvas>` no HTML.
4. Inicialize o Chart.js.

## Cores

A cor primária vem do branding configurado. Para cores fixas de gráficos, ajuste a paleta JS `PALETTE`.
