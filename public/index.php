<?php
session_start();
$year = date("Y");
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP</title>
  <link rel="stylesheet" href="../assets/css/styles.css">
</head>

<body style="min-height:100vh; display:flex; flex-direction:column;">

  <!-- BARRA ARRIBA: CEVIMEP centro + Iniciar sesión derecha -->
  <header class="navbar">
    <div class="inner">
      <div></div>
      <div class="brand"><span class="dot"></span> CEVIMEP</div>
      <div class="nav-right"><a href="login.php">Iniciar Sesion</a></div>
    </div>
  </header>

  <main class="container" style="flex:1;">

    <section class="hero">
      <h1>Bienvenido a CEVIMEP</h1>
      <p>Centro de vacunación y medicina preventiva. y acceder al sistema interno.</p>
    </section>

    <section class="grid-top">

      <!-- SUCURSALES -->
      <div class="card">
        <h3>Nuestras Sucursales</h3>
        <p class="muted">Contamos con múltiples puntos para estar más cerca de ti.</p>

        <div class="suc-grid" style="margin-top:12px;">
          <div class="suc-item">
            <b><span class="pin"></span> Puerto Plata</b>
            <div class="addr">Plaza L &amp; M, primer nivel<br>Avenida 27 de Febrero</div>
            <div class="wa">WhatsApp: <a href="#">(809) 870-7155</a></div>
          </div>

          <div class="suc-item">
            <b><span class="pin"></span> Moca</b>
            <div class="addr">Av. Los Agricultores, Plaza Green Gallery<br>Mód. 214, 2do Nivel</div>
            <div class="wa">WhatsApp: <a href="#">(849) 207-7155</a></div>
          </div>

          <div class="suc-item">
            <b><span class="pin"></span> Santiago</b>
            <div class="addr">Plaza La Ramblas, Mód. 122<br>Calle Juan Pablo Duarte</div>
            <div class="wa">WhatsApp: <a href="#">(849) 354-7155</a></div>
          </div>

          <div class="suc-item">
            <b><span class="pin"></span> Salcedo</b>
            <div class="addr">Calle Francisca R Mollins #25<br>Plaza SL, Mód. 104</div>
            <div class="wa">WhatsApp: <a href="#">(829) 741-4320</a></div>
          </div>

          <div class="suc-item">
            <b><span class="pin"></span> La Vega</b>
            <div class="addr">Calle Balilo Gómez, Plaza Michelle<br>Módulo 118</div>
            <div class="wa">WhatsApp: <a href="#">(829) 959-1766</a></div>
          </div>

          <div class="suc-item">
            <b><span class="pin"></span> Mao</b>
            <div class="addr">Calle Independencia #34B<br>Plaza Fratilide, Mód. 04</div>
            <div class="wa">WhatsApp: <a href="#">(829) 980-7155</a></div>
          </div>
        </div>
      </div>

      <!-- HORARIOS -->
      <div class="card">
        <h3>Horarios</h3>

        <div class="block">
          <div class="block-title">Lunes a Viernes</div>
          <div class="block-text">8:00 AM - 6:00 PM</div>
        </div>

        <div class="block" style="margin-top:12px;">
          <div class="block-title">Sábados</div>
          <div class="block-text">8:30 AM - 1:00 PM</div>
        </div>
      </div>

      <!-- SERVICIOS -->
      <div class="card">
        <h3>Servicios</h3>

        <div class="block">
          <div class="block-title">Vacunación</div>
          <div class="block-text">Influenza, Neumococo, VPH y más.</div>
        </div>

        <div class="block" style="margin-top:12px;">
          <div class="block-title">Prevención</div>
          <div class="block-text">Orientación preventiva y educación en salud.</div>
        </div>

        <div class="block" style="margin-top:12px;">
          <div class="block-title">Campañas comunitarias</div>
          <div class="block-text">Jornadas y actividades de vacunación.</div>
        </div>
      </div>

    </section>

  </main>

  <!-- BARRA ABAJO: texto exacto -->
  <footer class="footer">
    <div class="inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
  </footer>

</body>
</html>
