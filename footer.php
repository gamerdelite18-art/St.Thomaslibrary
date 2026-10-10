    </main>
</div><!-- /.app-layout -->

<footer class="app-footer text-center text-muted small py-3">
    &copy; <?= date('Y') ?> Library Management System &middot; Built with core PHP, MySQL &amp; Bootstrap 5
</footer>

<!-- Bootstrap JS bundle (vendored locally) -->
<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<!-- Confirm before destructive submits -->
<script>
    document.addEventListener('submit', function (e) {
        var msg = e.target.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) {
            e.preventDefault();
        }
    });
</script>
</body>
</html>
