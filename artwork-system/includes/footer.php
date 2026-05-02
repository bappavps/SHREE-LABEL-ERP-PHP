        </div> <!-- .content-wrapper -->
        <footer class="app-footer" role="contentinfo">
            <div class="app-footer-left">Version : <?php echo sanitize(defined('APP_VERSION') ? APP_VERSION : '1.0.0'); ?></div>
            <div class="app-footer-right">&copy; <?php echo date('Y'); ?> <?php echo sanitize(APP_NAME); ?> &bull; ERP Master System v<?php echo sanitize(defined('APP_VERSION') ? APP_VERSION : '1.0.0'); ?> | @ Developed by Mriganka Bhusan Debnath</div>
        </footer>
    </main>
    <script src="../assets/js/main.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/main.js'); ?>"></script>
</body>
</html>
