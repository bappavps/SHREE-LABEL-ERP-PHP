    </div><!-- /.page-content -->
</div><!-- /.main-content -->

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script>
    // Auto-dismiss alerts after 4 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert-dismissible').forEach(function(el) {
            var alert = bootstrap.Alert.getOrCreateInstance(el);
            alert.close();
        });
    }, 4000);
</script>
<?php if (isset($extraJs)) echo $extraJs; ?>
</body>
</html>
