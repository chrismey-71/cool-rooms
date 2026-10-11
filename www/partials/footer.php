<?php
require_once __DIR__ . '/../lib/version.php';
require_once __DIR__ . '/../lib/branding.php';
$__v = APP_VERSION;
$__branding = branding_settings();
?>
</main>
<footer class="footer">
  <div class="wrap small"><?= h((string)$__branding['brand_title']) ?> Version <?= htmlspecialchars($__v, ENT_QUOTES, 'UTF-8') ?> · &#169; 2026 by Christian Meysing · mit Live-View (SSE) und Kiosk-Modus</div>
</footer>
</body>
</html>
