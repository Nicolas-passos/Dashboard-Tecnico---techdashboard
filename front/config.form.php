<?php
include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);
Plugin::load('techdashboard');

require_once(__DIR__ . '/../inc/branding.class.php');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::checkCSRF($_POST);
    try {
        PluginTechdashboardBranding::save($_POST, $_FILES['logo'] ?? null);
        Session::addMessageAfterRedirect(__('Configuração salva com sucesso.'));
        Html::redirect(Plugin::getWebDir('techdashboard', true) . '/front/config.form.php');
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$branding = PluginTechdashboardBranding::load();

Html::header('TechDashboard - Configuração', '', 'config', 'plugins');
?>

<div class="center">
  <form method="post" enctype="multipart/form-data" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">
    <div class="spaced" id="tabsbody">
      <table class="tab_cadre_fixe">
        <tr><th colspan="2">Branding / White-label</th></tr>

        <?php foreach ($errors as $error): ?>
          <tr><td colspan="2"><div class="red"><?= htmlspecialchars($error) ?></div></td></tr>
        <?php endforeach; ?>

        <tr class="tab_bg_1">
          <td>Nome da empresa</td>
          <td><input type="text" name="company_name" value="<?= htmlspecialchars($branding['company_name']) ?>" size="50"></td>
        </tr>

        <tr class="tab_bg_1">
          <td>Título do dashboard</td>
          <td><input type="text" name="dashboard_title" value="<?= htmlspecialchars($branding['dashboard_title']) ?>" size="50"></td>
        </tr>

        <tr class="tab_bg_1">
          <td>Exibir logo</td>
          <td><input type="checkbox" name="show_logo" value="1" <?= !empty($branding['show_logo']) ? 'checked' : '' ?>></td>
        </tr>

        <tr class="tab_bg_1">
          <td>Logo atual</td>
          <td>
            <?php if (!empty($branding['logo_url'])): ?>
              <img src="<?= htmlspecialchars($branding['logo_url']) ?>" style="max-height:60px;max-width:220px;background:#fff;border:1px solid #ddd;padding:6px;border-radius:6px;">
              <label style="margin-left:12px"><input type="checkbox" name="remove_logo" value="1"> Remover logo</label>
            <?php else: ?>
              Nenhuma logo configurada.
            <?php endif; ?>
          </td>
        </tr>

        <tr class="tab_bg_1">
          <td>Enviar nova logo</td>
          <td>
            <input type="file" name="logo" accept="image/png,image/jpeg,image/svg+xml,image/webp">
            <br><small>Formatos aceitos: PNG, JPG, SVG e WEBP.</small>
          </td>
        </tr>

        <tr class="tab_bg_1">
          <td>Cor primária</td>
          <td><input type="color" name="primary_color" value="<?= htmlspecialchars($branding['primary_color']) ?>"></td>
        </tr>

        <tr class="tab_bg_1">
          <td>Cor secundária</td>
          <td><input type="color" name="secondary_color" value="<?= htmlspecialchars($branding['secondary_color']) ?>"></td>
        </tr>

        <tr class="tab_bg_2">
          <td colspan="2" class="center">
            <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>
            <button type="submit" class="btn btn-primary">Salvar configuração</button>
          </td>
        </tr>
      </table>
    </div>
  </form>
</div>

<?php Html::footer(); ?>
