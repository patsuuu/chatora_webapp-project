<?php
session_start();
if (!empty($_SESSION['username'])) {
    if (!empty($_SESSION['is_admin'])) {
        header('Location: admin.php');
    } else {
        header('Location: index.php');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login | Chatora</title>
  <link rel="icon" href="logo.png" type="image/png" />
  <link rel="stylesheet" href="style.css" />
</head>
<body class="login-page">
  <div class="login-screen">
    <div class="login-card">
      <div class="login-header">
        <h1>
          <span class="brand">
            <a class="brand-link" href="index.php">
              <img class="brand-logo" src="logo.png" alt="Chatora logo">
            </a>
            <span class="brand-name">Chatora</span>
          </span>
        </h1>
        <p>Mag-login o mag-register para makapasok sa chat.</p>
      </div>

      <div class="account-card login-panel">
        <form id="auth-form">
          <label for="account-username">Username</label>
          <input id="account-username" type="text" placeholder="Ilagay ang username" maxlength="20" autocomplete="username" />
          <label for="account-password">Password</label>
          <input id="account-password" type="password" placeholder="Password" maxlength="50" autocomplete="current-password" />
          <div class="account-actions">
            <button id="login-button" type="submit">Login</button>
          </div>
        </form>
        <p class="account-note">Wala ka pang account? <a href="register.php">Mag-register dito</a>.</p>
      </div>
    </div>
  </div>

  <script src="login.js"></script>
</body>
</html>
