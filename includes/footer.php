    </div>
  </main>
</div>

<footer class="text-center text-muted small py-3 border-top bg-white">
  &copy; 2026 Developed by Omunju Tech Services
</footer>

<!-- AI Bot Widget -->
<?php if (isset($_SESSION['user_id'])): ?>
<link rel="stylesheet" href="<?= e(app_url('/assets/css/ai-bot.css')) ?>">
<script>
window.ASMS_PAGE_CONTEXT = {
  role: <?= json_encode(current_role()) ?>,
  userId: <?= json_encode(current_user_id()) ?>,
  userName: <?= json_encode($_SESSION['full_name'] ?? 'User') ?>,
  pageTitle: <?= json_encode($pageTitle ?? '') ?>,
  apiBaseUrl: <?= json_encode(app_url('/api/ai_bot.php')) ?>
};
</script>
<script src="<?= e(app_url('/assets/js/ai-bot.js')) ?>"></script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(app_url('/assets/js/app.js')) ?>"></script>
</body>
</html>
