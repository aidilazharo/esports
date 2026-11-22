<?php require_once __DIR__ . '/website_version.php'; ?>
</main>
<footer class="border-top bg-white py-4 mt-auto">
    <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 text-secondary small">
        <span>&copy; <?= date('Y') ?> Metadata Berhad 1573083-A <span class="ms-1">v<?= htmlspecialchars(websiteVersion(), ENT_QUOTES, 'UTF-8') ?></span></span>
        <span>Contact Support: 09-5818322 Email: cs@metadata.technology</span>
    </div>
    <?= mobileNavigationBar($mobilePublicSecondary, 'Secondary mobile navigation', 'bottom') ?>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/mobile-icon-links.js?v=2"></script>
</body>
</html>
