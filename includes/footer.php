  </main><!-- /.page-content -->

  <?php
    $footerSettings = function_exists('getAppSettings') ? getAppSettings() : [];
    $footerCompanyName = trim((string)($footerSettings['company_name'] ?? ''));
    $footerErpName = function_exists('getErpDisplayName') ? getErpDisplayName($footerCompanyName) : APP_NAME;
  ?>
  <footer class="app-footer" role="contentinfo">
    <div class="app-footer-left">Version : <?= e(APP_VERSION) ?></div>
    <div class="app-footer-right">&copy; <?= date('Y') ?> <?= e($footerErpName) ?> &bull; ERP Master System v<?= e(APP_VERSION) ?> | @ Developed by Mriganka Bhusan Debnath</div>
  </footer>

</div><!-- /.main-wrapper -->
</div><!-- /.app-shell -->
<script src="<?= BASE_URL ?>/assets/js/push-notification.js?v=<?= @filemtime(__DIR__ . '/../assets/js/push-notification.js') ?: time() ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/app.js?v=<?= @filemtime(__DIR__ . '/../assets/js/app.js') ?: time() ?>"></script>
</body>
</html>
