    </div>
  </main>
</div>

<footer class="text-center text-muted small py-3 border-top bg-white">
  &copy; <?= date('Y') ?> <?= e($schoolName ?? APP_NAME) ?> &mdash; Advanced School Management System
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(app_url('/assets/js/app.js')) ?>"></script>
</body>
</html>
