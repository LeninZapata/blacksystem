<?php
// ============================================================
// CONFIGURACIÓN
// ============================================================
$API_BASE_URL    = 'https://mibackend.com';           // URL del backend (sin slash final)
$PRODUCT_SLUG    = 'superacion-infantil';
$FALLBACK_URL    = 'https://pay.hotmart.com/X102845502H?checkoutMode=10';
// ============================================================

// Leer número de la persona desde ?discount=
$rawNumber = $_GET['discount'] ?? $_GET['number'] ?? $_GET['n'] ?? '';
$number    = preg_replace('/[^0-9]/', '', $rawNumber);

// Si no hay número, redirigir directo al checkout sin SCK
if (empty($number)) {
  header('Location: ' . $FALLBACK_URL);
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Descubre qué necesita tu hijo</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }

    .quiz-card {
      background: #fff;
      border-radius: 24px;
      box-shadow: 0 20px 60px rgba(0,0,0,.25);
      max-width: 480px;
      width: 100%;
      overflow: hidden;
    }

    .progress-bar {
      height: 5px;
      background: #e9ecef;
    }
    .progress-fill {
      height: 100%;
      background: linear-gradient(90deg, #667eea, #764ba2);
      transition: width .4s ease;
      width: 0%;
    }

    .quiz-body {
      padding: 36px 32px 40px;
    }

    .step-label {
      font-size: 12px;
      font-weight: 600;
      letter-spacing: .8px;
      text-transform: uppercase;
      color: #9b59b6;
      margin-bottom: 12px;
    }

    .question {
      font-size: 22px;
      font-weight: 700;
      color: #2d3748;
      line-height: 1.4;
      margin-bottom: 28px;
    }

    .options {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .option-btn {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 16px 20px;
      border: 2px solid #e2e8f0;
      border-radius: 14px;
      background: #fff;
      cursor: pointer;
      font-size: 15px;
      font-weight: 500;
      color: #4a5568;
      text-align: left;
      transition: all .2s ease;
      -webkit-tap-highlight-color: transparent;
    }

    .option-btn:hover {
      border-color: #764ba2;
      background: #faf5ff;
      color: #553c9a;
    }

    .option-btn .emoji {
      font-size: 22px;
      flex-shrink: 0;
      width: 32px;
      text-align: center;
    }

    /* Step hidden/visible */
    .step { display: none; }
    .step.active {
      display: block;
      animation: fadeIn .35s ease;
    }

    /* Loading screen */
    #loading {
      display: none;
      text-align: center;
      padding: 20px 0 8px;
    }
    #loading.active { display: block; animation: fadeIn .35s ease; }

    .spinner {
      width: 52px;
      height: 52px;
      border: 5px solid #e9ecef;
      border-top-color: #764ba2;
      border-radius: 50%;
      animation: spin .8s linear infinite;
      margin: 0 auto 20px;
    }

    #loading p {
      font-size: 16px;
      font-weight: 600;
      color: #4a5568;
      margin-bottom: 6px;
    }

    #loading small {
      font-size: 13px;
      color: #a0aec0;
    }

    @keyframes spin { to { transform: rotate(360deg); } }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

    @media (max-width: 480px) {
      .quiz-body { padding: 28px 20px 32px; }
      .question  { font-size: 19px; }
    }
  </style>
</head>
<body>

<div class="quiz-card">
  <div class="progress-bar">
    <div class="progress-fill" id="progressFill"></div>
  </div>

  <div class="quiz-body">

    <!-- PASO 1 -->
    <div class="step active" id="step1">
      <div class="step-label">Paso 1 de 2</div>
      <div class="question">¿Cuántos años tiene tu hijo/a?</div>
      <div class="options">
        <button class="option-btn" onclick="nextStep('0-2 años')">
          <span class="emoji">👶</span> 0 – 2 años
        </button>
        <button class="option-btn" onclick="nextStep('3-4 años')">
          <span class="emoji">🧒</span> 3 – 4 años
        </button>
        <button class="option-btn" onclick="nextStep('5-6 años')">
          <span class="emoji">🧒‍♀️</span> 5 – 6 años
        </button>
        <button class="option-btn" onclick="nextStep('7+ años')">
          <span class="emoji">👦</span> 7 años o más
        </button>
      </div>
    </div>

    <!-- PASO 2 -->
    <div class="step" id="step2">
      <div class="step-label">Paso 2 de 2</div>
      <div class="question">¿Cuál es tu mayor preocupación?</div>
      <div class="options">
        <button class="option-btn" onclick="finish('No habla claro')">
          <span class="emoji">🗣️</span> No habla o no habla claro
        </button>
        <button class="option-btn" onclick="finish('Comportamiento')">
          <span class="emoji">😤</span> Berrinches y mal comportamiento
        </button>
        <button class="option-btn" onclick="finish('Concentración')">
          <span class="emoji">🎯</span> Le cuesta concentrarse
        </button>
        <button class="option-btn" onclick="finish('Aprendizaje')">
          <span class="emoji">📚</span> Dificultad para aprender
        </button>
      </div>
    </div>

    <!-- LOADING -->
    <div id="loading">
      <div class="spinner"></div>
      <p>Preparando tu acceso...</p>
      <small>Te redirigimos en un momento</small>
    </div>

  </div>
</div>

<script>
  var number       = '<?= htmlspecialchars($number, ENT_QUOTES) ?>';
  var apiBaseUrl   = '<?= rtrim($API_BASE_URL, '/') ?>';
  var productSlug  = '<?= htmlspecialchars($PRODUCT_SLUG, ENT_QUOTES) ?>';
  var fallbackUrl  = '<?= htmlspecialchars($FALLBACK_URL, ENT_QUOTES) ?>';

  var answerStep1 = null;

  function setProgress(pct) {
    document.getElementById('progressFill').style.width = pct + '%';
  }

  // Paso 1 completado → mostrar paso 2
  function nextStep(answer) {
    answerStep1 = answer;
    document.getElementById('step1').classList.remove('active');
    document.getElementById('step2').classList.add('active');
    setProgress(50);
  }

  // Paso 2 completado → llamar API y redirigir
  function finish(answer) {
    document.getElementById('step2').classList.remove('active');
    document.getElementById('loading').classList.add('active');
    setProgress(90);

    fetchCheckoutContext()
      .then(function(ctx) {
        setProgress(100);
        var finalUrl = buildUrl(ctx);
        setTimeout(function() { window.location.href = finalUrl; }, 400);
      })
      .catch(function() {
        // Si falla la API, redirigir al fallback sin SCK
        window.location.href = fallbackUrl;
      });
  }

  function fetchCheckoutContext() {
    var url = apiBaseUrl + '/api/checkout/context'
            + '?number=' + encodeURIComponent(number)
            + '&slug='   + encodeURIComponent(productSlug);

    return fetch(url, { method: 'GET', headers: { 'Accept': 'application/json' } })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.success && data.found) return data;
        throw new Error('not_found');
      });
  }

  function buildUrl(ctx) {
    // Construir SCK: {number}|b-{bot_id}|p-{product_id}|t-{type}
    var sck = number + '|b-' + ctx.bot_id + '|p-' + ctx.product_id + '|t-' + ctx.type;

    var base = ctx.checkout_url;
    var sep  = base.indexOf('?') !== -1 ? '&' : '?';
    return base + sep + 'sck=' + encodeURIComponent(sck);
  }

  // Iniciar barra de progreso en 25%
  setProgress(25);
</script>

</body>
</html>
